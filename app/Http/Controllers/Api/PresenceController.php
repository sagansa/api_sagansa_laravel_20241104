<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Presence;
use App\Models\Store;
use App\Models\ShiftStore;
use App\Models\PermitEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PresenceController extends Controller
{
    public function getUserPresence(Request $request)
    {
        $user = Auth::user();
        $presenceUserId = $this->resolvePresenceUserId($user);
        $now = Carbon::now();
        $today = Carbon::today();
        $toleranceEnd = $today->copy()->addHours(3); // Toleransi sampai jam 3 pagi

        // Jika masih dalam rentang toleransi (sebelum jam 3 pagi)
        if ($now->lt($toleranceEnd)) {
            $yesterday = Carbon::yesterday();
            $todayPresence = Presence::with(['store', 'shiftStore'])
                ->where('created_by_id', $presenceUserId)
                ->whereDate('check_in', $yesterday)
                ->whereNull('check_out')
                ->first();

            // Jika tidak ada presensi hari kemarin yang belum checkout, cek hari ini
            if (!$todayPresence) {
                $todayPresence = Presence::with(['store', 'shiftStore'])
                    ->where('created_by_id', $presenceUserId)
                    ->whereDate('check_in', $today)
                    ->first();
            }
        } else {
            // Di luar toleransi, ambil presensi hari ini
            $todayPresence = Presence::with(['store', 'shiftStore'])
                ->where('created_by_id', $presenceUserId)
                ->whereDate('check_in', $today)
                ->first();
        }

        // Ambil presensi sebelumnya
        $previousPresences = Presence::with(['store', 'shiftStore'])
            ->where('created_by_id', $presenceUserId)
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
            'image_in' => $presence->image_in ? route('media.show', ['path' => $presence->image_in]) : null,
            'latitude_out' => $presence->latitude_out,
            'longitude_out' => $presence->longitude_out,
            'image_out' => $presence->image_out ? route('media.show', ['path' => $presence->image_out]) : null,
            'shift_start_time' => $shiftStartTime,
            'shift_end_time' => $shiftEndTime,
            'check_in_status' => $checkInStatus,
            'check_out_status' => $checkOutStatus,
            'late_minutes' => $lateMinutes,
            'shift_end_datetime' => $shiftEndDateTime ? $shiftEndDateTime->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') : null,
            'checkout_deadline' => isset($checkoutDeadline) ? $checkoutDeadline->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') : null,
        ];
    }

    public function checkIn(Request $request)
    {
        try {
            $user = Auth::user();
            $presenceUserId = $this->resolvePresenceUserId($user);
            $now = Carbon::now();

            // Cek apakah user sedang dalam masa cuti/izin
            $activeLeave = PermitEmployee::where('created_by_id', $presenceUserId)
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
            $existingPresence = Presence::where('created_by_id', $presenceUserId)
                ->whereDate('check_in', $now->toDateString())
                ->first();

            if ($existingPresence) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda sudah melakukan check-in hari ini'
                ], 400);
            }

            // Validasi Kesiapan (Readiness) khusus hari Jumat
            if ($now->isFriday()) {
                $hasReadiness = \App\Models\Readiness::where('created_by_id', $presenceUserId)
                    ->whereDate('created_at', $now->toDateString())
                    ->exists();

                if (!$hasReadiness) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Anda wajib mengisi form Kesiapan Diri pada hari Jumat sebelum Check-in.',
                        'error_code' => 'READINESS_REQUIRED'
                    ], 400);
                }
            }

            // Validasi Kebersihan Toko - setiap hari
            $hasHygiene = \App\Models\Hygiene::where('created_by_id', $presenceUserId)
                ->whereDate('created_at', $now->toDateString())
                ->exists();

            if (!$hasHygiene) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda wajib mengisi form Kebersihan Toko sebelum Check-in.',
                    'error_code' => 'HYGIENE_REQUIRED'
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
                'created_by_id' => $presenceUserId,
                'store_id' => $request->store_id,
                'shift_store_id' => $request->shift_store_id,
                'status' => $request->status,
                'check_in' => $now,
                'latitude_in' => $request->latitude_in,
                'longitude_in' => $request->longitude_in,
                'image_in' => $imagePath,
            ]);

            $presence->save();

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
            $presenceUserId = $this->resolvePresenceUserId($user);
            $now = Carbon::now();

            // Cari presensi yang belum checkout
            $presence = Presence::where('created_by_id', $presenceUserId)
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
                'daily_salary_amount' => 'required|numeric|min:0',
                'daily_salary_payment_type_id' => 'required|exists:payment_types,id',
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

                // Buat data Daily Salary
                \App\Models\DailySalary::create([
                    'store_id' => $presence->store_id,
                    'shift_store_id' => $presence->shift_store_id,
                    'date' => $now->toDateString(),
                    'amount' => $request->daily_salary_amount,
                    'payment_type_id' => $request->daily_salary_payment_type_id,
                    'status' => 1,
                    'presence_id' => $presence->id,
                    'created_by_id' => $presenceUserId,
                ]);

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

    private function resolvePresenceUserId($authUser): int
    {
        $presenceUserId = DB::table('users')
            ->where('email', $authUser->email)
            ->value('id');

        if ($presenceUserId) {
            return (int) $presenceUserId;
        }

        $existingIdOwner = DB::table('users')
            ->where('id', $authUser->id)
            ->first(['id', 'email']);

        if (!$existingIdOwner) {
            DB::table('users')->insert([
                'id' => $authUser->id,
                'name' => $authUser->name,
                'email' => $authUser->email,
                'password' => $authUser->password,
                'email_verified_at' => $authUser->email_verified_at,
                'remember_token' => $authUser->remember_token,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Presence user mapping created from auth user', [
                'auth_user_id' => $authUser->id,
                'auth_user_email' => $authUser->email,
            ]);

            return (int) $authUser->id;
        }

        Log::error('Presence user mapping not found and auth id is already used', [
            'auth_user_id' => $authUser->id,
            'auth_user_email' => $authUser->email,
            'existing_user_id' => $existingIdOwner->id,
            'existing_user_email' => $existingIdOwner->email,
        ]);

        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'User presensi tidak dapat disinkronkan otomatis karena ID sudah digunakan. Hubungi administrator.',
        ], 422));
    }

    public function getStores(Request $request)
    {
        $stores = Store::where('status', '<>', '8')
            ->select('id', 'nickname', 'latitude', 'longitude', 'radius', 'daily_salary_amount')
            ->get()
            ->map(function ($store) {
                return [
                    'id' => $store->id,
                    'nickname' => $store->nickname,
                    'latitude' => (string) $store->latitude,
                    'longitude' => (string) $store->longitude,
                    'radius' => (string) $store->radius,
                    'daily_salary_amount' => (string) ($store->daily_salary_amount ?? 50000)
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $stores
        ]);
    }

    public function getShiftStores()
    {
        $shiftStores = ShiftStore::select('id', 'name', 'shift_start_time', 'shift_end_time')
            ->get();
        return response()->json([
            'success' => true,
            'data' => $shiftStores
        ]);
    }

}
