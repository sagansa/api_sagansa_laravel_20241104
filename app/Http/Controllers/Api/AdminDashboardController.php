<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Presence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get dashboard statistics for today
     */
    public function stats(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->format('Y-m-d'));
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        // Get total employees with staff role
        $totalEmployees = User::role('staff')->count();

        // Get presences for the selected date from staff only
        $presencesToday = Presence::whereBetween('check_in', [$startOfDay, $endOfDay])
            ->whereHas('createdBy', function ($query) {
                $query->role('staff');
            })
            ->get();

        // Calculate statistics
        $presentToday = $presencesToday->count();
        $absentToday = $totalEmployees - $presentToday;
        
        // Count late employees (assuming status 2 means late or checking shift times)
        $lateToday = $presencesToday->where('status', 2)->count();

        // Calculate check-out statistics
        $checkedOut = $presencesToday->whereNotNull('check_out')->count();
        $notCheckedOut = $presentToday - $checkedOut;

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'total_employees' => $totalEmployees,
                'present_today' => $presentToday,
                'absent_today' => $absentToday,
                'late_today' => $lateToday,
                'checked_out' => $checkedOut,
                'not_checked_out' => $notCheckedOut,
                'attendance_rate' => $totalEmployees > 0 ? round(($presentToday / $totalEmployees) * 100, 2) : 0,
            ]
        ]);
    }

    /**
     * Get presence trends for the last 7 days
     */
    public function trends(Request $request): JsonResponse
    {
        $days = $request->get('days', 7);
        $endDate = now();
        $startDate = now()->subDays($days - 1);

        $trends = [];
        $totalEmployees = User::role('staff')->count();

        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $presenceCount = Presence::whereBetween('check_in', [$dayStart, $dayEnd])
                ->whereHas('createdBy', function ($query) {
                    $query->role('staff');
                })
                ->count();
            $lateCount = Presence::whereBetween('check_in', [$dayStart, $dayEnd])
                ->whereHas('createdBy', function ($query) {
                    $query->role('staff');
                })
                ->where('status', 2)
                ->count();

            $trends[] = [
                'date' => $date->format('Y-m-d'),
                'day_name' => $date->format('l'),
                'present' => $presenceCount,
                'absent' => $totalEmployees - $presenceCount,
                'late' => $lateCount,
                'attendance_rate' => $totalEmployees > 0 ? round(($presenceCount / $totalEmployees) * 100, 2) : 0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'days' => $days
                ],
                'trends' => $trends
            ]
        ]);
    }

    /**
     * Get list of employees who haven't checked in today
     */
    public function absentEmployees(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->format('Y-m-d'));
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        // Get staff employees who have checked in today
        $presentEmployeeIds = Presence::whereBetween('check_in', [$startOfDay, $endOfDay])
            ->whereHas('createdBy', function ($query) {
                $query->role('staff');
            })
            ->pluck('created_by_id')
            ->toArray();

        // Get staff employees who haven't checked in
        $absentEmployees = User::role('staff')
            ->whereNotIn('id', $presentEmployeeIds)
            ->with(['company'])
            ->select('id', 'name', 'email', 'company_id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'count' => $absentEmployees->count(),
                'employees' => $absentEmployees
            ]
        ]);
    }

    /**
     * Get employees who haven't checked out yet
     */
    public function notCheckedOut(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->format('Y-m-d'));
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        $notCheckedOut = Presence::whereBetween('check_in', [$startOfDay, $endOfDay])
            ->whereNull('check_out')
            ->whereHas('createdBy', function ($query) {
                $query->role('staff');
            })
            ->with(['createdBy', 'store', 'shiftStore'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'count' => $notCheckedOut->count(),
                'presences' => $notCheckedOut
            ]
        ]);
    }

    /**
     * Get recent presence activities
     */
    public function recentActivities(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);

        $recentActivities = Presence::with(['createdBy', 'store'])
            ->whereHas('createdBy', function ($query) {
                $query->role('staff');
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($presence) {
                return [
                    'id' => $presence->id,
                    'employee_name' => $presence->createdBy->name,
                    'store_name' => $presence->store->nickname,
                    'check_in' => $presence->check_in,
                    'check_out' => $presence->check_out,
                    'status' => $presence->status,
                    'created_at' => $presence->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $recentActivities
        ]);
    }
}