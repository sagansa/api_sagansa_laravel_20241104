<?php

namespace Tests\Feature\Api;

use App\Models\FaceEncoding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaceRecognitionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    public function test_register_face_success()
    {
        Sanctum::actingAs($this->user);

        Http::fake([
            'localhost:5000/generate_encoding' => Http::response([
                'success' => true,
                'encoding' => array_fill(0, 128, 0.5),
                'faces_detected' => 1,
            ], 200),
        ]);

        $imageFile = UploadedFile::fake()->image('face.jpg');

        $response = $this->postJson('/api/face/register', [
            'image' => $imageFile,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Face registered successfully.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'face_encoding_id',
            ]);

        $this->assertDatabaseHas('face_encodings', [
            'user_id' => $this->user->id,
            'is_active' => true,
        ]);
    }

    public function test_register_face_validation_error()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/face/register', [
            'image' => 'not-an-image',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid image file.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
            ]);
    }

    public function test_register_face_unauthenticated()
    {
        $imageFile = UploadedFile::fake()->image('face.jpg');

        $response = $this->postJson('/api/face/register', [
            'image' => $imageFile,
        ]);

        $response->assertStatus(401);
    }

    public function test_verify_face_success()
    {
        Sanctum::actingAs($this->user);

        // Create face encoding for user
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => array_fill(0, 128, 0.5),
            'is_active' => true,
            'registered_at' => now(),
        ]);

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

        $response = $this->postJson('/api/face/verify', [
            'image' => $imageFile,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Face verification successful.',
                'confidence' => 0.8,
            ]);
    }

    public function test_verify_face_no_registered_face()
    {
        Sanctum::actingAs($this->user);

        $imageFile = UploadedFile::fake()->image('face.jpg');

        $response = $this->postJson('/api/face/verify', [
            'image' => $imageFile,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => false,
                'confidence' => 0.0,
            ])
            ->assertJsonFragment([
                'message' => 'No face registered for this user. Please register your face first.',
            ]);
    }

    public function test_get_status_with_face()
    {
        Sanctum::actingAs($this->user);

        $registeredAt = now();
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => array_fill(0, 128, 0.5),
            'encoding_version' => '1.0',
            'is_active' => true,
            'registered_at' => $registeredAt,
        ]);

        $response = $this->getJson('/api/face/status');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'has_face_registered' => true,
                    'registration_date' => $registeredAt->toDateTimeString(),
                    'encoding_version' => '1.0',
                ],
            ]);
    }

    public function test_get_status_without_face()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/face/status');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'has_face_registered' => false,
                    'registration_date' => null,
                    'encoding_version' => null,
                ],
            ]);
    }

    public function test_remove_face()
    {
        Sanctum::actingAs($this->user);

        // Create face encoding for user
        FaceEncoding::create([
            'user_id' => $this->user->id,
            'encoding' => array_fill(0, 128, 0.5),
            'is_active' => true,
            'registered_at' => now(),
        ]);

        $response = $this->deleteJson('/api/face/remove');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Face registration removed successfully.',
            ]);

        // Verify face encoding is deactivated
        $this->assertDatabaseHas('face_encodings', [
            'user_id' => $this->user->id,
            'is_active' => false,
        ]);
    }

    public function test_service_health_available()
    {
        Sanctum::actingAs($this->user);

        Http::fake([
            'localhost:5000/health' => Http::response([
                'status' => 'healthy',
            ], 200),
        ]);

        $response = $this->getJson('/api/face/service-health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'service_available' => true,
                'message' => 'Face recognition service is available.',
            ]);
    }

    public function test_service_health_unavailable()
    {
        Sanctum::actingAs($this->user);

        Http::fake([
            'localhost:5000/health' => Http::response([], 500),
        ]);

        $response = $this->getJson('/api/face/service-health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'service_available' => false,
                'message' => 'Face recognition service is unavailable.',
            ]);
    }

    public function test_persistent_routes_work()
    {
        Sanctum::actingAs($this->user);

        Http::fake([
            'localhost:5000/health' => Http::response([
                'status' => 'healthy',
            ], 200),
        ]);

        $response = $this->getJson('/api/persistent/face/service-health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'service_available' => true,
            ]);
    }
}