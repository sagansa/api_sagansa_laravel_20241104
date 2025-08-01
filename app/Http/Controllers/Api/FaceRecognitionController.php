<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FaceRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FaceRecognitionController extends Controller
{
    private FaceRecognitionService $faceRecognitionService;

    public function __construct(FaceRecognitionService $faceRecognitionService)
    {
        $this->faceRecognitionService = $faceRecognitionService;
    }

    /**
     * Register a face for the authenticated user.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid image file.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $imageFile = $request->file('image');

        $result = $this->faceRecognitionService->registerFace($user, $imageFile);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Verify a face against the authenticated user's registered face.
     */
    public function verify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid image file.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $imageFile = $request->file('image');

        $result = $this->faceRecognitionService->verifyFace($user, $imageFile);

        return response()->json($result, 200);
    }

    /**
     * Get face recognition status for the authenticated user.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $stats = $this->faceRecognitionService->getUserFaceStats($user);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Remove face registration for the authenticated user.
     */
    public function remove(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Deactivate all face encodings for the user
        \App\Models\FaceEncoding::deactivateUserEncodings($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Face registration removed successfully.',
        ]);
    }

    /**
     * Check if the face recognition service is available.
     */
    public function serviceHealth(): JsonResponse
    {
        $isAvailable = $this->faceRecognitionService->isServiceAvailable();

        return response()->json([
            'success' => true,
            'service_available' => $isAvailable,
            'message' => $isAvailable 
                ? 'Face recognition service is available.' 
                : 'Face recognition service is unavailable.',
        ]);
    }
}