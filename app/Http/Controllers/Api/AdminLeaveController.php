<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermitEmployee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminLeaveController extends Controller
{
    // Implement admin leave management methods here
    public function index()
    {
        $user = Auth::user();

        if (!$user || !$user->hasRole('admin')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        \Log::info('AdminLeaveController::index accessed by admin', [
            'user' => $user,
            'request' => request()->all(),
        ]);

        $leaves = PermitEmployee::with(['createdBy', 'approvedBy']);
        dd($leaves->toSql(), $leaves->getBindings());

        \Log::info('Fetched leaves data:', [
           'leaves' => $leaves->items(),
           'total' => $leaves->total(),
           'per_page' => $leaves->perPage(),
           'current_page' => $leaves->currentPage(),
           'last_page' => $leaves->lastPage(),
       ]);

       return response()->json([
           'status' => 'success',
           'data' => $leaves->items(),
           'total' => $leaves->total(),
           'per_page' => $leaves->perPage(),
           'current_page' => $leaves->currentPage(),
           'last_page' => $leaves->lastPage(),
       ], 200);
   }

    public function show($id)
    {
        // Implement logic to show a specific leave request
        return response()->json(['message' => 'Show Leave Request', 'id' => $id], 200);
    }

    public function approve($id)
    {
        // Implement logic to approve a leave request
        return response()->json(['message' => 'Approve Leave Request', 'id' => $id], 200);
    }

    public function reject($id)
    {
        // Implement logic to reject a leave request
        return response()->json(['message' => 'Reject Leave Request', 'id' => $id], 200);
    }
}
