<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\EmployeeLocation;
use App\Models\LocationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menerima data lokasi & device token dari aplikasi mobile pegawai.
 *
 * Endpoint di sini dipanggil oleh:
 *  - workmanager periodic task (update lokasi tiap ~2 jam)
 *  - FCM background handler (jawaban atas permintaan on-demand dari admin)
 */
class LocationController extends Controller
{
    /**
     * POST /location
     *
     * Menyimpan titik lokasi pegawai. Bila membawa `request_id`, lokasi ini
     * dianggap jawaban atas permintaan on-demand dan request ditandai fulfilled.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'source' => ['required', 'string', 'in:on_demand,periodic'],
            'request_id' => ['nullable', 'uuid'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $userId = Auth::id();
        $requestId = $validated['request_id'] ?? null;

        return DB::transaction(function () use ($validated, $userId, $requestId) {
            $location = EmployeeLocation::create([
                'created_by_id' => $userId,
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'accuracy' => $validated['accuracy'] ?? null,
                'source' => $validated['source'],
                'request_id' => $requestId,
                'captured_at' => $validated['captured_at'] ?? now(),
            ]);

            // Tutup permintaan on-demand bila ada korelasi request_id.
            if ($requestId) {
                LocationRequest::where('request_id', $requestId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'fulfilled',
                        'fulfilled_at' => now(),
                    ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Lokasi tersimpan.',
                'data' => $location->only(['id', 'latitude', 'longitude', 'captured_at']),
            ]);
        });
    }

    /**
     * POST /device-tokens
     *
     * Mendaftarkan FCM token milik device pegawai agar admin dapat memicu
     * permintaan lokasi on-demand. Dipanggil setelah login & setiap token refresh.
     */
    public function registerToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'device_id' => ['nullable', 'string'],
        ]);

        $userId = Auth::id();

        DeviceToken::updateOrCreate(
            [
                'user_id' => $userId,
                'token' => $validated['token'],
            ],
            [
                'device_id' => $validated['device_id'] ?? null,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Device token terdaftar.',
        ]);
    }

    /**
     * DELETE /device-tokens
     *
     * Menghapus FCM token saat logout agar tidak ada push ke device yang tidak
     * lagi dipakai pegawai.
     */
    public function deregisterToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        DeviceToken::where('user_id', Auth::id())
            ->where('token', $validated['token'])
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Device token dihapus.',
        ]);
    }
}
