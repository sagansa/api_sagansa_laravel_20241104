<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlySalary;
use App\Models\DailySalary;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SalaryController extends Controller
{
    /**
     * Get monthly salary history list.
     * - Admin (role admin): sees all records; supports ?user_id=, ?period=YYYY-MM, ?status=, ?page= pagination.
     * - Non-admin: only their own records (legacy behavior).
     *
     * Non-breaking: when no `page` param is sent, returns {success, data:[...]} (no meta)
     * to preserve compatibility with existing callers.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');

        $query = MonthlySalary::with('user')
            ->when(
                !$request->has('page'),
                fn($q) => $q->where('status', '>=', MonthlySalary::STATUS_APPROVED),
                function ($q) use ($request) {
                    // admin paginated list: optional status filter
                    $map = ['draft' => 1, 'processing' => 2, 'paid' => 3];
                    $val = $map[$request->input('status')] ?? null;
                    if ($val !== null) {
                        $q->where('status', $val);
                    }
                },
            )
            ->orderBy('period_start', 'desc');

        // The admin "view all" path is opt-in via the `page` param. The legacy
        // (no-page) callers (home/HRD/loan dashboards) expect the user's OWN
        // records to render personal indicators, so scope them to own even for
        // admins to avoid showing other employees' data on personal dashboards.
        $wantsAdminList = $isAdmin && $request->has('page');

        if (!$wantsAdminList) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            // Admin list filtered to a specific employee
            $query->where('user_id', $request->input('user_id'));
        }

        // Optional filter: period YYYY-MM (applies to both, harmless for non-admin)
        if ($request->filled('period')) {
            $period = $request->input('period'); // e.g. "2026-07"
            $parts = explode('-', $period);
            if (count($parts) === 2) {
                $query->whereYear('period_start', (int) $parts[0])
                      ->whereMonth('period_start', (int) $parts[1]);
            }
        }

        $formatItem = function (MonthlySalary $salary) {
            $statusText = 'draft';
            if ($salary->status === MonthlySalary::STATUS_PAID) {
                $statusText = 'paid';
            } elseif ($salary->status === MonthlySalary::STATUS_APPROVED) {
                $statusText = 'processing';
            }

            $deductions = $salary->deductions ?? [];
            $hasLoan = isset($deductions['loan_installments']) && (int) $deductions['loan_installments'] > 0;

            return [
                'id' => $salary->id,
                'period' => $salary->period_start->toDateString(),
                'period_label' => Carbon::parse($salary->period_start)->translatedFormat('F Y'),
                'amount' => (int) $salary->total_salary,
                'daily_salary_total' => (int) ($salary->daily_salary_total ?? 0),
                'paid_amount' => $salary->paid_amount !== null ? (int) $salary->paid_amount : null,
                'status' => $statusText,
                'paymentDate' => $salary->payment_date ? $salary->payment_date->toDateString() : null,
                'has_loan' => $hasLoan,
                'user_id' => $salary->user_id,
                'user_name' => $salary->user?->name,
            ];
        };

        // Paginated response (admin list with page param)
        if ($request->has('page')) {
            $paged = $query->paginate($request->integer('per_page', 20));
            $data = $paged->getCollection()->map($formatItem)->values();
            return response()->json([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'current_page' => $paged->currentPage(),
                    'last_page' => $paged->lastPage(),
                    'per_page' => $paged->perPage(),
                    'total' => $paged->total(),
                ],
            ]);
        }

        // Legacy response (no page param): full list, no meta
        $formatted = $query->get()->map($formatItem)->values();
        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    /**
     * Get monthly salary details with daily breakdown
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');

        $query = MonthlySalary::with(['presences.shiftStore', 'presences.store', 'user']);

        // Non-admin: only own records
        if (!$isAdmin) {
            $query->where('user_id', $user->id);
        }

        $salary = $query->findOrFail($id);

        $periodLabel = Carbon::parse($salary->period_start)->translatedFormat('F Y');

        // Map status (default DRAFT -> 'draft', konsisten dengan index())
        $statusText = 'draft';
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
                'user_id' => $salary->user_id,
                'user_name' => $salary->user?->name,
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

    /**
     * Get employees who have monthly salary records (admin filter helper).
     * Mirrors DailySalaryController::employees().
     */
    public function employees()
    {
        $userIds = MonthlySalary::distinct()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->toArray();

        if (empty($userIds)) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $employees = User::whereIn('id', $userIds)
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', ['staff', 'former-employee']);
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $employees,
        ]);
    }
}
