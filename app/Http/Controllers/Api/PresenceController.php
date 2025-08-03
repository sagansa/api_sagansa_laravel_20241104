<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Presence;
use App\Models\PresenceValidation;
use App\Models\Store;
use App\Models\ShiftStore;
use App\Models\PermitEmployee;
use App\Models\FaceEncoding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PresenceController extends Controller
{
    public function getUserPresence(Request $request)
    {
        $user = Auth::user();
        $now = Carbon::now();
        $today = Carbon::today();
        $toleranceEnd = $today->copy()->addHours(3); // Toleransi sampai jam 3 pagi

        // Jika masih dalam rentang toleransi (sebelum jam 3 pagi)
        if ($now->lt($toleranceEnd)) {
            $yesterday = Carbon::yesterday();
            $todayPresence = Presence::with(['store', 'shiftStore', 'validation'])
                ->where('created_by_id', $user->id)
                ->whereDate('check_in', $yesterday)
                ->whereNull('check_out')
                ->first();

            // Jika tidak ada presensi hari kemarin yang belum checkout, cek hari ini
            if (!$todayPresence) {
                $todayPresence = Presence::with(['store', 'shiftStore', 'validation'])
                    ->where('created_by_id', $user->id)
                    ->whereDate('check_in', $today)
                    ->first();
            }
        } else {
            // Di luar toleransi, ambil presensi hari ini
            $todayPresence = Presence::with(['store', 'shiftStore', 'validation'])
                ->where('created_by_id', $user->id)
                ->whereDate('check_in', $today)
                ->first();
        }

        // Ambil presensi sebelumnya
        $previousPresences = Presence::with(['store', 'shiftStore', 'validation'])
            ->where('created_by_id', $user->id)
            ->where(function ($query) use ($todayPresence) {
                if ($todayPresence) {
                    $query->where('check_in', '<', $todayPresence->check_in);
                }
            })
            ->orderBy('check_in', 'desc')
            ->take(31)
            ->get();

        if ($todayPresence) {
            $todayPresence = $this->formatPresence($todayPresence);
        }

        $previousPresences = $previousPresences->map(function ($presence) {
            return $this->formatPresence($presence);
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'today' => $todayPresence,
                'previous' => $previousPresences
            ]
        ]);
    }

    private function formatPresence($presence)
    {
        // Parse dan set timezone untuk check in/out
        $checkInDateTime = Carbon::parse($presence->check_in)->timezone('Asia/Jakarta');
        $checkOutDateTime = $presence->check_out ? Carbon::parse($presence->check_out)->timezone('Asia/Jakarta') : null;

        // Ambil jadwal shift
        $shiftStartTime = $presence->shiftStore ? $presence->shiftStore->shift_start_time : null;
        $shiftEndTime = $presence->shiftStore ? $presence->shiftStore->shift_end_time : null;

        // Tentukan status ketepatan waktu check-in
        $checkInStatus = null;
        $lateMinutes = null;

        if ($shiftStartTime) {
            $shiftStartDateTime = Carbon::parse($checkInDateTime->format('Y-m-d') . ' ' . $shiftStartTime)
                ->timezone('Asia/Jakarta');

            if ($checkInDateTime->isAfter($shiftStartDateTime)) {
                $checkInStatus = 'terlambat';
                $lateMinutes = $checkInDateTime->diffInMinutes($shiftStartDateTime);
            } else {
                $checkInStatus = 'tepat_waktu';
            }
        }

        // Tentukan status ketepatan waktu check-out
        $checkOutStatus = null;
        if ($shiftEndTime && $checkOutDateTime) {
            // Gabungkan tanggal check-in dengan waktu selesai shift
            $shiftEndDateTime = Carbon::parse($checkInDateTime->format('Y-m-d') . ' ' . $shiftEndTime)
                ->timezone('Asia/Jakarta');

            // Jika shift berakhir di hari berikutnya (misal shift malam)
            if ($shiftEndTime < $shiftStartTime) {
                $shiftEndDateTime->addDay();
            }

            // Tambah toleransi 3 jam untuk checkout
            $checkoutDeadline = $shiftEndDateTime->copy()->addHours(3)
                ->timezone('Asia/Jakarta');

            if ($checkOutDateTime->isBefore($shiftEndDateTime)) {
                $checkOutStatus = 'pulang_cepat';
            } else if ($checkOutDateTime->isBefore($checkoutDeadline)) {
                $checkOutStatus = 'tepat_waktu';
            } else {
                $checkOutStatus = 'terlambat_checkout';
            }
        } elseif ($shiftEndTime && !$checkOutDateTime) {
            // Jika belum checkout, cek apakah sudah lewat batas waktu
            $shiftEndDateTime = Carbon::parse($checkInDateTime->format('Y-m-d') . ' ' . $shiftEndTime)
                ->timezone('Asia/Jakarta');
            if ($shiftEndTime < $shiftStartTime) {
                $shiftEndDateTime->addDay();
            }

            // Tambah toleransi 3 jam
            $checkoutDeadline = $shiftEndDateTime->copy()->addHours(3)
                ->timezone('Asia/Jakarta');

            if (Carbon::now()->isAfter($checkoutDeadline)) {
                $checkOutStatus = 'tidak_absen';
            } else {
                $checkOutStatus = 'belum_checkout';
            }
        }

        // Get validation data
        $validation = $presence->validation;
        $validationData = null;
        if ($validation) {
            $validationData = [
                'status' => $validation->validation_status,
                'face_confidence' => $validation->face_confidence,
                'face_confidence_level' => $validation->face_confidence_level,
                'gps_accuracy' => $validation->gps_accuracy,
                'location_source' => $validation->location_source,
                'security_flags' => $validation->security_flags,
                'retry_count' => $validation->retry_count,
                'can_retry' => $validation->canRetry(),
                'validated_at' => $validation->validated_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
            ];
        }

        // Format response dengan waktu lokal
        return [
            'id' => $presence->id,
            'store' => $presence->store ? $presence->store->nickname : null,
            'shift_store' => $presence->shiftStore ? $presence->shiftStore->name : null,
            'status' => $presence->status,
            'check_in' => $checkInDateTime->format('Y-m-d H:i:s'),
            'check_out' => $checkOutDateTime ? $checkOutDateTime->format('Y-m-d H:i:s') : null,
            'latitude_in' => $presence->latitude_in,
            'longitude_in' => $presence->longitude_in,
            'image_in' => $presence->image_in ? url('storage/' . $presence->image_in) : null,
            'latitude_out' => $presence->latitude_out,
            'longitude_out' => $presence->longitude_out,
            'image_out' => $presence->image_out ? url('storage/' . $presence->image_out) : null,
            'shift_start_time' => $shiftStartTime,
            'shift_end_time' => $shiftEndTime,
            'check_in_status' => $checkInStatus,
            'check_out_status' => $checkOutStatus,
            'late_minutes' => $lateMinutes,
            'shift_end_datetime' => $shiftEndDateTime ? $shiftEndDateTime->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') : null,
            'checkout_deadline' => isset($checkoutDeadline) ? $checkoutDeadline->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') : null,
            'validation' => $validationData,
        ];
    }

    public function checkIn(Request $request)
    {
        try {
            $user = Auth::user();
            $now = Carbon::now();

            // Apply face validation logic
            $this->applyFaceValidation($request, $user);

            // Cek apakah user sedang dalam masa cuti/izin
            $activeLeave = PermitEmployee::where('created_by_id', $user->id)
                ->where('status', PermitEmployee::STATUS_APPROVED)
                ->where(function ($query) use ($now) {
                    $query->whereDate('from_date', '<=', $now)
                        ->whereDate('until_date', '>=', $now);
                })
                ->orderBy('from_date', 'desc')
                ->first();

            if ($activeLeave) {
                // Pastikan tanggal hari ini termasuk dalam rentang cuti
                $currentDate = $now->format('Y-m-d');
                $fromDate = Carbon::parse($activeLeave->from_date)->format('Y-m-d');
                $untilDate = Carbon::parse($activeLeave->until_date)->format('Y-m-d');

                if ($currentDate >= $fromDate && $currentDate <= $untilDate) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Anda tidak dapat melakukan presensi karena sedang dalam masa ' .
                            PermitEmployee::getReasonText($activeLeave->reason),
                        'data' => [
                            'leave' => [
                                'reason' => $activeLeave->reason,
                                'reason_text' => PermitEmployee::getReasonText($activeLeave->reason),
                                'from_date' => $activeLeave->from_date,
                                'until_date' => $activeLeave->until_date,
                                'current_date' => $currentDate
                            ]
                        ]
                    ], 400);
                }
            }

            // Cek apakah sudah ada presensi hari ini
            $existingPresence = Presence::where('created_by_id', $user->id)
                ->whereDate('check_in', $now->toDateString())
                ->first();

            if ($existingPresence) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda sudah melakukan check-in hari ini'
                ], 400);
            }

            // Validasi input
            $validationRules = [
                'store_id' => 'required|exists:stores,id',
                'shift_store_id' => 'required|exists:shift_stores,id',
                'status' => 'required|in:1,2,3',
                'latitude_in' => 'required|numeric|between:-90,90',
                'longitude_in' => 'required|numeric|between:-180,180',
                'image_in' => 'required|image|mimes:jpeg,png,jpg',
            ];

            // Add face image validation if user has face registered
            $faceEncoding = FaceEncoding::getActiveEncodingForUser($user->id);
            if ($faceEncoding) {
                $validationRules['face_image'] = 'required|image|mimes:jpeg,png,jpg,gif|max:10240';
            }

            $request->validate($validationRules);

            // Cek jadwal shift
            $shiftStore = ShiftStore::findOrFail($request->shift_store_id);
            $shiftStartTime = Carbon::parse($now->format('Y-m-d') . ' ' . $shiftStore->shift_start_time);

            // Hitung selisih waktu dengan jadwal shift
            $hoursBeforeShift = $shiftStartTime->diffInHours($now, false);

            // Hanya cek jika mencoba check-in lebih dari 3 jam sebelum shift
            if ($hoursBeforeShift < -3) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Presensi hanya dapat dilakukan maksimal 3 jam sebelum shift dimulai',
                    'data' => [
                        'current_time' => $now->format('Y-m-d H:i:s'),
                        'shift_start' => $shiftStartTime->format('Y-m-d H:i:s'),
                        'hours_difference' => abs($hoursBeforeShift)
                    ]
                ], 400);
            }

            // Cari store terdekat yang sesuai dengan radius
            $nearbyStore = Store::where('id', $request->store_id)
                ->where('status', '<>', '8')
                ->first();

            if (!$nearbyStore) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Store tidak ditemukan'
                ], 400);
            }

            // Hitung jarak
            $distance = $this->calculateDistance(
                $request->latitude_in,
                $request->longitude_in,
                $nearbyStore->latitude,
                $nearbyStore->longitude
            );

            if ($distance > $nearbyStore->radius) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda harus berada dalam area store untuk melakukan check-in'
                ], 400);
            }

            // Upload dan simpan image
            $imagePath = null;
            if ($request->hasFile('image_in')) {
                $imagePath = $request->file('image_in')->store('presences/check-in', 'public');
            }

            // Buat presensi baru
            $presence = new Presence([
                'created_by_id' => $user->id,
                'store_id' => $request->store_id,
                'shift_store_id' => $request->shift_store_id,
                'status' => $request->status,
                'check_in' => $now,
                'latitude_in' => $request->latitude_in,
                'longitude_in' => $request->longitude_in,
                'image_in' => $imagePath,
            ]);

            $presence->save();

            // Create presence validation record
            $this->createPresenceValidation($presence, $request);

            return response()->json([
                'status' => 'success',
                'message' => 'Check-in berhasil',
                'data' => $this->formatPresence($presence)
            ], 201);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            // Re-throw HttpResponseException to maintain proper status codes
            throw $e;
        } catch (\Exception $e) {
            // Hapus file jika upload gagal
            if (isset($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkOut(Request $request)
    {
        try {
            $user = Auth::user();
            $now = Carbon::now();

            // Apply face validation logic
            $this->applyFaceValidation($request, $user);

            // Cari presensi yang belum checkout
            $presence = Presence::where('created_by_id', $user->id)
                ->whereNull('check_out')
                ->orderBy('check_in', 'desc')
                ->first();

            if (!$presence) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tidak ada presensi yang dapat di-checkout'
                ], 400);
            }

            // Cek apakah masih dalam batas waktu checkout (3 jam setelah shift berakhir)
            $shiftStore = $presence->shiftStore;
            if ($shiftStore) {
                $checkInDate = Carbon::parse($presence->check_in);
                $shiftEndDateTime = Carbon::parse($checkInDate->format('Y-m-d') . ' ' . $shiftStore->shift_end_time);

                // Jika shift berakhir di hari berikutnya
                if ($shiftStore->shift_end_time < $shiftStore->shift_start_time) {
                    $shiftEndDateTime->addDay();
                }

                // Tambah toleransi 3 jam
                $checkoutDeadline = $shiftEndDateTime->copy()->addHours(3);

                if ($now->isAfter($checkoutDeadline)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Batas waktu checkout telah berakhir',
                        'data' => [
                            'current_time' => $now->format('Y-m-d H:i:s'),
                            'shift_end' => $shiftEndDateTime->format('Y-m-d H:i:s'),
                            'checkout_deadline' => $checkoutDeadline->format('Y-m-d H:i:s')
                        ]
                    ], 400);
                }
            }

            // Validasi input
            $request->validate([
                'latitude_out' => 'required|numeric|between:-90,90',
                'longitude_out' => 'required|numeric|between:-180,180',
                'image_out' => 'required|image|mimes:jpeg,png,jpg',
            ]);

            // Cari store terdekat yang sesuai dengan radius
            $nearbyStore = Store::where('status', '<>', '8')
                ->get()
                ->filter(function ($store) use ($request) {
                    $distance = $this->calculateDistance(
                        $request->latitude_out,
                        $request->longitude_out,
                        $store->latitude,
                        $store->longitude
                    );
                    return $distance <= $store->radius;
                })
                ->first();

            if (!$nearbyStore) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda harus berada dalam area store untuk melakukan check-out'
                ], 400);
            }

            try {
                // Upload dan simpan image
                $imagePath = null;
                if ($request->hasFile('image_out')) {
                    $imagePath = $request->file('image_out')->store('presences/check-out', 'public');
                }

                // Update presensi
                $presence->check_out = $now;
                $presence->latitude_out = $request->latitude_out;
                $presence->longitude_out = $request->longitude_out;
                $presence->image_out = $imagePath;
                $presence->save();

                // Update presence validation for check-out
                $this->updatePresenceValidationForCheckout($presence, $request);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Check-out berhasil',
                    'data' => $this->formatPresence($presence)
                ]);
            } catch (\Exception $e) {
                // Hapus file jika upload gagal
                if (isset($imagePath)) {
                    Storage::disk('public')->delete($imagePath);
                }

                throw $e;
            }
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            // Re-throw HttpResponseException to maintain proper status codes
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    // Tambahkan method untuk menghitung jarak
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // Radius bumi dalam meter

        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1) * cos($lat2) *
            sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        return $distance; // Hasil dalam meter
    }

    public function getStores()
    {
        $stores = Store::where('status', '<>', '8')
            ->select('id', 'nickname', 'latitude', 'longitude', 'radius')
            ->get()
            ->map(function ($store) {
                return [
                    'id' => $store->id,
                    'nickname' => $store->nickname,
                    'latitude' => (string) $store->latitude,
                    'longitude' => (string) $store->longitude,
                    'radius' => (string) $store->radius
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $stores
        ]);
    }

    public function getShiftStores()
    {
        $shiftStores = ShiftStore::select('id', 'name', 'shift_start_time', 'shift_end_time')
            ->get();
        return response()->json([
            'status' => 'success',
            'data' => $shiftStores
        ]);
    }

    /**
     * Create presence validation record for check-in.
     */
    private function createPresenceValidation(Presence $presence, Request $request): void
    {
        $validationData = [
            'presence_id' => $presence->id,
            'gps_accuracy' => $request->input('gps_accuracy'),
            'location_source' => $request->input('location_source', 'GPS'),
            'validation_status' => PresenceValidation::STATUS_PENDING,
            'security_flags' => [],
        ];

        // Add face validation data if available
        if ($request->has('face_verification_result')) {
            $faceResult = $request->input('face_verification_result');
            $validationData['face_confidence'] = $faceResult['confidence'] ?? 0.0;
            
            if ($faceResult['success']) {
                $validationData['validation_status'] = PresenceValidation::STATUS_PASSED;
                $validationData['validated_at'] = now();
            } else {
                $validationData['validation_status'] = PresenceValidation::STATUS_FAILED;
                $validationData['security_flags'][] = 'face_verification_failed';
            }
        } elseif ($request->input('face_validation_skipped')) {
            $validationData['security_flags'][] = 'face_not_registered';
            $validationData['validation_status'] = PresenceValidation::STATUS_PASSED;
            $validationData['validated_at'] = now();
        }

        PresenceValidation::create($validationData);

        Log::info('Presence validation created', [
            'presence_id' => $presence->id,
            'user_id' => $presence->created_by_id,
            'validation_status' => $validationData['validation_status'],
            'face_confidence' => $validationData['face_confidence'] ?? null,
        ]);
    }

    /**
     * Update presence validation for check-out.
     */
    private function updatePresenceValidationForCheckout(Presence $presence, Request $request): void
    {
        $validation = $presence->validation;
        if (!$validation) {
            // Create validation if it doesn't exist
            $this->createPresenceValidation($presence, $request);
            return;
        }

        $updateData = [
            'gps_accuracy' => $request->input('gps_accuracy'),
            'location_source' => $request->input('location_source', 'GPS'),
        ];

        // Update face validation data if available
        if ($request->has('face_verification_result')) {
            $faceResult = $request->input('face_verification_result');
            $updateData['face_confidence'] = $faceResult['confidence'] ?? 0.0;
            
            if ($faceResult['success']) {
                $updateData['validation_status'] = PresenceValidation::STATUS_PASSED;
                $updateData['validated_at'] = now();
            } else {
                $updateData['validation_status'] = PresenceValidation::STATUS_FAILED;
                $securityFlags = $validation->security_flags ?? [];
                $securityFlags[] = 'checkout_face_verification_failed';
                $updateData['security_flags'] = $securityFlags;
            }
        }

        $validation->update($updateData);

        Log::info('Presence validation updated for checkout', [
            'presence_id' => $presence->id,
            'user_id' => $presence->created_by_id,
            'validation_status' => $updateData['validation_status'] ?? $validation->validation_status,
            'face_confidence' => $updateData['face_confidence'] ?? null,
        ]);
    }

    /**
     * Retry face verification for a presence.
     */
    public function retryFaceVerification(Request $request, $presenceId)
    {
        try {
            $user = Auth::user();
            $presence = Presence::where('id', $presenceId)
                ->where('created_by_id', $user->id)
                ->with('validation')
                ->first();

            if (!$presence) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Presence not found'
                ], 404);
            }

            $validation = $presence->validation;
            if (!$validation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No validation record found'
                ], 404);
            }

            if (!$validation->canRetry()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Maximum retry attempts exceeded',
                    'max_attempts' => PresenceValidation::MAX_RETRY_ATTEMPTS,
                    'current_attempts' => $validation->retry_count
                ], 400);
            }

            // Validate face image
            $request->validate([
                'face_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240',
            ]);

            // Perform face verification
            $faceImage = $request->file('face_image');
            $faceRecognitionService = app(\App\Services\FaceRecognitionService::class);
            $verificationResult = $faceRecognitionService->verifyFace($user, $faceImage);

            // Increment retry count
            $validation->incrementRetryCount();

            if ($verificationResult['success']) {
                $validation->update([
                    'face_confidence' => $verificationResult['confidence'],
                    'validation_status' => PresenceValidation::STATUS_PASSED,
                    'validated_at' => now(),
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Face verification successful',
                    'confidence' => $verificationResult['confidence'],
                    'retry_count' => $validation->retry_count,
                ]);
            } else {
                $validation->update([
                    'face_confidence' => $verificationResult['confidence'],
                    'validation_status' => $validation->canRetry() 
                        ? PresenceValidation::STATUS_RETRY_REQUIRED 
                        : PresenceValidation::STATUS_FAILED,
                ]);

                if (!$validation->canRetry()) {
                    $validation->addSecurityFlag('max_retry_attempts_exceeded');
                }

                return response()->json([
                    'status' => 'error',
                    'message' => $verificationResult['message'],
                    'confidence' => $verificationResult['confidence'],
                    'retry_count' => $validation->retry_count,
                    'can_retry' => $validation->canRetry(),
                    'max_attempts' => PresenceValidation::MAX_RETRY_ATTEMPTS,
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Face verification retry failed', [
                'presence_id' => $presenceId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Face verification retry failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply face validation logic to the request.
     */
    private function applyFaceValidation(Request $request, $user): void
    {
        // Skip validation if user doesn't have face registered
        $faceEncoding = FaceEncoding::getActiveEncodingForUser($user->id);
        if (!$faceEncoding) {
            // Add flag to request indicating face validation was skipped
            $request->merge(['face_validation_skipped' => true]);
            return;
        }

        // Check if face image is provided
        if (!$request->hasFile('face_image')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'status' => 'error',
                    'message' => 'Face verification required. Please provide face image.',
                    'error_code' => 'FACE_IMAGE_REQUIRED',
                ], 422)
            );
        }

        // Validate face image
        $validator = validator($request->all(), [
            'face_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240',
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'status' => 'error',
                    'message' => 'Invalid face image.',
                    'errors' => $validator->errors(),
                    'error_code' => 'INVALID_FACE_IMAGE',
                ], 422)
            );
        }

        // Perform face verification
        $faceImage = $request->file('face_image');
        $faceRecognitionService = app(\App\Services\FaceRecognitionService::class);
        $verificationResult = $faceRecognitionService->verifyFace($user, $faceImage);

        // Add face verification result to request
        $request->merge([
            'face_verification_result' => $verificationResult,
            'face_confidence' => $verificationResult['confidence'] ?? 0.0,
        ]);

        // Log face verification result
        if (!$verificationResult['success']) {
            Log::warning('Face verification failed during presence', [
                'user_id' => $user->id,
                'confidence' => $verificationResult['confidence'] ?? 0.0,
                'message' => $verificationResult['message'] ?? 'Unknown error',
            ]);
        } else {
            Log::info('Face verification successful during presence', [
                'user_id' => $user->id,
                'confidence' => $verificationResult['confidence'],
            ]);
        }
    }
}
