<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermitEmployee;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminLeaveController extends Controller
{
    protected $exportService;

    public function __construct(ExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * Display a listing of leave requests with pagination and filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = PermitEmployee::with(['createdBy', 'approvedBy'])
            ->whereHas('createdBy', function ($q) {
                $q->role('staff');
            });

        // Apply date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('from_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('until_date', '<=', $request->date_to);
        }

        // Apply employee filter
        if ($request->filled('user_id')) {
            $query->where('created_by_id', $request->user_id);
        }

        // Apply search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('createdBy', function ($q) use ($search) {
                $q->role('staff')
                  ->where(function ($subQ) use ($search) {
                      $subQ->where('name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Apply status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Apply leave type filter
        if ($request->filled('leave_type') || $request->filled('reason')) {
            $leaveType = $request->leave_type ?: $request->reason;
            $query->where('reason', $leaveType);
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginate results
        $perPage = $request->get('per_page', 15);
        $leaves = $query->paginate($perPage);

        // Transform data to include text representations
        $leaves->getCollection()->transform(function ($leave) {
            $leave->reason_text = PermitEmployee::getReasonText($leave->reason);
            $leave->status_text = PermitEmployee::getStatusText($leave->status);
            return $leave;
        });

        return response()->json([
            'success' => true,
            'data' => $leaves,
            'message' => 'Permintaan cuti berhasil diambil'
        ]);
    }

    /**
     * Display the specified leave request
     */
    public function show(string $id): JsonResponse
    {
        $leave = PermitEmployee::with(['createdBy', 'approvedBy'])
            ->find($id);

        if (!$leave) {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan cuti tidak ditemukan'
            ], 404);
        }

        // Add text representations
        $leave->reason_text = PermitEmployee::getReasonText($leave->reason);
        $leave->status_text = PermitEmployee::getStatusText($leave->status);

        return response()->json([
            'success' => true,
            'data' => $leave,
            'message' => 'Detail permintaan cuti berhasil diambil'
        ]);
    }

    /**
     * Approve a leave request
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $leave = PermitEmployee::find($id);

        if (!$leave) {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan cuti tidak ditemukan'
            ], 404);
        }

        // Check if already approved or rejected (use loose comparison for type safety)
        $status = (string) $leave->status;
        if ($status != PermitEmployee::STATUS_PENDING && $status != PermitEmployee::STATUS_RESUBMIT) {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan cuti sudah diproses sebelumnya'
            ], 422);
        }

        $leave->update([
            'status' => PermitEmployee::STATUS_APPROVED,
            'approved_by_id' => Auth::id(),
            'approved_at' => now(),
            'admin_notes' => $request->input('notes', 'Permintaan cuti disetujui')
        ]);

        $leave->load(['createdBy', 'approvedBy']);
        $leave->reason_text = PermitEmployee::getReasonText($leave->reason);
        $leave->status_text = PermitEmployee::getStatusText($leave->status);

        Log::info('Leave request approved', [
            'leave_id' => $leave->id,
            'employee_id' => $leave->created_by_id,
            'approved_by' => Auth::id()
        ]);

        return response()->json([
            'success' => true,
            'data' => $leave,
            'message' => 'Permintaan cuti berhasil disetujui'
        ]);
    }

    /**
     * Reject a leave request
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reject_note' => 'nullable|string|max:500'
        ]);

        $leave = PermitEmployee::find($id);

        if (!$leave) {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan cuti tidak ditemukan'
            ], 404);
        }

        // Check if already approved or rejected (use loose comparison for type safety)
        $status = (string) $leave->status;
        if ($status != PermitEmployee::STATUS_PENDING && $status != PermitEmployee::STATUS_RESUBMIT) {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan cuti sudah diproses sebelumnya'
            ], 422);
        }

        $leave->update([
            'status' => PermitEmployee::STATUS_REJECTED,
            'approved_by_id' => Auth::id(),
            'approved_at' => now(),
            'admin_notes' => $request->input('reject_note', 'Permintaan cuti ditolak')
        ]);

        $leave->load(['createdBy', 'approvedBy']);
        $leave->reason_text = PermitEmployee::getReasonText($leave->reason);
        $leave->status_text = PermitEmployee::getStatusText($leave->status);

        Log::info('Leave request rejected', [
            'leave_id' => $leave->id,
            'employee_id' => $leave->created_by_id,
            'rejected_by' => Auth::id(),
            'reject_note' => $request->input('reject_note')
        ]);

        return response()->json([
            'success' => true,
            'data' => $leave,
            'message' => 'Permintaan cuti berhasil ditolak'
        ]);
    }

    /**
     * Export leaves data
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|in:excel,pdf,csv',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:1,2,3,4',
            'reason' => 'nullable|in:1,2,3,4,5',
            'search' => 'nullable|string|max:255',
        ]);

        try {
            // Build query for export with the same filters as index
            $query = PermitEmployee::with(['createdBy', 'approvedBy'])
                ->whereHas('createdBy', function ($q) {
                    $q->role('staff');
                });

            // Apply filters
            if (isset($validated['date_from'])) {
                $query->whereDate('from_date', '>=', $validated['date_from']);
            }
            if (isset($validated['date_to'])) {
                $query->whereDate('until_date', '<=', $validated['date_to']);
            }
            if (isset($validated['user_id'])) {
                $query->where('created_by_id', $validated['user_id']);
            }
            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            }
            if (isset($validated['reason'])) {
                $query->where('reason', $validated['reason']);
            }
            if (isset($validated['search'])) {
                $search = $validated['search'];
                $query->whereHas('createdBy', function ($q) use ($search) {
                    $q->role('staff')
                      ->where(function ($subQ) use ($search) {
                          $subQ->where('name', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Execute export using ExportService
            $result = $this->exportService->exportLeaves($validated, $validated['format']);

            if ($result['status'] === 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Export gagal',
                    'error' => $result['error_message'] ?? 'Unknown error'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Export berhasil',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Leave export failed', [
                'error' => $e->getMessage(),
                'filters' => $validated
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Export gagal',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
