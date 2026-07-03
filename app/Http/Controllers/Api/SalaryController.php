<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlySalary;
use App\Models\DailySalary;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SalaryController extends Controller
{
    /**
     * Get monthly salary history list for the authenticated user
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $salaries = MonthlySalary::where('user_id', $userId)
            ->where('status', '>=', MonthlySalary::STATUS_APPROVED) // Only show approved/paid
            ->orderBy('period_start', 'desc')
            ->get();

        $formatted = $salaries->map(function (MonthlySalary $salary) {
            $periodLabel = Carbon::parse($salary->period_start)->translatedFormat('F Y');
            
            // Map status
            $statusText = 'pending';
            if ($salary->status === MonthlySalary::STATUS_PAID) {
                $statusText = 'paid';
            } elseif ($salary->status === MonthlySalary::STATUS_APPROVED) {
                $statusText = 'processing';
            }

            return [
                'id' => $salary->id,
                'period' => $salary->period_start->toDateString(),
                'period_label' => $periodLabel,
                'amount' => (int) $salary->total_salary,
                'daily_salary_total' => (int) ($salary->daily_salary_total ?? 0),
                'paid_amount' => $salary->paid_amount !== null ? (int) $salary->paid_amount : null,
                'status' => $statusText,
                'paymentDate' => $salary->payment_date ? $salary->payment_date->toDateString() : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    /**
     * Get monthly salary details with daily breakdown
     */
    public function show(Request $request, $id)
    {
        $userId = $request->user()->id;

        $salary = MonthlySalary::where('user_id', $userId)
            ->with(['presences.shiftStore', 'presences.store'])
            ->findOrFail($id);

        $periodLabel = Carbon::parse($salary->period_start)->translatedFormat('F Y');

        // Map status
        $statusText = 'pending';
        if ($salary->status === MonthlySalary::STATUS_PAID) {
            $statusText = 'paid';
        } elseif ($salary->status === MonthlySalary::STATUS_APPROVED) {
            $statusText = 'processing';
        }

        // Daily work breakdown
        $dailyWork = $salary->presences->map(function ($presence) {
            $dailySalary = DailySalary::where('presence_id', $presence->id)->first();
            $dailyWage = $dailySalary ? (int)$dailySalary->amount : (int)($presence->store?->daily_salary_amount ?? 50000);

            // Determine daily salary payment status
            $paymentStatus = 'belum_dibayar';
            if ($dailySalary) {
                if ($dailySalary->status == 2) {
                    $paymentStatus = 'sudah_dibayar';
                } elseif ($dailySalary->status == 3) {
                    $paymentStatus = 'siap_dibayar';
                } elseif ($dailySalary->status == 4) {
                    $paymentStatus = 'perbaiki';
                }
            }

            // Calculate hours worked
            $checkIn = Carbon::parse($presence->check_in);
            $checkOut = $presence->check_out ? Carbon::parse($presence->check_out) : null;
            
            $workHours = 0.0;
            if ($checkOut) {
                $workHours = round($checkOut->diffInMinutes($checkIn) / 60, 2);
            }

            // Determine status
            $status = 'normal';
            if (is_null($presence->check_out)) {
                $status = 'no_checkout';
            }

            return [
                'date' => $checkIn->toDateString(),
                'workHours' => $workHours,
                'overtime' => 0.0, // default placeholder
                'dailyWage' => $dailyWage,
                'status' => $status,
                'payment_status' => $paymentStatus
            ];
        })->sortBy('date')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $salary->id,
                'period' => $salary->period_start->toDateString(),
                'period_label' => $periodLabel,
                'amount' => (int) $salary->total_salary,
                'base_salary' => (int) $salary->base_salary,
                'daily_salary_total' => (int) ($salary->daily_salary_total ?? 0),
                'paid_amount' => $salary->paid_amount !== null ? (int) $salary->paid_amount : null,
                'allowances' => $salary->allowances ?? ['transport' => 0, 'meal' => 0],
                'deductions' => array_merge([
                    'late_penalties' => 0,
                    'manual_penalties' => 0,
                    'loan_installments' => 0,
                ], $salary->deductions ?? []),
                'status' => $statusText,
                'paymentDate' => $salary->payment_date ? $salary->payment_date->toDateString() : null,
                'daily_work' => $dailyWork
            ]
        ]);
    }
}
