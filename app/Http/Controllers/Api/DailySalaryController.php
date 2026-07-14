<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailySalary;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DailySalaryController extends Controller
{
    /**
     * List daily salaries.
     * - Admin sees all
     * - Non-admin sees only their own
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = DailySalary::with(['createdBy', 'store', 'shiftStore', 'paymentType']);

        // Non-admin only sees their own
        if (!$user->hasRole('admin')) {
            $query->where('created_by_id', $user->id);
        }

        // Filter by employee (admin only)
        if ($request->has('user_id') && $user->hasRole('admin')) {
            $query->where('created_by_id', $request->user_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment type (1 = Transfer, 2 = Tunai)
        if ($request->has('payment_type_id')) {
            $query->where('payment_type_id', $request->payment_type_id);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        $dailySalaries = $query->orderBy('date', 'desc')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $dailySalaries->items(),
            'meta' => [
                'current_page' => $dailySalaries->currentPage(),
                'last_page' => $dailySalaries->lastPage(),
                'per_page' => $dailySalaries->perPage(),
                'total' => $dailySalaries->total(),
            ],
        ]);
    }

    /**
     * Get employees who have daily salary records
     * Only show users with role 'staff' or 'former employee'
     */
    public function employees()
    {
        // Get user IDs from daily_salaries table (different connection)
        $userIds = DailySalary::distinct()
            ->whereNotNull('created_by_id')
            ->pluck('created_by_id')
            ->toArray();

        if (empty($userIds)) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $employees = User::whereIn('id', $userIds)
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', ['staff', 'former employee']);
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $employees,
        ]);
    }

    /**
     * Get daily salaries for payment receipt (transfer type, status 3 = siap dibayar)
     */
    public function forPayment(Request $request)
    {
        $user = $request->user();
        $query = DailySalary::with(['createdBy', 'store', 'paymentType'])
            ->where('payment_type_id', 1) // Transfer
            ->where('status', '3') // Siap dibayar
            ->whereDoesntHave('paymentReceipts');

        // Non-admin only sees their own
        if (!$user->hasRole('admin')) {
            $query->where('created_by_id', $user->id);
        }

        // Filter by user_id (admin only)
        if ($request->has('user_id') && $user->hasRole('admin')) {
            $query->where('created_by_id', $request->user_id);
        }

        $dailySalaries = $query->orderBy('date', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $dailySalaries,
        ]);
    }

    /**
     * Show a single daily salary
     */
    public function show($id)
    {
        $dailySalary = DailySalary::with(['createdBy', 'store', 'shiftStore', 'paymentType', 'approvedBy'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $dailySalary,
        ]);
    }

    /**
     * Bulk update status for daily salaries (admin only)
     */
    public function bulkUpdateStatus(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admin can perform bulk updates.',
            ], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:daily_salaries,id',
            'status' => 'required|in:1,2,3,4',
        ]);

        $updated = DailySalary::whereIn('id', $request->ids)
            ->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => "$updated daily salary(s) updated successfully.",
            'data' => ['updated_count' => $updated],
        ]);
    }
}
