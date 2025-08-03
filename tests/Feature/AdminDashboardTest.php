<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Store;
use App\Models\ShiftStore;
use App\Models\FaceEncoding;
use App\Models\Presence;
use App\Models\PresenceValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class AdminDashboardTest extends TestCase
{
    protected $adminUser;
    protected $regularUser;
    protected $store;
    protected $shiftStore;

    protected function setUp(): void
    {
        parent::setUp();

        // Use existing users from database
        $users = User::take(2)->get();
        if ($users->count() < 2) {
            $this->markTestSkipped('Need at least 2 users in database');
        }
        
        $this->adminUser = $users->first();
        $this->regularUser = $users->last();

        // Use existing store and shift store
        $this->store = Store::first();
        $this->shiftStore = ShiftStore::first();
        
        if (!$this->store || !$this->shiftStore) {
            $this->markTestSkipped('Need store and shift store data in database');
        }
    }

    public function test_get_face_recognition_stats()
    {
        // Create presences with validations
        $presence1 = Presence::create([
            'created_by_id' => $this->regularUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => $this->store->latitude,
            'longitude_in' => $this->store->longitude,
        ]);

        $presence2 = Presence::create([
            'created_by_id' => $this->regularUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => $this->store->latitude,
            'longitude_in' => $this->store->longitude,
        ]);

        // Create validations with different statuses and confidence levels
        PresenceValidation::create([
            'presence_id' => $presence1->id,
            'face_confidence' => 0.85,
            'validation_status' => PresenceValidation::STATUS_PASSED,
            'validated_at' => now(),
        ]);

        PresenceValidation::create([
            'presence_id' => $presence2->id,
            'face_confidence' => 0.45,
            'validation_status' => PresenceValidation::STATUS_FAILED,
            'security_flags' => ['face_verification_failed'],
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/face-recognition-stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'period' => [
                    'start_date',
                    'end_date',
                ],
                'overall_stats' => [
                    'total_attempts',
                    'successful_attempts',
                    'failed_attempts',
                    'retry_required_attempts',
                    'success_rate',
                    'average_confidence',
                ],
                'confidence_distribution' => [
                    'high',
                    'medium',
                    'low',
                ],
                'daily_stats',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals(2, $data['overall_stats']['total_attempts']);
        $this->assertEquals(1, $data['overall_stats']['successful_attempts']);
        $this->assertEquals(1, $data['overall_stats']['failed_attempts']);
        $this->assertEquals(50.0, $data['overall_stats']['success_rate']);
    }

    public function test_get_face_recognition_stats_with_date_filter()
    {
        // Create test data with specific dates
        $presence = Presence::create([
            'created_by_id' => $this->regularUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => Carbon::parse('2024-01-15'),
            'latitude_in' => $this->store->latitude,
            'longitude_in' => $this->store->longitude,
        ]);

        // Create validation with specific date
        $validation = PresenceValidation::create([
            'presence_id' => $presence->id,
            'face_confidence' => 0.75,
            'validation_status' => PresenceValidation::STATUS_PASSED,
            'created_at' => Carbon::parse('2024-01-15'),
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/face-recognition-stats?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(1, $data['overall_stats']['total_attempts']);
    }

    public function test_get_users_with_face_issues()
    {
        // Create user with multiple failed attempts
        $store = Store::factory()->create();
        $shiftStore = ShiftStore::factory()->create();

        // Create multiple failed presences for the same user
        for ($i = 0; $i < 5; $i++) {
            $presence = Presence::factory()->create([
                'created_by_id' => $this->regularUser->id,
                'store_id' => $store->id,
                'shift_store_id' => $shiftStore->id,
            ]);

            PresenceValidation::factory()->create([
                'presence_id' => $presence->id,
                'face_confidence' => 0.3,
                'validation_status' => PresenceValidation::STATUS_FAILED,
                'security_flags' => ['face_verification_failed'],
                'created_at' => now()->subDays($i),
            ]);
        }

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/users-with-face-issues?' . http_build_query([
                'days' => 7,
                'min_failures' => 3,
            ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'period_days',
                'min_failures_threshold',
                'users_count',
                'users' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'total_attempts',
                        'failed_attempts',
                        'success_rate',
                        'average_confidence',
                        'last_failed_at',
                    ]
                ],
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals(1, $data['users_count']);
        $this->assertEquals($this->regularUser->id, $data['users'][0]['id']);
    }

    public function test_get_security_flags_summary()
    {
        // Create test data with various security flags
        $store = Store::factory()->create();
        $shiftStore = ShiftStore::factory()->create();

        $presence1 = Presence::factory()->create([
            'created_by_id' => $this->regularUser->id,
            'store_id' => $store->id,
            'shift_store_id' => $shiftStore->id,
        ]);

        $presence2 = Presence::factory()->create([
            'created_by_id' => $this->regularUser->id,
            'store_id' => $store->id,
            'shift_store_id' => $shiftStore->id,
        ]);

        PresenceValidation::factory()->create([
            'presence_id' => $presence1->id,
            'security_flags' => ['face_verification_failed', 'low_confidence'],
        ]);

        PresenceValidation::factory()->create([
            'presence_id' => $presence2->id,
            'security_flags' => ['face_verification_failed', 'max_retry_attempts_exceeded'],
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/security-flags-summary');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'period_days',
                'total_validations_with_flags',
                'security_flags',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals(2, $data['total_validations_with_flags']);
        $this->assertEquals(2, $data['security_flags']['face_verification_failed']);
        $this->assertEquals(1, $data['security_flags']['low_confidence']);
        $this->assertEquals(1, $data['security_flags']['max_retry_attempts_exceeded']);
    }

    public function test_get_face_registration_stats()
    {
        // Create users with and without face registrations
        $userWithFace = User::factory()->create();
        $userWithoutFace = User::factory()->create();

        FaceEncoding::factory()->create([
            'user_id' => $userWithFace->id,
            'is_active' => true,
            'registered_at' => now()->subDays(15),
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/face-registration-stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'total_users',
                'users_with_face',
                'users_without_face',
                'face_registration_rate',
                'recent_registrations_30_days',
            ],
        ]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(2, $data['total_users']); // At least admin and regular user
        $this->assertEquals(1, $data['users_with_face']);
        $this->assertEquals(1, $data['recent_registrations_30_days']);
    }

    public function test_admin_dashboard_endpoints_require_authentication()
    {
        $endpoints = [
            '/api/admin/face-recognition-stats',
            '/api/admin/users-with-face-issues',
            '/api/admin/security-flags-summary',
            '/api/admin/face-registration-stats',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertStatus(401);
        }
    }

    public function test_face_recognition_stats_validation()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/face-recognition-stats?' . http_build_query([
                'start_date' => 'invalid-date',
                'end_date' => '2024-01-01',
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date']);
    }

    public function test_users_with_face_issues_validation()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/users-with-face-issues?' . http_build_query([
                'days' => 0, // Invalid: should be min 1
                'min_failures' => 0, // Invalid: should be min 1
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['days', 'min_failures']);
    }

    public function test_security_flags_summary_validation()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/security-flags-summary?' . http_build_query([
                'days' => 100, // Invalid: should be max 90
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['days']);
    }
}