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

class FaceRecognitionIntegrationTest extends TestCase
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

    public function test_complete_face_recognition_integration_flow()
    {
        // Clean up any existing data for this user
        FaceEncoding::where('user_id', $this->user->id)->delete();
        PresenceValidation::whereHas('presence', function ($query) {
            $query->where('created_by_id', $this->user->id);
        })->delete();
        Presence::where('created_by_id', $this->user->id)->delete();

        // Step 1: Create face encoding for user
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => [0.1, 0.2, 0.3], // dummy encoding
            'encoding_version' => '1.0',
            'is_active' => true,
            'registered_at' => now(),
        ]);

        // Step 2: Mock successful face verification
        $this->faceRecognitionService
            ->shouldReceive('verifyFace')
            ->once()
            ->andReturn([
                'success' => true,
                'confidence' => 0.85,
                'message' => 'Face verification successful.',
            ]);

        // Step 3: Perform check-in with face verification
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

        // Step 4: Verify check-in was successful
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'validation' => [
                    'status',
                    'face_confidence',
                    'face_confidence_level',
                ]
            ]
        ]);

        // Step 5: Verify presence and validation records were created correctly
        $presence = Presence::where('created_by_id', $this->user->id)->first();
        $this->assertNotNull($presence);

        $validation = $presence->validation;
        $this->assertNotNull($validation);
        $this->assertEquals(PresenceValidation::STATUS_PASSED, $validation->validation_status);
        $this->assertEquals(0.85, $validation->face_confidence);
        $this->assertNotNull($validation->validated_at);

        // Step 6: Test face verification retry mechanism
        // Create another presence with failed validation
        $presence2 = Presence::create([
            'created_by_id' => $this->user->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => $this->store->latitude,
            'longitude_in' => $this->store->longitude,
        ]);

        $validation2 = PresenceValidation::create([
            'presence_id' => $presence2->id,
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

        // Step 7: Test retry endpoint
        $retryResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/presence/{$presence2->id}/retry-face", [
                'face_image' => UploadedFile::fake()->image('face_retry.jpg'),
            ]);

        $retryResponse->assertStatus(200);
        $retryResponse->assertJson([
            'status' => 'success',
            'confidence' => 0.82,
            'retry_count' => 2,
        ]);

        // Step 8: Verify validation was updated
        $validation2->refresh();
        $this->assertEquals(PresenceValidation::STATUS_PASSED, $validation2->validation_status);
        $this->assertEquals(0.82, $validation2->face_confidence);
        $this->assertEquals(2, $validation2->retry_count);

        // Step 9: Test admin dashboard endpoints
        $statsResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/admin/face-recognition-stats');

        $statsResponse->assertStatus(200);
        $statsResponse->assertJsonStructure([
            'status',
            'data' => [
                'overall_stats' => [
                    'total_attempts',
                    'successful_attempts',
                    'failed_attempts',
                    'success_rate',
                    'average_confidence',
                ],
                'confidence_distribution',
                'daily_stats',
            ],
        ]);

        $this->assertTrue(true, 'Face recognition integration test completed successfully');
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