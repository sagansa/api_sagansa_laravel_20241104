<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPresenceStoreRequest;
use App\Http\Requests\AdminPresenceUpdateRequest;
use App\Models\Presence;
use App\Models\User;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class AdminPresenceController extends Controller
{
    protected $exportService;

    public function __construct(ExportService $exportService)
    {
        $this->exportService = $exportService;
    }
    /**
     * Display a listing of presences with pagination and filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = Presence::with(['createdBy', 'store', 'shiftStore'])
            ->whereHas('createdBy', function ($q) {
                $q->role('staff');
            });

        // Apply date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('check_in', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('check_in', '<=', $request->date_to);
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

        // Apply store filter
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Apply status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'check_in');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginate results
        $perPage = $request->get('per_page', 15);
        $presences = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $presences
        ]);
    }

    /**
     * Store a newly created presence
     */
    public function store(AdminPresenceStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Check for duplicate presence on the same date
        $existingPresence = Presence::where('created_by_id', $validated['created_by_id'])
            ->whereDate('check_in', date('Y-m-d', strtotime($validated['check_in'])))
            ->first();

        if ($existingPresence) {
            return response()->json([
                'success' => false,
                'message' => 'Presence already exists for this employee on the selected date',
                'errors' => [
                    'created_by_id' => ['Presence already exists for this date']
                ]
            ], 422);
        }

        $presence = Presence::create($validated);
        $presence->load(['createdBy', 'store', 'shiftStore']);

        return response()->json([
            'success' => true,
            'message' => 'Presence created successfully',
            'data' => $presence
        ], 201);
    }

    /**
     * Display the specified presence
     */
    public function show(string $id): JsonResponse
    {
        $presence = Presence::with(['createdBy', 'store', 'shiftStore'])
            ->find($id);

        if (!$presence) {
            return response()->json([
                'success' => false,
                'message' => 'Presence not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $presence
        ]);
    }

    /**
     * Update the specified presence
     */
    public function update(AdminPresenceUpdateRequest $request, string $id): JsonResponse
    {
        $presence = Presence::find($id);

        if (!$presence) {
            return response()->json([
                'success' => false,
                'message' => 'Presence not found'
            ], 404);
        }

        $validated = $request->validated();

        // Check for duplicate if employee or date is being changed
        if (isset($validated['created_by_id']) || isset($validated['check_in'])) {
            $employeeId = $validated['created_by_id'] ?? $presence->created_by_id;
            $checkInDate = isset($validated['check_in']) 
                ? date('Y-m-d', strtotime($validated['check_in']))
                : $presence->check_in->format('Y-m-d');

            $existingPresence = Presence::where('created_by_id', $employeeId)
                ->whereDate('check_in', $checkInDate)
                ->where('id', '!=', $id)
                ->first();

            if ($existingPresence) {
                return response()->json([
                    'success' => false,
                    'message' => 'Presence already exists for this employee on the selected date',
                    'errors' => [
                        'created_by_id' => ['Presence already exists for this date']
                    ]
                ], 422);
            }
        }

        $presence->update($validated);
        $presence->load(['createdBy', 'store', 'shiftStore']);

        return response()->json([
            'success' => true,
            'message' => 'Presence updated successfully',
            'data' => $presence
        ]);
    }

    /**
     * Remove the specified presence
     */
    public function destroy(string $id): JsonResponse
    {
        $presence = Presence::find($id);

        if (!$presence) {
            return response()->json([
                'success' => false,
                'message' => 'Presence not found'
            ], 404);
        }

        $presence->delete();

        return response()->json([
            'success' => true,
            'message' => 'Presence deleted successfully'
        ]);
    }

    /**
     * Check for duplicate presence
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'created_by_id' => 'required|exists:users,id',
            'check_in_date' => 'required|date',
            'exclude_id' => 'nullable|exists:presences,id'
        ]);

        $query = Presence::where('created_by_id', $validated['created_by_id'])
            ->whereDate('check_in', $validated['check_in_date']);

        if (isset($validated['exclude_id'])) {
            $query->where('id', '!=', $validated['exclude_id']);
        }

        $existingPresence = $query->first();

        return response()->json([
            'success' => true,
            'data' => [
                'has_duplicate' => $existingPresence !== null,
                'existing_presence' => $existingPresence ? [
                    'id' => $existingPresence->id,
                    'check_in' => $existingPresence->check_in,
                    'check_out' => $existingPresence->check_out,
                ] : null
            ]
        ]);
    }

    /**
     * Export presences data
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|in:excel,pdf,csv',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => 'nullable|exists:users,id',
            'store_id' => 'nullable|exists:stores,id',
            'search' => 'nullable|string|max:255',
        ]);

        // Validate export request
        $validationErrors = $this->exportService->validateExportRequest($validated);
        if (!empty($validationErrors)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validationErrors
            ], 422);
        }

        try {
            // Perform export
            $result = $this->exportService->exportPresences($validated, $validated['format']);

            if ($result['status'] === 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Export failed',
                    'error' => $result['error_message'] ?? 'Unknown error'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Export completed successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get export status (placeholder for job-based exports)
     */
    public function getExportStatus(string $jobId): JsonResponse
    {
        // For now, we'll return a completed status since we're doing synchronous exports
        // In a real implementation, you'd store job status in database or cache
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $jobId,
                'status' => 'completed',
                'progress' => 100,
                'created_at' => now()->toISOString(),
                'completed_at' => now()->toISOString(),
            ]
        ]);
    }

    /**
     * Get export history (placeholder)
     */
    public function getExportHistory(): JsonResponse
    {
        // For now, return empty array
        // In a real implementation, you'd store export jobs in database
        return response()->json([
            'success' => true,
            'data' => []
        ]);
    }

    /**
     * Cancel export job (placeholder)
     */
    public function cancelExport(string $jobId): JsonResponse
    {
        // For now, just return success
        // In a real implementation, you'd cancel the running job
        return response()->json([
            'success' => true,
            'message' => 'Export job cancelled'
        ]);
    }
}