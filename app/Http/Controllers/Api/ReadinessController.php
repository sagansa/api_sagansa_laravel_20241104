<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Readiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ReadinessController extends Controller
{
    /**
     * List the authenticated user's readiness history.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $userId = $this->resolvePresenceUserId($user);

        $perPage = max(1, (int) $request->input('per_page', 15));

        $readinesses = Readiness::with(['createdBy:id,name'])
            ->where('created_by_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => $readinesses->items(),
            'meta' => [
                'current_page' => $readinesses->currentPage(),
                'last_page' => $readinesses->lastPage(),
                'per_page' => $readinesses->perPage(),
                'total' => $readinesses->total(),
            ],
        ]);
    }

    public function checkStatus(Request $request)
    {
        $user = Auth::user();
        $userId = $this->resolvePresenceUserId($user);

        $today = Carbon::today();
        $readiness = Readiness::where('created_by_id', $userId)
            ->whereDate('created_at', $today)
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'has_submitted_today' => $readiness ? true : false,
                'readiness' => $readiness
            ]
        ]);
    }

    /**
     * List seluruh kesiapan diri untuk role admin/super_admin.
     *
     * Menampilkan readiness semua user (bukan hanya milik sendiri) sehingga
     * admin dapat memantau daftar kesiapan harian karyawan. Mendukung filter
     * opsional ?date=YYYY-MM-DD (default hari ini).
     */
    public function adminIndex(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak.',
            ], 403);
        }

        $perPage = max(1, (int) $request->input('per_page', 15));
        $date = $request->input('date');
        $query = Readiness::with(['createdBy:id,name']);

        // Tanpa filter ?date= -> tampilkan semua laporan (tidak dibatasi hari ini).
        if ($date) {
            $query->whereDate('created_at', $date);
        }

        $readinesses = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => $readinesses->items(),
            'meta' => [
                'current_page' => $readinesses->currentPage(),
                'last_page' => $readinesses->lastPage(),
                'per_page' => $readinesses->perPage(),
                'total' => $readinesses->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $userId = $this->resolvePresenceUserId($user);
        $today = Carbon::today();

        $existing = Readiness::where('created_by_id', $userId)
            ->whereDate('created_at', $today)
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda sudah mengisi kesiapan hari ini'
            ], 400);
        }

        $request->validate([
            'image_selfie' => 'required|string',
            'left_hand' => 'required|string',
            'right_hand' => 'required|string',
        ]);

        $imageSelfiePath = null;
        $leftHandPath = null;
        $rightHandPath = null;

        try {
            $imageSelfiePath = $request->input('image_selfie');
            $leftHandPath = $request->input('left_hand');
            $rightHandPath = $request->input('right_hand');

            $readiness = Readiness::create([
                'created_by_id' => $userId,
                'image_selfie' => $imageSelfiePath,
                'left_hand' => $leftHandPath,
                'right_hand' => $rightHandPath,
                'status' => '1', // 1: belum diperiksa
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Kesiapan berhasil dikirim',
                'data' => $readiness
            ], 201);
        } catch (\Exception $e) {
            if ($imageSelfiePath) app(\App\Contracts\ImageStorageContract::class)->delete($imageSelfiePath);
            if ($leftHandPath) app(\App\Contracts\ImageStorageContract::class)->delete($leftHandPath);
            if ($rightHandPath) app(\App\Contracts\ImageStorageContract::class)->delete($rightHandPath);

            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ubah status kesiapan diri (admin/super_admin).
     * Status: 1 = belum diperiksa, 2 = sudah diperiksa.
     */
    public function updateStatus(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user || !$user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak.',
            ], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:1,2'],
        ]);

        $readiness = Readiness::findOrFail($id);
        $readiness->update(['status' => $data['status']]);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => $readiness->fresh(['createdBy:id,name']),
        ]);
    }

    private function resolvePresenceUserId($authUser): int
    {
        $presenceUserId = DB::table('users')
            ->where('email', $authUser->email)
            ->value('id');
            
        return $presenceUserId ? (int) $presenceUserId : (int) $authUser->id;
    }
}
