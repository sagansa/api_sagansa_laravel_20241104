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
            'image_selfie' => 'required|image|mimes:jpeg,png,jpg',
            'left_hand' => 'required|image|mimes:jpeg,png,jpg',
            'right_hand' => 'required|image|mimes:jpeg,png,jpg',
        ]);

        $imageSelfiePath = null;
        $leftHandPath = null;
        $rightHandPath = null;

        try {
            $imageSelfiePath = $request->file('image_selfie')->store('images/Readiness', 'public');
            $leftHandPath = $request->file('left_hand')->store('images/Readiness', 'public');
            $rightHandPath = $request->file('right_hand')->store('images/Readiness', 'public');

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
            if ($imageSelfiePath) Storage::disk('public')->delete($imageSelfiePath);
            if ($leftHandPath) Storage::disk('public')->delete($leftHandPath);
            if ($rightHandPath) Storage::disk('public')->delete($rightHandPath);

            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    private function resolvePresenceUserId($authUser): int
    {
        $presenceUserId = DB::table('users')
            ->where('email', $authUser->email)
            ->value('id');
            
        return $presenceUserId ? (int) $presenceUserId : (int) $authUser->id;
    }
}
