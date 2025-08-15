<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Presence;
use App\Models\User;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminReportController extends Controller
{
    /**
     * Get available report types
     */
    public function types(): JsonResponse
    {
        $reportTypes = [
            [
                'id' => 'monthly',
                'name' => 'Monthly Attendance Report',
                'description' => 'Monthly summary of employee attendance',
                'parameters' => ['month', 'year', 'user_id', 'store_id']
            ],
            [
                'id' => 'late_employees',
                'name' => 'Late Employees Report',
                'description' => 'Employees who frequently arrive late',
                'parameters' => ['date_from', 'date_to', 'user_id', 'store_id']
            ],
            [
                'id' => 'attendance_summary',
                'name' => 'Attendance Summary Report',
                'description' => 'Overall attendance statistics',
                'parameters' => ['date_from', 'date_to', 'user_id', 'store_id']
            ],
            [
                'id' => 'daily_attendance',
                'name' => 'Daily Attendance Report',
                'description' => 'Daily attendance breakdown',
                'parameters' => ['date', 'store_id']
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $reportTypes
        ]);
    }

    /**
     * Generate monthly attendance report
     */
    public function monthly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer|between:2020,2030',
            'user_id' => [
                'nullable',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $user = User::find($value);
                        if ($user && !$user->hasRole('staff')) {
                            $fail('The selected employee must have staff role.');
                        }
                    }
                }
            ],
            'store_id' => 'nullable|exists:stores,id',
        ]);

        $month = $validated['month'] ?? now()->month;
        $year = $validated['year'] ?? now()->year;

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth();

        $query = Presence::whereBetween('check_in', [$startDate, $endDate])
            ->whereHas('createdBy', function ($q) {
                $q->role('staff');
            })
            ->with(['createdBy', 'store', 'shiftStore']);

        if (isset($validated['user_id'])) {
            $query->where('created_by_id', $validated['user_id']);
        }

        if (isset($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

        $presences = $query->get();

        // Group by employee
        $employeeStats = $presences->groupBy('created_by_id')->map(function ($employeePresences, $userId) use ($startDate, $endDate) {
            $employee = $employeePresences->first()->createdBy;
            $totalDays = $startDate->diffInDays($endDate) + 1;
            $workingDays = $this->getWorkingDays($startDate, $endDate);
            
            $presentDays = $employeePresences->count();
            $lateDays = $employeePresences->where('status', 2)->count();
            $absentDays = $workingDays - $presentDays;

            return [
                'employee_id' => $userId,
                'employee_name' => $employee->name,
                'employee_email' => $employee->email,
                'total_working_days' => $workingDays,
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'late_days' => $lateDays,
                'attendance_rate' => $workingDays > 0 ? round(($presentDays / $workingDays) * 100, 2) : 0,
                'punctuality_rate' => $presentDays > 0 ? round((($presentDays - $lateDays) / $presentDays) * 100, 2) : 0,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'month' => $month,
                    'year' => $year,
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'summary' => [
                    'total_employees' => $employeeStats->count(),
                    'average_attendance_rate' => $employeeStats->avg('attendance_rate'),
                    'average_punctuality_rate' => $employeeStats->avg('punctuality_rate'),
                ],
                'employees' => $employeeStats
            ]
        ]);
    }

    /**
     * Generate late employees report
     */
    public function lateEmployees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => [
                'nullable',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $user = User::find($value);
                        if ($user && !$user->hasRole('staff')) {
                            $fail('The selected employee must have staff role.');
                        }
                    }
                }
            ],
            'store_id' => 'nullable|exists:stores,id',
            'min_late_days' => 'nullable|integer|min:1',
        ]);

        $dateFrom = $validated['date_from'] ?? now()->subDays(30)->format('Y-m-d');
        $dateTo = $validated['date_to'] ?? now()->format('Y-m-d');
        $minLateDays = $validated['min_late_days'] ?? 1;

        $startDate = Carbon::parse($dateFrom)->startOfDay();
        $endDate = Carbon::parse($dateTo)->endOfDay();

        $query = Presence::whereBetween('check_in', [$startDate, $endDate])
            ->where('status', 2) // Late status
            ->whereHas('createdBy', function ($q) {
                $q->role('staff');
            })
            ->with(['createdBy', 'store', 'shiftStore']);

        if (isset($validated['user_id'])) {
            $query->where('created_by_id', $validated['user_id']);
        }

        if (isset($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

        $latePresences = $query->get();

        // Group by employee and calculate late statistics
        $lateEmployees = $latePresences->groupBy('created_by_id')->map(function ($employeeLatePresences, $userId) use ($startDate, $endDate) {
            $employee = $employeeLatePresences->first()->createdBy;
            
            // Get total presence days for this employee in the period
            $totalPresenceDays = Presence::where('created_by_id', $userId)
                ->whereBetween('check_in', [$startDate, $endDate])
                ->whereHas('createdBy', function ($q) {
                    $q->role('staff');
                })
                ->count();

            $lateDays = $employeeLatePresences->count();
            
            return [
                'employee_id' => $userId,
                'employee_name' => $employee->name,
                'employee_email' => $employee->email,
                'total_presence_days' => $totalPresenceDays,
                'late_days' => $lateDays,
                'late_percentage' => $totalPresenceDays > 0 ? round(($lateDays / $totalPresenceDays) * 100, 2) : 0,
                'recent_late_dates' => $employeeLatePresences->sortByDesc('check_in')->take(5)->pluck('check_in')->map(function ($date) {
                    return Carbon::parse($date)->format('Y-m-d');
                })->values(),
            ];
        })
        ->filter(function ($employee) use ($minLateDays) {
            return $employee['late_days'] >= $minLateDays;
        })
        ->sortByDesc('late_days')
        ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
                'filters' => [
                    'min_late_days' => $minLateDays,
                ],
                'summary' => [
                    'total_late_employees' => $lateEmployees->count(),
                    'total_late_incidents' => $latePresences->count(),
                    'average_late_percentage' => $lateEmployees->avg('late_percentage'),
                ],
                'employees' => $lateEmployees
            ]
        ]);
    }

    /**
     * Generate attendance summary report
     */
    public function attendanceSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'store_id' => 'nullable|exists:stores,id',
        ]);

        $dateFrom = $validated['date_from'] ?? now()->subDays(30)->format('Y-m-d');
        $dateTo = $validated['date_to'] ?? now()->format('Y-m-d');

        $startDate = Carbon::parse($dateFrom)->startOfDay();
        $endDate = Carbon::parse($dateTo)->endOfDay();

        $query = Presence::whereBetween('check_in', [$startDate, $endDate])
            ->whereHas('createdBy', function ($q) {
                $q->role('staff');
            })
            ->with(['createdBy', 'store']);

        if (isset($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

        $presences = $query->get();
        $totalEmployees = User::role('staff')->count();

        $workingDays = $this->getWorkingDays($startDate, $endDate);

        // Overall statistics
        $totalPresences = $presences->count();
        $latePresences = $presences->where('status', 2)->count();
        $checkedOutPresences = $presences->whereNotNull('check_out')->count();

        // Daily breakdown
        $dailyStats = [];
        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $dayPresences = $presences->filter(function ($presence) use ($dayStart, $dayEnd) {
                $checkIn = Carbon::parse($presence->check_in);
                return $checkIn >= $dayStart && $checkIn <= $dayEnd;
            });

            $dailyStats[] = [
                'date' => $date->format('Y-m-d'),
                'day_name' => $date->format('l'),
                'present' => $dayPresences->count(),
                'late' => $dayPresences->where('status', 2)->count(),
                'attendance_rate' => $totalEmployees > 0 ? round(($dayPresences->count() / $totalEmployees) * 100, 2) : 0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'working_days' => $workingDays,
                ],
                'summary' => [
                    'total_employees' => $totalEmployees,
                    'total_presences' => $totalPresences,
                    'late_presences' => $latePresences,
                    'checked_out_presences' => $checkedOutPresences,
                    'average_daily_attendance' => $workingDays > 0 ? round($totalPresences / $workingDays, 2) : 0,
                    'late_percentage' => $totalPresences > 0 ? round(($latePresences / $totalPresences) * 100, 2) : 0,
                    'checkout_percentage' => $totalPresences > 0 ? round(($checkedOutPresences / $totalPresences) * 100, 2) : 0,
                ],
                'daily_breakdown' => $dailyStats
            ]
        ]);
    }

    /**
     * Export report data
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|in:pdf,excel,csv',
            'report_type' => 'required|in:monthly,late_employees,attendance_summary',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer|between:2020,2030',
            'user_id' => 'nullable|exists:users,id',
            'store_id' => 'nullable|exists:stores,id',
            'min_late_days' => 'nullable|integer|min:1',
        ]);

        // For now, return a placeholder response
        // In a real implementation, you would generate the actual export file
        return response()->json([
            'success' => true,
            'message' => 'Export functionality is not yet implemented',
            'data' => [
                'format' => $validated['format'],
                'report_type' => $validated['report_type'],
                'download_url' => null,
                'filename' => 'report_export.' . $validated['format']
            ]
        ]);
    }

    /**
     * Calculate working days (excluding weekends)
     * This is a simple implementation - you might want to exclude holidays too
     */
    private function getWorkingDays(Carbon $startDate, Carbon $endDate): int
    {
        $workingDays = 0;
        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            if (!$date->isWeekend()) {
                $workingDays++;
            }
        }
        return $workingDays;
    }
}