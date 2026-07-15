<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlySalary;
use App\Models\PayrollPeriodSetting;
use App\Models\Presence;
use App\Models\Store;
use App\Services\SalaryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalaryAdminController extends Controller
{
    /**
     * Generate/regenerate monthly payroll untuk semua karyawan yang hadir.
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2024|max:2030',
        ]);

        $month = (int) $data['month'];
        $year = (int) $data['year'];

        $tenantId = Store::first()?->tenant_id
            ?? DB::connection('mysql_auth')->table('tenants')->first()?->id
            ?? '00000000-0000-0000-0000-000000000000';

        $setting = PayrollPeriodSetting::where('tenant_id', $tenantId)->first();
        $startDay = $setting ? $setting->start_day : 26;
        $range = \App\Services\SalaryService::getPeriodRange($year, $month, $startDay);
        $periodStart = $range['start'];
        $periodEnd = $range['end'];

        $userIds = Presence::whereBetween('check_in', [$periodStart, $periodEnd])
            ->where('status', '2')
            ->pluck('created_by_id')
            ->unique()
            ->filter();

        if ($userIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'generated_count' => 0,
                'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()],
                'message' => 'Tidak ada data presensi valid untuk periode ini.',
            ]);
        }

        $salaryService = app(SalaryService::class);
        $count = 0;

        foreach ($userIds as $userId) {
            try {
                $salaryService->generateMonthlySalary($userId, $year, $month);
                $count++;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("SalaryController generate failed for user {$userId}: ".$e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'generated_count' => $count,
            'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()],
            'message' => "Berhasil men-generate {$count} slip gaji.",
        ]);
    }

    /**
     * Approve single slip (DRAFT -> APPROVED).
     */
    public function approve(Request $request, $id)
    {
        $salary = MonthlySalary::findOrFail($id);
        if ($salary->status !== MonthlySalary::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Slip tidak berstatus Draft.',
            ], 422);
        }
        $salary->update(['status' => MonthlySalary::STATUS_APPROVED]);
        return response()->json(['success' => true, 'message' => 'Slip berhasil di-approve.']);
    }

    /**
     * Bulk approve (semua DRAFT terpilih -> APPROVED).
     */
    public function bulkApprove(Request $request)
    {
        $data = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);

        $count = MonthlySalary::whereIn('id', $data['ids'])
            ->where('status', MonthlySalary::STATUS_DRAFT)
            ->update(['status' => MonthlySalary::STATUS_APPROVED]);

        return response()->json([
            'success' => true,
            'approved_count' => $count,
            'message' => "{$count} slip berhasil di-approve.",
        ]);
    }

    /**
     * Bayar gaji -> set PAID + paid_amount + payment_date.
     */
    public function pay(Request $request, $id)
    {
        $data = $request->validate([
            'paid_amount'   => 'required|numeric|min:0',
            'payment_date'  => 'required|date',
        ]);

        $salary = MonthlySalary::findOrFail($id);
        if ($salary->status !== MonthlySalary::STATUS_APPROVED) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya slip berstatus Diproses (Approved) yang bisa dibayar.',
            ], 422);
        }
        $salary->update([
            'paid_amount'   => $data['paid_amount'],
            'payment_date'  => $data['payment_date'],
            'status'        => MonthlySalary::STATUS_PAID,
        ]);

        $selisih = (float) $salary->total_salary - (float) $data['paid_amount'];

        return response()->json([
            'success' => true,
            'message' => 'Gaji berhasil dibayarkan.',
            'data' => [
                'id' => $salary->id,
                'paid_amount' => (float) $salary->paid_amount,
                'total_salary' => (float) $salary->total_salary,
                'selisih' => $selisih,
                'status' => 'paid',
            ],
        ]);
    }

    /**
     * Info pembayaran: rekening bank penerima + breakdown + defaults.
     */
    public function paymentInfo(Request $request, $id)
    {
        $salary = MonthlySalary::findOrFail($id);
        $detail = $salary->user?->applicantDetail;

        $deductions = $salary->deductions ?? [];
        $latePenalties = (float) ($deductions['late_penalties'] ?? 0);
        $manualPenalties = (float) ($deductions['manual_penalties'] ?? 0);
        $loanInstallments = (float) ($deductions['loan_installments'] ?? 0);
        $totalDeductions = $latePenalties + $manualPenalties + $loanInstallments;

        $baseSalary = (float) $salary->base_salary;
        $monthlyPart = $baseSalary - $totalDeductions;
        $dailySalaryTotal = (float) ($salary->daily_salary_total ?? 0);

        return response()->json([
            'success' => true,
            'data' => [
                'bank' => [
                    'bank_name' => $detail?->bank_name,
                    'bank_account_number' => $detail?->bank_account_number,
                    'bank_account_name' => $detail?->bank_account_name,
                    'admin_fee' => $detail ? (float) $detail->admin_fee : 0,
                ],
                'breakdown' => [
                    'base_salary' => $baseSalary,
                    'late_penalties' => $latePenalties,
                    'manual_penalties' => $manualPenalties,
                    'loan_installments' => $loanInstallments,
                    'total_deductions' => $totalDeductions,
                    'monthly_part' => $monthlyPart,
                    'daily_salary_total' => $dailySalaryTotal,
                    'total_salary' => (float) $salary->total_salary,
                ],
                'defaults' => [
                    'suggested_paid_amount' => (float) $salary->total_salary - $dailySalaryTotal,
                    'today' => now()->toDateString(),
                ],
            ],
        ]);
    }
}
