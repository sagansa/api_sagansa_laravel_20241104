<?php

namespace Tests\Unit;

use App\Models\FaceEncoding;
use App\Models\User;
use App\Services\FaceRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FaceRecognitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FaceRecognitionService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new FaceRecognitionService();
        $this->user = User::factory()->create();
        
        Storage::fake('local');
    }

    public function test_register_face_success()
    {
        // Mock successful Python service response
        Http::fake([
            'localhost:5000/generate_encoding' => Http::response([
                'success' => true,
                'encoding' => array_fill(0, 128, 0.5),
                'faces_detected' => 1,
            ], 200),
        ]);

        $imageFile = UploadedFile::fake()->image('face.jpg');
        
        $result = $this->service->registerFace($this->user, $imageFile);

        $this->assertTrue($result['success']);
        $this->assertEquals('Face registered successfully.', $result['message']);
        $this->assertArrayHasKey('face_encoding_id', $result);

        // Verify face encoding was stored
        $this->assertDatabaseHas('face_encodings', [
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);
    }

    public function test_register_face_no_face_detected()
    {
        // Mock Python service response with no face detected
        Http::fake([
            'localhost:5000/generate_encoding' => Http::response([
                'error' => 'No face detected in image',
                'encoding' => [],
            ], 200),
        ]);

        $imageFile = UploadedFile::fake()->image('no-face.jpg');
        
        $result = $this->service->registerFace($this->user, $imageFile);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No face detected', $result['message']);

        // Verify no face encoding was stored
        $this->assertDatabaseMissing('face_encodings', [
            'user_id' => $this->user->id,
        ]);
    }

    public function test_register_face_deactivates_existing_encodings()
    {
        // Create existing face encoding
        $existingEncoding = FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => array_fill(0, 128, 0.3),
            'is_active' => true,
            'registered_at' => now(),
        ]);

        // Mock successful Python service response
        Http::fake([
            'localhost:5000/generate_encoding' => Http::response([
                'success' => true,
                'encoding' => array_fill(0, 128, 0.5),
                'faces_detected' => 1,
            ], 200),
        ]);

        $imageFile = UploadedFile::fake()->image('face.jpg');
        
        $result = $this->service->registerFace($this->user, $imageFile);

        $this->assertTrue($result['success']);

        // Verify old encoding is deactivated
        $existingEncoding->refresh();
        $this->assertFalse($existingEncoding->is_active);

        // Verify new encoding is active
        $newEncoding = FaceEncoding::where('user_id', $this->user->id)
            ->where('is_active', true)
            ->first();
        $this->assertNotNull($newEncoding);
        $this->assertNotEquals($existingEncoding->id, $newEncoding->id);
    }

    public function test_verify_face_success()
    {
        // Create face encoding for user
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => array_fill(0, 128, 0.5),
            'is_active' => true,
            'registered_at' => now(),
        ]);

        // Mock Python service responses
        Http::fake([
            'localhost:5000/generate_encoding' => Http::response([
                'success' => true,
                'encoding' => array_fill(0, 128, 0.5),
                'faces_detected' => 1,
            ], 200),
            'localhost:5000/compare_encodings' => Http::response([
                'success' => true,
                'confidence' => 0.8,
                'distance' => 0.2,
                'is_match' => true,
            ], 200),
        ]);

        $imageFile = UploadedFile::fake()->image('face.jpg');
        
        $result = $this->service->verifyFace($this->user, $imageFile);

        $this->assertTrue($result['success']);
        $this->assertEquals('Face verification successful.', $result['message']);
        $this->assertEquals(0.8, $result['confidence']);
    }

    public function test_verify_face_no_registered_face()
    {
        $imageFile = UploadedFile::fake()->image('face.jpg');
        
        $result = $this->service->verifyFace($this->user, $imageFile);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No face registered', $result['message']);
        $this->assertEquals(0.0, $result['confidence']);
    }

    public function test_verify_face_low_confidence()
    {
        // Create face encoding for user
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => array_fill(0, 128, 0.5),
            'is_active' => true,
            'registered_at' => now(),
        ]);

        // Mock Python service responses with low confidence
        Http::fake([
            'localhost:5000/generate_encoding' => Http::response([
                'success' => true,
                'encoding' => array_fill(0, 128, 0.2),
                'faces_detected' => 1,
            ], 200),
            'localhost:5000/compare_encodings' => Http::response([
                'success' => true,
                'confidence' => 0.3,
                'distance' => 0.7,
                'is_match' => false,
            ], 200),
        ]);

        $imageFile = UploadedFile::fake()->image('face.jpg');
        
        $result = $this->service->verifyFace($this->user, $imageFile);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Face verification failed', $result['message']);
        $this->assertEquals(0.3, $result['confidence']);
    }

    public function test_is_service_available_success()
    {
        Http::fake([
            'localhost:5000/health' => Http::response([
                'status' => 'healthy',
            ], 200),
        ]);

        $result = $this->service->isServiceAvailable();

        $this->assertTrue($result);
    }

    public function test_is_service_available_failure()
    {
        Http::fake([
            'localhost:5000/health' => Http::response([], 500),
        ]);

        $result = $this->service->isServiceAvailable();

        $this->assertFalse($result);
    }

    public function test_get_user_face_stats_with_face()
    {
        $registeredAt = now();
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => array_fill(0, 128, 0.5),
            'encoding_version' => '1.0',
            'is_active' => true,
            'registered_at' => $registeredAt,
        ]);

        $stats = $this->service->getUserFaceStats($this->user);

        $this->assertTrue($stats['has_face_registered']);
        $this->assertEquals($registeredAt->toDateTimeString(), $stats['registration_date']);
        $this->assertEquals('1.0', $stats['encoding_version']);
    }

    public function test_get_user_face_stats_without_face()
    {
        $stats = $this->service->getUserFaceStats($this->user);

        $this->assertFalse($stats['has_face_registered']);
        $this->assertNull($stats['registration_date']);
        $this->assertNull($stats['encoding_version']);
    }

    public function test_python_service_error_handling()
    {
        // Mock Python service error
        Http::fake([
            'localhost:5000/generate_encoding' => Http::response([], 500),
        ]);

        $imageFile = UploadedFile::fake()->image('face.jpg');
        
        $result = $this->service->registerFace($this->user, $imageFile);

        $this->assertFalse($result['success']);
        // When Python service fails, it returns empty encoding which triggers "No face detected" message
        $this->assertStringContainsString('No face detected', $result['message']);
    }
}