<?php

namespace App\Services;

use App\Models\FaceEncoding;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FaceRecognitionService
{
    private string $pythonServiceUrl;
    private float $confidenceThreshold;
    private float $highConfidenceThreshold;
    private float $lowConfidenceThreshold;

    public function __construct()
    {
        $this->pythonServiceUrl = config('services.face_recognition.url', 'http://localhost:5000');
        $this->confidenceThreshold = config('services.face_recognition.confidence_threshold', 0.6);
        $this->highConfidenceThreshold = config('services.face_recognition.high_confidence_threshold', 0.8);
        $this->lowConfidenceThreshold = config('services.face_recognition.low_confidence_threshold', 0.4);
    }

    /**
     * Register a new face encoding for a user.
     */
    public function registerFace(User $user, UploadedFile $imageFile): array
    {
        try {
            // Store the image temporarily
            $imagePath = $imageFile->store('temp/face_registration', 'local');
            $fullPath = Storage::path($imagePath);

            // Generate face encoding using Python service
            $encoding = $this->generateFaceEncoding($fullPath);

            if (empty($encoding)) {
                return [
                    'success' => false,
                    'message' => 'No face detected in the image. Please ensure your face is clearly visible.',
                ];
            }

            // Deactivate existing face encodings
            FaceEncoding::deactivateUserEncodings($user->id);

            // Store new face encoding
            $faceEncoding = FaceEncoding::create([
                'user_id' => $user->id,
                'encoding' => $encoding,
                'encoding_version' => '1.0',
                'is_active' => true,
                'registered_at' => now(),
            ]);

            // Clean up temporary file
            Storage::delete($imagePath);

            return [
                'success' => true,
                'message' => 'Face registered successfully.',
                'face_encoding_id' => $faceEncoding->id,
            ];

        } catch (\Exception $e) {
            Log::error('Face registration failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Face registration failed. Please try again.',
            ];
        }
    }

    /**
     * Verify a face against a user's registered face encoding.
     */
    public function verifyFace(User $user, UploadedFile $imageFile): array
    {
        try {
            // Get user's active face encoding
            $faceEncoding = FaceEncoding::getActiveEncodingForUser($user->id);

            if (!$faceEncoding) {
                return [
                    'success' => false,
                    'message' => 'No face registered for this user. Please register your face first.',
                    'confidence' => 0.0,
                ];
            }

            // Store the image temporarily
            $imagePath = $imageFile->store('temp/face_verification', 'local');
            $fullPath = Storage::path($imagePath);

            // Generate face encoding for verification image
            $verificationEncoding = $this->generateFaceEncoding($fullPath);

            if (empty($verificationEncoding)) {
                Storage::delete($imagePath);
                return [
                    'success' => false,
                    'message' => 'No face detected in the image. Please ensure your face is clearly visible.',
                    'confidence' => 0.0,
                ];
            }

            // Compare face encodings
            $confidence = $this->compareFaceEncodings(
                $faceEncoding->encoding,
                $verificationEncoding
            );

            // Clean up temporary file
            Storage::delete($imagePath);

            $isMatch = $confidence >= $this->confidenceThreshold;
            $confidenceLevel = $this->getConfidenceLevel($confidence);

            $message = $this->getVerificationMessage($confidence, $isMatch);

            return [
                'success' => $isMatch,
                'message' => $message,
                'confidence' => $confidence,
                'confidence_level' => $confidenceLevel,
                'threshold_met' => $isMatch,
                'requires_review' => $confidence < $this->lowConfidenceThreshold,
            ];

        } catch (\Exception $e) {
            Log::error('Face verification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Face verification failed. Please try again.',
                'confidence' => 0.0,
            ];
        }
    }

    /**
     * Generate face encoding using Python microservice.
     */
    private function generateFaceEncoding(string $imagePath): array
    {
        try {
            $response = Http::timeout(30)
                ->attach('image', file_get_contents($imagePath), 'image.jpg')
                ->post($this->pythonServiceUrl . '/generate_encoding');

            if ($response->successful()) {
                $data = $response->json();
                return $data['encoding'] ?? [];
            }

            Log::error('Python service error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];

        } catch (\Exception $e) {
            Log::error('Failed to generate face encoding', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Compare two face encodings using Python microservice.
     */
    private function compareFaceEncodings(array $encoding1, array $encoding2): float
    {
        try {
            $response = Http::timeout(30)
                ->post($this->pythonServiceUrl . '/compare_encodings', [
                    'encoding1' => $encoding1,
                    'encoding2' => $encoding2,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['confidence'] ?? 0.0;
            }

            Log::error('Python service comparison error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return 0.0;

        } catch (\Exception $e) {
            Log::error('Failed to compare face encodings', [
                'error' => $e->getMessage(),
            ]);

            return 0.0;
        }
    }

    /**
     * Check if Python microservice is available.
     */
    public function isServiceAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->pythonServiceUrl . '/health');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get face recognition statistics for a user.
     */
    public function getUserFaceStats(User $user): array
    {
        $faceEncoding = FaceEncoding::getActiveEncodingForUser($user->id);

        return [
            'has_face_registered' => !is_null($faceEncoding),
            'registration_date' => $faceEncoding?->registered_at?->toDateTimeString(),
            'encoding_version' => $faceEncoding?->encoding_version,
        ];
    }

    /**
     * Get confidence level description based on confidence score.
     */
    private function getConfidenceLevel(float $confidence): string
    {
        if ($confidence >= $this->highConfidenceThreshold) {
            return 'high';
        } elseif ($confidence >= $this->confidenceThreshold) {
            return 'medium';
        } elseif ($confidence >= $this->lowConfidenceThreshold) {
            return 'low';
        }

        return 'very_low';
    }

    /**
     * Get verification message based on confidence score.
     */
    private function getVerificationMessage(float $confidence, bool $isMatch): string
    {
        if ($isMatch) {
            if ($confidence >= $this->highConfidenceThreshold) {
                return 'Face verification successful with high confidence.';
            } else {
                return 'Face verification successful.';
            }
        } else {
            if ($confidence >= $this->lowConfidenceThreshold) {
                return 'Face verification failed. The face does not match closely enough. Please try again.';
            } else {
                return 'Face verification failed with low confidence. Please ensure good lighting and face the camera directly.';
            }
        }
    }

    /**
     * Get confidence thresholds for validation.
     */
    public function getConfidenceThresholds(): array
    {
        return [
            'minimum' => $this->confidenceThreshold,
            'high' => $this->highConfidenceThreshold,
            'low' => $this->lowConfidenceThreshold,
        ];
    }
}