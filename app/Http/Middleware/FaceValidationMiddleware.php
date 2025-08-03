<?php

namespace App\Http\Middleware;

use App\Models\FaceEncoding;
use App\Services\FaceRecognitionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class FaceValidationMiddleware
{
    private FaceRecognitionService $faceRecognitionService;

    public function __construct(FaceRecognitionService $faceRecognitionService)
    {
        $this->faceRecognitionService = $faceRecognitionService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip validation if user doesn't have face registered
        $faceEncoding = FaceEncoding::getActiveEncodingForUser($user->id);
        if (!$faceEncoding) {
            // Add flag to request indicating face validation was skipped
            $request->merge(['face_validation_skipped' => true]);
            return $next($request);
        }

        // Check if face image is provided
        if (!$request->hasFile('face_image')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Face verification required. Please provide face image.',
                'error_code' => 'FACE_IMAGE_REQUIRED',
            ], 422);
        }

        // Validate face image
        $validator = validator($request->all(), [
            'face_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid face image.',
                'errors' => $validator->errors(),
                'error_code' => 'INVALID_FACE_IMAGE',
            ], 422);
        }

        // Perform face verification
        $faceImage = $request->file('face_image');
        $verificationResult = $this->faceRecognitionService->verifyFace($user, $faceImage);

        // Add face verification result to request
        $request->merge([
            'face_verification_result' => $verificationResult,
            'face_confidence' => $verificationResult['confidence'] ?? 0.0,
        ]);

        // Check if face verification passed
        if (!$verificationResult['success']) {
            Log::warning('Face verification failed during presence', [
                'user_id' => $user->id,
                'confidence' => $verificationResult['confidence'] ?? 0.0,
                'message' => $verificationResult['message'] ?? 'Unknown error',
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $verificationResult['message'] ?? 'Face verification failed.',
                'confidence' => $verificationResult['confidence'] ?? 0.0,
                'error_code' => 'FACE_VERIFICATION_FAILED',
                'can_retry' => true,
            ], 403);
        }

        Log::info('Face verification successful during presence', [
            'user_id' => $user->id,
            'confidence' => $verificationResult['confidence'],
        ]);

        return $next($request);
    }
}
