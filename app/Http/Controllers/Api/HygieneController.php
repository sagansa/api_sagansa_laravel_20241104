<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hygiene;
use App\Models\HygieneOfRoom;
use App\Models\Room;
use App\Models\Presence;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class HygieneController extends Controller
{
    public function rooms()
    {
        $rooms = Room::where('is_active', 1)
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'is_active']);

        return response()->json([
            'status' => 'success',
            'data' => $rooms,
        ]);
    }

    public function todayStatus(Request $request)
    {
        $today = Carbon::today();
        $storeId = $request->query('store_id');

        if ($storeId) {
            $existing = Hygiene::where('store_id', $storeId)
                ->whereDate('created_at', $today)
                ->exists();
        } else {
            $userId = $request->user()->id;
            $existing = Hygiene::where('created_by_id', $userId)
                ->whereDate('created_at', $today)
                ->exists();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'has_submitted_today' => $existing,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $hygienes = Hygiene::with([
            'store:id,nickname',
            'hygieneOfRooms.room:id,name',
            'createdBy:id,name',
            'approvedBy:id,name',
        ])
            ->where('created_by_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $hygienes,
        ]);
    }

    public function show(Request $request, $id)
    {
        $hygiene = Hygiene::with([
            'store:id,nickname',
            'hygieneOfRooms.room:id,name',
            'createdBy:id,name',
            'approvedBy:id,name',
        ])->findOrFail($id);

        if ($hygiene->created_by_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda tidak memiliki akses ke data ini',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $hygiene,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;
        $today = Carbon::today();

        $existing = Hygiene::where('created_by_id', $userId)
            ->whereDate('created_at', $today)
            ->exists();

        if ($existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda sudah mengirim laporan kebersihan hari ini',
            ], 400);
        }

        $storeId = $request->input('store_id')
            ?? $this->userTodayStoreId($user);

        if (!$storeId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Store tidak ditemukan. Pastikan Anda sudah check-in hari ini atau kirim store_id.',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'rooms' => 'required|array|min:1',
            'rooms.*.room_id' => 'required|exists:rooms,id',
            'rooms.*.condition' => 'nullable|integer|in:1,2,3',
            'rooms.*.notes' => 'nullable|string|max:500',
            'rooms.*.image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $hygiene = Hygiene::create([
            'store_id' => $storeId,
            'status' => 1,
            'created_by_id' => $userId,
        ]);

        $uploadedRooms = [];

        foreach ($request->input('rooms', []) as $i => $roomData) {
            $imagePath = $roomData['image'] ?? null;

            $hygieneOfRoom = HygieneOfRoom::create([
                'hygiene_id' => $hygiene->id,
                'room_id' => $roomData['room_id'],
                'image' => $imagePath,
                'condition' => $roomData['condition'] ?? null,
                'notes' => $roomData['notes'] ?? null,
            ]);

            $uploadedRooms[] = $hygieneOfRoom;
        }

        $hygiene->load([
            'store:id,nickname',
            'hygieneOfRooms.room:id,name',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Laporan kebersihan berhasil dikirim',
            'data' => $hygiene,
        ], 201);
    }

    public function updateRoom(Request $request, $id)
    {
        $hygieneOfRoom = HygieneOfRoom::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'condition' => 'nullable|integer|in:1,2,3',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $hygieneOfRoom->update([
            'condition' => $request->input('condition', $hygieneOfRoom->condition),
            'notes' => $request->input('notes', $hygieneOfRoom->notes),
        ]);

        $hygieneOfRoom->load('room:id,name');

        return response()->json([
            'status' => 'success',
            'message' => 'Penilaian kebersihan berhasil diperbarui',
            'data' => $hygieneOfRoom,
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
