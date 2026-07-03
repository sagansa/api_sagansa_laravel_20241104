<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLocation;
use App\Models\LocationRequest;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Endpoint admin untuk pelacakan lokasi pegawai.
 *
 * Mendukung:
 *  - trigger on-demand (POST) → kirim FCM ke device pegawai untuk minta lokasi
 *  - daftar lokasi terbaru seluruh pegawai (GET) → untuk peta admin
 *  - history satu pegawai (GET) → untuk detail
 */
class AdminTrackLocationController extends Controller
{
    public function __construct(protected FcmService $fcm)
    {
    }

    /**
     * POST /admin/track-location/{user}
     *
     * Memicu permintaan lokasi on-demand. Membuat record LocationRequest lalu
     * mengirim FCM data-message ke device pegawai. Status request = pending,
     * akan berubah fulfilled saat device membalas via POST /location, atau
     * timeout oleh scheduler.
     */
    public function trigger(Request $request, int $user): JsonResponse
    {
        $employee = User::role('staff')->find($user);

        if (! $employee) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pegawai tidak ditemukan.',
            ], 404);
        }

        if (! $this->fcm->hasDevice($employee->id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pegawai belum memiliki perangkat terdaftar (FCM token). Pastikan aplikasi sudah dipasang dan login.',
            ], 422);
        }

        $locationRequest = LocationRequest::create([
            'user_id' => $employee->id,
            'requested_by_id' => Auth::id(),
            'request_id' => $requestId = (string) Str::uuid(),
            'status' => 'pending',
        ]);

        // Kirim push ke semua device pegawai. Payload berisi request_id yang
        // akan dikirim balik oleh device bersama lokasi untuk mencocokkan.
        $sentCount = $this->fcm->sendToUser($employee->id, [
            'type' => 'location_request',
            'request_id' => $requestId,
            'requested_at' => now()->toIso8601String(),
        ]);

        if ($sentCount === 0) {
            $locationRequest->update([
                'status' => 'failed',
                'error' => 'Tidak ada device yang berhasil dikirimi FCM.',
            ]);

            Log::warning('Track location: FCM gagal terkirim ke semua device.', [
                'user_id' => $employee->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengirim permintaan ke perangkat pegawai. Coba beberapa saat lagi.',
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Permintaan lokasi terkirim. Menunggu respons perangkat.',
            'data' => [
                'request_id' => $requestId,
                'status' => 'pending',
                'user' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                ],
            ],
        ]);
    }

    /**
     * GET /admin/track-location/{request}
     *
     * Mengecek status satu permintaan on-demand (apakah sudah fulfilled?).
     */
    public function showRequest(LocationRequest $location_request): JsonResponse
    {
        $location_request->load('location');

        return response()->json([
            'status' => 'success',
            'data' => [
                'request_id' => $location_request->request_id,
                'status' => $location_request->status,
                'created_at' => $location_request->created_at,
                'fulfilled_at' => $location_request->fulfilled_at,
                'location' => $location_request->location
                    ? $location_request->location->only(['latitude', 'longitude', 'accuracy', 'captured_at'])
                    : null,
            ],
        ]);
    }

    /**
     * GET /admin/employee-locations
     *
     * Lokasi terbaru tiap pegawai staff (untuk peta admin). Hanya pegawai yang
     * pernah mengirim lokasi yang muncul.
     */
    public function latestLocations(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 500);

        // Ambil lokasi paling baru per pegawai memakai subquery latest.
        $latest = EmployeeLocation::query()
            ->whereHas('createdBy', function ($q) {
                $q->role('staff');
            })
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('employee_locations')
                    ->groupBy('created_by_id');
            })
            ->with('createdBy:id,name,email')
            ->orderBy('captured_at', 'desc')
            ->limit($limit)
            ->get();

        $data = $latest->map(fn (EmployeeLocation $loc) => [
            'user_id' => $loc->created_by_id,
            'name' => $loc->createdBy?->name,
            'email' => $loc->createdBy?->email,
            'latitude' => $loc->latitude,
            'longitude' => $loc->longitude,
            'accuracy' => $loc->accuracy,
            'source' => $loc->source,
            'captured_at' => $loc->captured_at?->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * GET /admin/employee-locations/{user}
     *
     * Riwayat lokasi satu pegawai.
     */
    public function history(Request $request, int $user): JsonResponse
    {
        $limit = (int) $request->get('limit', 100);

        $locations = EmployeeLocation::where('created_by_id', $user)
            ->orderBy('captured_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $locations->map(fn (EmployeeLocation $loc) => [
                'latitude' => $loc->latitude,
                'longitude' => $loc->longitude,
                'accuracy' => $loc->accuracy,
                'source' => $loc->source,
                'captured_at' => $loc->captured_at?->format('Y-m-d H:i:s'),
            ]),
        ]);
    }
}
