<?php

namespace App\Services;

use App\Models\MonthlySalary;
use App\Models\DailySalary;
use App\Models\Presence;
use App\Models\PayrollPeriodSetting;
use App\Models\User;
use App\Models\Store;
use App\Models\SalaryRate;
use App\Models\SalaryRateDetail;
use App\Models\SalaryPenalty;
use App\Models\LoanInstallment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Catatan: Logic ini di-PORT dari apps/admin/app/Services/SalaryService.php
 * (Opsi A — duplikasi diterima agar mobile bisa akses via api service).
 * Bila formula gaji berubah, UPDATE JUGA file admin tersebut.
 */
class SalaryService
{
    /**
     * Hitung rentang periode penggajian dari PayrollPeriodSetting.start_day.
     * Returns ['start' => Carbon, 'end' => Carbon].
     */
    public static function getPeriodRange(int $year, int $month, int $startDay): array
    {
        if ($startDay == 1) {
            return [
                'start' => \Carbon\Carbon::create($year, $month, 1)->startOfMonth(),
                'end' => \Carbon\Carbon::create($year, $month, 1)->endOfMonth(),
            ];
        }
        $prevMonth = \Carbon\Carbon::create($year, $month, 1)->subMonth();
        $startDayClamped = min($startDay, $prevMonth->daysInMonth);
        $periodStart = $prevMonth->day($startDayClamped)->startOfDay();

        $currentMonth = \Carbon\Carbon::create($year, $month, 1);
        $endDayClamped = min($startDay - 1, $currentMonth->daysInMonth);
        $periodEnd = $currentMonth->day($endDayClamped)->endOfDay();

        return ['start' => $periodStart, 'end' => $periodEnd];
    }

    /**
     * Generate atau update gaji bulanan
     */
    public function generateMonthlySalary($userId, $year, $month)
    {
        $user = User::findOrFail($userId);

        // Dapatkan tenant_id dari user, store, atau fallback
        $tenantId = $user->tenant_id
            ?? Store::first()?->tenant_id
            ?? DB::connection('mysql_auth')->table('tenants')->first()?->id
            ?? '00000000-0000-0000-0000-000000000000';

        // Ambil pengaturan periode penggajian tenant
        $setting = PayrollPeriodSetting::where('tenant_id', $tenantId)->first();
        if (!$setting) {
            $setting = PayrollPeriodSetting::create([
                'tenant_id' => $tenantId,
                'start_day' => 26,
                'end_day' => 25,
                'transport_allowance_per_day' => 25000,
                'meal_allowance_per_day' => 20000,
                'late_penalty_per_hour' => 10000,
                'no_checkout_penalty' => 20000,
            ]);
        }

        $startDay = $setting->start_day;
        $range = self::getPeriodRange($year, $month, $startDay);
        $periodStart = $range['start'];
        $periodEnd = $range['end'];

        // Ambil semua presensi valid ('2') karyawan dalam rentang tanggal
        $presences = Presence::where('created_by_id', $userId)
            ->whereBetween('check_in', [$periodStart, $periodEnd])
            ->where('status', '2') // 2 = valid
            ->with(['shiftStore', 'store'])
            ->get();

        // Dapatkan data rate per jam berdasarkan masa kerja s.d. akhir periode gaji
        $ratePerHour = $this->getHourlyRateForUser($user, $periodEnd);

        $totalWorkDays = $presences->count();
        $totalEffectiveHours = 0;
        $totalGrossHours = 0;
        $totalPenaltyHours = 0;

        foreach ($presences as $presence) {
            $grossHours = $presence->shiftStore?->duration ?? 8.0;
            $penaltyHours = $presence->calculateTotalPenalty();

            // Capped agar denda tidak melebihi jam shift
            $actualPenaltyHours = min($grossHours, $penaltyHours);
            $effectiveHours = $grossHours - $actualPenaltyHours;

            $totalGrossHours += $grossHours;
            $totalPenaltyHours += $actualPenaltyHours;
            $totalEffectiveHours += $effectiveHours;
        }

        // Base salary gross: rate per jam x total jam kotor
        $totalBaseSalary = $ratePerHour * $totalGrossHours;

        // Denda keterlambatan secara nominal: rate per jam x total jam denda
        $totalPenaltyAmount = $ratePerHour * $totalPenaltyHours;

        // Transport & meals tidak digunakan dalam rekap bulanan
        $allowances = [];

        // Reset kaitan data pinalti, kasbon, dan gaji harian jika rekap gaji ini pernah dibuat sebelumnya
        $existingMonthlySalary = MonthlySalary::where('user_id', $userId)
            ->where('period_start', $periodStart->toDateString())
            ->where('period_end', $periodEnd->toDateString())
            ->first();
        $existingMonthlySalaryId = $existingMonthlySalary ? $existingMonthlySalary->id : null;

        if ($existingMonthlySalaryId) {
            SalaryPenalty::where('monthly_salary_id', $existingMonthlySalaryId)
                ->update(['monthly_salary_id' => null]);

            DailySalary::where('monthly_salary_id', $existingMonthlySalaryId)
                ->update(['monthly_salary_id' => null]);

            $associatedInstallments = LoanInstallment::where('monthly_salary_id', $existingMonthlySalaryId)->get();
            foreach ($associatedInstallments as $inst) {
                $inst->update([
                    'monthly_salary_id' => null,
                    'status' => 1 // pending
                ]);
                $inst->employeeLoan->update(['status' => 1]); // active
            }
        }

        // Ambil pinalti manual dalam periode gaji berjalan yang belum dipotong
        $manualPenalties = SalaryPenalty::where('user_id', $userId)
            ->whereNull('monthly_salary_id')
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();
        $manualPenaltyTotal = $manualPenalties->sum('amount');

        // Ambil cicilan kasbon yang jatuh tempo pada periode berjalan dan berstatus pending
        $loanInstallments = LoanInstallment::whereHas('employeeLoan', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->where('status', 1) // pending
            ->whereNull('monthly_salary_id')
            ->whereBetween('due_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();
        $loanInstallmentTotal = $loanInstallments->sum('amount');

        // Ambil gaji harian dalam periode gaji berjalan
        // Mencakup: (1) input langsung oleh karyawan, (2) input oleh admin/operator tapi terhubung ke presensi karyawan
        // Hanya yang berstatus sudah dibayar (2) atau siap dibayar (3) yang dijumlahkan
        // ke total gaji harian. Yang belum dibayar (1) / perbaiki (4) diabaikan sampai
        // statusnya diperbarui.
        $dailySalaries = DailySalary::whereNull('monthly_salary_id')
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereIn('status', [2, 3])
            ->where(function ($q) use ($userId) {
                $q->where('created_by_id', $userId)
                  ->orWhereHas('presence', fn ($pq) => $pq->where('created_by_id', $userId));
            })
            ->get();
        $dailySalaryTotal = $dailySalaries->sum('amount');

        $deductions = [
            'late_penalties' => $totalPenaltyAmount,
            'manual_penalties' => $manualPenaltyTotal,
            'loan_installments' => $loanInstallmentTotal,
        ];

        // Gaji Bersih = (Gaji Tenur - Total Potongan) + Gaji Harian
        $monthlyPart = $totalBaseSalary - $totalPenaltyAmount - $manualPenaltyTotal - $loanInstallmentTotal;
        $finalSalary = $monthlyPart + $dailySalaryTotal;
        $finalSalary = max(0, $finalSalary);

        // Simpan atau update rekapitulasi gaji bulanan
        $monthlySalary = MonthlySalary::updateOrCreate(
            [
                'user_id' => $userId,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ],
            [
                'tenant_id' => $tenantId,
                'total_work_days' => $totalWorkDays,
                'total_hours' => $totalEffectiveHours,
                'base_salary' => $totalBaseSalary,
                'daily_salary_total' => $dailySalaryTotal,
                'allowances' => $allowances,
                'deductions' => $deductions,
                'total_salary' => $finalSalary,
                'amount' => $finalSalary,
                'status' => MonthlySalary::STATUS_DRAFT
            ]
        );

        // Kaitkan denda manual ke slip gaji ini
        foreach ($manualPenalties as $penalty) {
            $penalty->update(['monthly_salary_id' => $monthlySalary->id]);
        }

        // Kaitkan gaji harian ke slip gaji ini
        foreach ($dailySalaries as $ds) {
            $ds->update(['monthly_salary_id' => $monthlySalary->id]);
        }

        // Kaitkan dan tandai lunas cicilan kasbon bulan ini
        foreach ($loanInstallments as $installment) {
            $installment->update([
                'monthly_salary_id' => $monthlySalary->id,
                'status' => 2 // paid
            ]);

            // Cek jika seluruh cicilan untuk kasbon ini sudah lunas
            $loan = $installment->employeeLoan;
            $unpaidCount = $loan->installments()->where('status', '!=', 2)->count();
            if ($unpaidCount === 0) {
                $loan->update(['status' => 2]); // paid
            }
        }

        // Sinkronisasi data presensi yang masuk dalam slip gaji ini ke pivot table
        $monthlySalary->presences()->sync($presences->pluck('id')->toArray());

        return $monthlySalary;
    }

    /**
     * Dapatkan tarif per jam untuk user berdasarkan masa kerja (applicantDetail->join_date)
     */
    public function getHourlyRateForUser(User $user, Carbon $date): float
    {
        // Hubungkan ke applicant_details di database recruitment
        $applicantDetail = $user->applicantDetail;
        $joinDate = $applicantDetail ? $applicantDetail->join_date : null;

        if ($joinDate) {
            // Hitung masa kerja (tahun) dari join_date s.d. tanggal target
            $yearsOfService = floor(Carbon::parse($joinDate)->diffInYears($date));

            // Ambil skema tarif tahun terkait
            $salaryRate = SalaryRate::whereYear('effective_date', $date->year)
                ->orderBy('effective_date', 'desc')
                ->first()
                ?? SalaryRate::orderBy('effective_date', 'desc')->first();

            if ($salaryRate) {
                $salaryRateDetail = SalaryRateDetail::where('salary_rate_id', $salaryRate->id)
                    ->where('years_of_service', '<=', $yearsOfService)
                    ->orderBy('years_of_service', 'desc')
                    ->first();

                if ($salaryRateDetail) {
                    return (float) $salaryRateDetail->rate_per_hour;
                }
            }
        }

        // Fallback jika tidak ada: default gaji harian store dibagi 8 jam kerja standar
        $defaultStoreRate = Store::first()?->daily_salary_amount ?? 50000;
        return (float) round($defaultStoreRate / 8, 2);
    }
}
