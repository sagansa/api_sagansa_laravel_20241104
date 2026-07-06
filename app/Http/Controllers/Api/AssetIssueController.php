<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssetIssue;
use App\Models\Presence;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Issue/temuan pemeriksaan aset (modul sederhana: open/closed).
 *
 * Akses berbasis role+store: admin lihat semua; user lain lihat issue dari
 * aset yang dia buat atau aset di store tempat dia check-in hari ini.
 */
class AssetIssueController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = AssetIssue::with([
            'asset:id,name,code,store_id',
            'asset.category:id,name',
            'assetCheck:id,check_date,severity',
            'reportedBy:id,name',
            'resolvedBy:id,name',
        ]);

        // Scope berbasis role+store.
        if (!$user->hasRole('admin')) {
            $storeId = $this->userTodayStoreId($user);
            $query->whereHas('asset', function ($aq) use ($user, $storeId) {
                $aq->where(function ($q) use ($user, $storeId) {
                    $q->where('created_by_id', $user->id);
                    if ($storeId !== null) {
                        $q->orWhere('store_id', $storeId);
                    }
                });
            });
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->asset_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->open(); // default: open saja
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('store_id')) {
            $query->whereHas('asset', fn($q) => $q->where('store_id', $request->store_id));
        }

        $issues = $query->orderByRaw('status = ' . AssetIssue::STATUS_OPEN . ' DESC')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $issues,
        ]);
    }

    /**
     * Tutup issue: set status closed + resolved_by + resolved_at.
     * Hanya PIC/creator/admin.
     */
    public function close(Request $request, $id)
    {
        $user = $request->user();
        $issue = AssetIssue::with('asset')->find($id);
        if (!$issue) {
            return response()->json([
                'success' => false,
                'message' => 'Issue tidak ditemukan.',
            ], 404);
        }

        // Gate: admin / creator / storage-staff di store aset.
        $storeId = $this->userTodayStoreId($user);
        $allowed = $user->hasRole('admin')
            || $issue->asset?->created_by_id === $user->id
            || $storeId === $issue->asset?->store_id;

        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak berhak menutup issue ini.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $issue->update([
            'status' => AssetIssue::STATUS_CLOSED,
            'resolved_by_id' => $user->id,
            'resolved_at' => Carbon::now(),
            'notes' => $request->notes ?? $issue->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Issue berhasil ditutup.',
            'data' => $issue->fresh(['asset', 'resolvedBy:id,name']),
        ]);
    }

    private function userTodayStoreId($user): ?int
    {
        $presence = Presence::where('created_by_id', $user->id)
            ->whereDate('check_in', Carbon::now()->toDateString())
            ->first();
        return $presence?->store_id;
    }
}
