<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Store;
use App\Models\ShiftStore;
use App\Models\FaceEncoding;
use App\Models\Presence;
use App\Models\PresenceValidation;
use App\Services\FaceRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Mockery;

class FaceEnabledPresenceTest extends TestCase
{
    protected $user;
    protected $store;
    protected $shiftStore;
    protected $faceRecognitionService;

    protected function setUp(): void
    {
        parent::setUp();

        // Use existing user from database
        $this->user = User::first();
        if (!$this->user) {
            $this->markTestSkipped('No users found in database');
        }

        // Use existing active store from database
        $this->store = Store::where('status', '<>', '8')->first();
        if (!$this->store) {
            $this->markTestSkipped('No active stores found in database');
        }

        // Use existing shift store from database
        $this->shiftStore = ShiftStore::first();
        if (!$this->shiftStore) {
            $this->markTestSkipped('No shift stores found in database');
        }

        // Mock face recognition service
        $this->faceRecognitionService = Mockery::mock(FaceRecognitionService::class);
        $this->app->instance(FaceRecognitionService::class, $this->faceRecognitionService);

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_check_in_without_face_registration_succeeds()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/check-in', [
                'store_id' => $this->store->id,
                'shift_store_id' => $this->shiftStore->id,
                'status' => 1,
                'latitude_in' => $this->store->latitude,
                'longitude_in' => $this->store->longitude,
                'image_in' => UploadedFile::fake()->image('checkin.jpg'),
            ]);

        if ($response->status() !== 201) {
            dump('Response status: ' . $response->status());
            dump('Response body: ' . $response->content());
        }
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'validation' => [
                    'status',
                    'security_flags',
                ]
            ]
        ]);

        // Check that presence validation was created
        $presence = Presence::where('created_by_id', $this->user->id)->first();
        $this->assertNotNull($presence);

        $validation = $presence->validation;
        $this->assertNotNull($validation);
        $this->assertEquals(PresenceValidation::STATUS_PASSED, $validation->validation_status);
        $this->assertContains('face_not_registered', $validation->security_flags);
    }

    public function test_check_in_with_face_registration_requires_face_image()
    {
        // Clean up any existing face encodings for this user
        FaceEncoding::where('user_id', $this->user->id)->delete();
        
        // Create face encoding for user
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => [0.1, 0.2, 0.3], // dummy encoding
            'encoding_version' => '1.0',
            'is_active' => true,
            'registered_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/check-in', [
                'store_id' => $this->store->id,
                'shift_store_id' => $this->shiftStore->id,
                'status' => 1,
                'latitude_in' => $this->store->latitude,
                'longitude_in' => $this->store->longitude,
                'image_in' => UploadedFile::fake()->image('checkin.jpg'),
                // Missing face_image
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Face verification required. Please provide face image.',
            'error_code' => 'FACE_IMAGE_REQUIRED',
        ]);
    }

    public function test_check_in_with_successful_face_verification()
    {
        // Clean up any existing face encodings for this user
        FaceEncoding::where('user_id', $this->user->id)->delete();
        
        // Create face encoding for user
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => [0.1, 0.2, 0.3], // dummy encoding
            'encoding_version' => '1.0',
            'is_active' => true,
            'registered_at' => now(),
        ]);

        // Mock successful face verification
        $this->faceRecognitionService
            ->shouldReceive('verifyFace')
            ->once()
            ->andReturn([
                'success' => true,
                'confidence' => 0.85,
                'message' => 'Face verification successful.',
            ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/check-in', [
                'store_id' => $this->store->id,
                'shift_store_id' => $this->shiftStore->id,
                'status' => 1,
                'latitude_in' => $this->store->latitude,
                'longitude_in' => $this->store->longitude,
                'image_in' => UploadedFile::fake()->image('checkin.jpg'),
                'face_image' => UploadedFile::fake()->image('face.jpg'),
            ]);

        $response->assertStatus(201);

        // Check validation record
        $presence = Presence::where('created_by_id', $this->user->id)->first();
        $validation = $presence->validation;
        
        $this->assertEquals(PresenceValidation::STATUS_PASSED, $validation->validation_status);
        $this->assertEquals(0.85, $validation->face_confidence);
        $this->assertNotNull($validation->validated_at);
    }

    public function test_check_in_with_failed_face_verification()
    {
        // Clean up any existing face encodings for this user
        FaceEncoding::where('user_id', $this->user->id)->delete();
        
        // Create face encoding for user
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => [0.1, 0.2, 0.3], // dummy encoding
            'encoding_version' => '1.0',
            'is_active' => true,
            'registered_at' => now(),
        ]);

        // Mock failed face verification
        $this->faceRecognitionService
            ->shouldReceive('verifyFace')
            ->once()
            ->andReturn([
                'success' => false,
                'confidence' => 0.3,
                'message' => 'Face verification failed.',
            ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/check-in', [
                'store_id' => $this->store->id,
                'shift_store_id' => $this->shiftStore->id,
                'status' => 1,
                'latitude_in' => $this->store->latitude,
                'longitude_in' => $this->store->longitude,
                'image_in' => UploadedFile::fake()->image('checkin.jpg'),
                'face_image' => UploadedFile::fake()->image('face.jpg'),
            ]);

        $response->assertStatus(201);

        // Check validation record shows failure
        $presence = Presence::where('created_by_id', $this->user->id)->first();
        $validation = $presence->validation;
        
        $this->assertEquals(PresenceValidation::STATUS_FAILED, $validation->validation_status);
        $this->assertEquals(0.3, $validation->face_confidence);
        $this->assertContains('face_verification_failed', $validation->security_flags);
    }

    public function test_face_verification_retry_mechanism()
    {
        // Create presence with failed validation
        $presence = Presence::create([
            'created_by_id' => $this->user->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => $this->store->latitude,
            'longitude_in' => $this->store->longitude,
        ]);

        $validation = PresenceValidation::create([
            'presence_id' => $presence->id,
            'validation_status' => PresenceValidation::STATUS_FAILED,
            'face_confidence' => 0.3,
            'retry_count' => 1,
        ]);

        // Mock successful retry
        $this->faceRecognitionService
            ->shouldReceive('verifyFace')
            ->once()
            ->andReturn([
                'success' => true,
                'confidence' => 0.82,
                'message' => 'Face verification successful.',
            ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/presence/{$presence->id}/retry-face", [
                'face_image' => UploadedFile::fake()->image('face_retry.jpg'),
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'confidence' => 0.82,
            'retry_count' => 2,
        ]);

        // Check validation was updated
        $validation->refresh();
        $this->assertEquals(PresenceValidation::STATUS_PASSED, $validation->validation_status);
        $this->assertEquals(0.82, $validation->face_confidence);
        $this->assertEquals(2, $validation->retry_count);
    }

    public function test_face_verification_retry_exceeds_max_attempts()
    {
        // Create presence with validation at max retry count
        $presence = Presence::create([
            'created_by_id' => $this->user->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => $this->store->latitude,
            'longitude_in' => $this->store->longitude,
        ]);

        $validation = PresenceValidation::create([
            'presence_id' => $presence->id,
            'validation_status' => PresenceValidation::STATUS_FAILED,
            'retry_count' => PresenceValidation::MAX_RETRY_ATTEMPTS,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/presence/{$presence->id}/retry-face", [
                'face_image' => UploadedFile::fake()->image('face_retry.jpg'),
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Maximum retry attempts exceeded',
        ]);
    }

    public function test_check_out_with_face_verification()
    {
        // Create existing presence
        $presence = Presence::create([
            'created_by_id' => $this->user->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => $this->store->latitude,
            'longitude_in' => $this->store->longitude,
            'check_out' => null,
        ]);

        // Clean up any existing face encodings for this user
        FaceEncoding::where('user_id', $this->user->id)->delete();
        
        // Create face encoding
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => [0.1, 0.2, 0.3], // dummy encoding
            'encoding_version' => '1.0',
            'is_active' => true,
            'registered_at' => now(),
        ]);

        // Mock successful face verification
        $this->faceRecognitionService
            ->shouldReceive('verifyFace')
            ->once()
            ->andReturn([
                'success' => true,
                'confidence' => 0.88,
                'message' => 'Face verification successful.',
            ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/check-out', [
                'latitude_out' => $this->store->latitude,
                'longitude_out' => $this->store->longitude,
                'image_out' => UploadedFile::fake()->image('checkout.jpg'),
                'face_image' => UploadedFile::fake()->image('face_checkout.jpg'),
            ]);

        $response->assertStatus(200);

        // Check validation was updated
        $presence->refresh();
        $validation = $presence->validation;
        
        if ($validation) {
            $this->assertEquals(PresenceValidation::STATUS_PASSED, $validation->validation_status);
            $this->assertEquals(0.88, $validation->face_confidence);
        }
    }

    public function test_presence_validation_model_methods()
    {
        // Create a test presence first
        $presence = Presence::create([
            'created_by_id' => $this->user->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => $this->store->latitude,
            'longitude_in' => $this->store->longitude,
        ]);

        $validation = PresenceValidation::create([
            'presence_id' => $presence->id,
            'face_confidence' => 0.75,
            'retry_count' => 1,
        ]);

        // Test confidence level methods
        $this->assertTrue($validation->hasSufficientFaceConfidence(0.6));
        $this->assertFalse($validation->hasSufficientFaceConfidence(0.8));

        // Test retry methods
        $this->assertTrue($validation->canRetry());
        
        $validation->incrementRetryCount();
        $this->assertEquals(2, $validation->retry_count);

        // Test status methods
        $validation->markAsPassed();
        $this->assertEquals(PresenceValidation::STATUS_PASSED, $validation->validation_status);
        $this->assertNotNull($validation->validated_at);

        $validation->markAsFailed(['test_flag']);
        $this->assertEquals(PresenceValidation::STATUS_FAILED, $validation->validation_status);
        $this->assertContains('test_flag', $validation->security_flags);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->user) {
            // Clean up presence validations
            PresenceValidation::whereHas('presence', function ($query) {
                $query->where('created_by_id', $this->user->id);
            })->delete();
            
            // Clean up presences
            Presence::where('created_by_id', $this->user->id)->delete();
            
            // Clean up face encodings
            FaceEncoding::where('user_id', $this->user->id)->delete();
        }
        
        Mockery::close();
        parent::tearDown();
    }
}