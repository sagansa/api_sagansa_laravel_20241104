<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\PermitEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $leaves = PermitEmployee::with(['createdBy', 'approvedBy'])
            ->where('created_by_id', $user->id)
            ->orderBy('from_date', 'desc')
            ->get()
            ->map(function ($leave) {
                return $this->formatLeave($leave);
            });

        return response()->json([
            'status' => 'success',
            'data' => $leaves
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'reason' => 'required|in:1,2,3,4,5',
            'from_date' => 'required|date|after_or_equal:today',
            'until_date' => 'required|date|after_or_equal:from_date',
            'notes' => 'nullable|string',
        ]);

        $user = Auth::user();

        // Cek apakah sudah ada cuti yang overlap
        $existingLeave = PermitEmployee::where('created_by_id', $user->id)
            ->where(function ($query) use ($request) {
                $query->whereBetween('from_date', [$request->from_date, $request->until_date])
                    ->orWhereBetween('until_date', [$request->from_date, $request->until_date]);
            })
            ->whereNotIn('status', [PermitEmployee::STATUS_REJECTED])
            ->first();

        if ($existingLeave) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sudah ada pengajuan cuti pada rentang waktu tersebut'
            ], 400);
        }

        $leave = PermitEmployee::create([
            'reason' => $request->reason,
            'from_date' => $request->from_date,
            'until_date' => $request->until_date,
            'notes' => $request->notes,
            'status' => PermitEmployee::STATUS_PENDING,
            'created_by_id' => $user->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengajuan cuti berhasil dibuat',
            'data' => $this->formatLeave($leave)
        ], 201);
    }

    public function show($id)
    {
        $user = Auth::user();
        $leave = PermitEmployee::with(['createdBy', 'approvedBy'])
            ->where('created_by_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $this->formatLeave($leave)
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $leave = PermitEmployee::where('created_by_id', $user->id)->findOrFail($id);

        // Cek apakah izin/cuti masih bisa diedit (hanya yang status pending atau pengajuan ulang)
        if (!in_array($leave->status, [PermitEmployee::STATUS_PENDING, PermitEmployee::STATUS_RESUBMIT])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pengajuan cuti tidak dapat diedit karena status sudah ' .
                    PermitEmployee::getStatusText($leave->status)
            ], 400);
        }

        // Validasi input
        $request->validate([
            'reason' => 'required|in:1,2,3,4,5',
            'from_date' => 'required|date',
            'until_date' => 'required|date|after_or_equal:from_date',
            'notes' => 'nullable|string',
        ]);

        // Cek apakah sudah ada cuti lain yang overlap (exclude cuti yang sedang diedit)
        $existingLeave = PermitEmployee::where('created_by_id', $user->id)
            ->where('id', '!=', $id)
            ->where(function ($query) use ($request) {
                $query->whereBetween('from_date', [$request->from_date, $request->until_date])
                    ->orWhereBetween('until_date', [$request->from_date, $request->until_date]);
            })
            ->whereNotIn('status', [PermitEmployee::STATUS_REJECTED])
            ->first();

        if ($existingLeave) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sudah ada pengajuan cuti lain pada rentang waktu tersebut'
            ], 400);
        }

        // Update data
        $leave->update([
            'reason' => $request->reason,
            'from_date' => $request->from_date,
            'until_date' => $request->until_date,
            'notes' => $request->notes,
            'status' => PermitEmployee::STATUS_PENDING, // Reset status ke pending
            'approved_by_id' => null // Reset approved_by karena status kembali ke pending
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengajuan cuti berhasil diperbarui',
            'data' => $this->formatLeave($leave)
        ]);
    }

    public function destroy($id)
    {
        $user = Auth::user();
        $leave = PermitEmployee::where('created_by_id', $user->id)->findOrFail($id);

        // Cek apakah izin/cuti masih bisa dihapus (hanya yang status pending atau pengajuan ulang)
        if (!in_array($leave->status, [PermitEmployee::STATUS_PENDING, PermitEmployee::STATUS_RESUBMIT])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pengajuan cuti tidak dapat dihapus karena status sudah ' .
                    PermitEmployee::getStatusText($leave->status)
            ], 400);
        }

        $leave->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Pengajuan cuti berhasil dihapus'
        ]);
    }

    private function formatLeave($leave)
    {
        return [
            'id' => $leave->id,
            'reason' => $leave->reason,
            'reason_text' => PermitEmployee::getReasonText($leave->reason),
            'from_date' => $leave->from_date,
            'until_date' => $leave->until_date,
            'status' => $leave->status,
            'status_text' => PermitEmployee::getStatusText($leave->status),
            'notes' => $leave->notes,
            'created_by' => $leave->createdBy ? [
                'id' => $leave->createdBy->id,
                'name' => $leave->createdBy->name
            ] : null,
            'approved_by' => $leave->approvedBy ? [
                'id' => $leave->approvedBy->id,
                'name' => $leave->approvedBy->name
            ] : null,
            'created_at' => $leave->created_at,
            'updated_at' => $leave->updated_at
        ];
    }
}
