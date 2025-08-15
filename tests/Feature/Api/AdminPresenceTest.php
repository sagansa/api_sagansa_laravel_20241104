<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Presence;
use App\Models\Store;
use App\Models\ShiftStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPresenceTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $adminUser;
    protected User $regularUser;
    protected Store $store;
    protected ShiftStore $shiftStore;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles first
        \Spatie\Permission\Models\Role::create(['name' => 'admin']);
        \Spatie\Permission\Models\Role::create(['name' => 'staff']);
        \Spatie\Permission\Models\Role::create(['name' => 'manager']);

        // Create admin user
        $this->adminUser = User::factory()->create([
            'email' => 'admin@test.com',
            'name' => 'Admin User'
        ]);
        $this->adminUser->assignRole('admin');

        // Create regular user with staff role
        $this->regularUser = User::factory()->create([
            'email' => 'user@test.com',
            'name' => 'Regular User'
        ]);
        $this->regularUser->assignRole('staff');

        // Create store and shift store for testing
        $this->store = Store::factory()->create();
        $this->shiftStore = ShiftStore::factory()->create();
    }

    public function test_admin_can_delete_presence()
    {
        // Authenticate as admin
        Sanctum::actingAs($this->adminUser);

        // Create a presence record
        $presence = Presence::create([
            'created_by_id' => $this->regularUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => -6.2088,
            'longitude_in' => 106.8456,
            'image_in' => 'test_image.jpg'
        ]);

        // Delete the presence
        $response = $this->deleteJson("/api/admin/presences/{$presence->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'status' => 'success',
                    'message' => 'Presence deleted successfully'
                ]);

        // Verify the presence is deleted from database
        $this->assertDatabaseMissing('presences', [
            'id' => $presence->id
        ]);
    }

    public function test_admin_cannot_delete_non_existent_presence()
    {
        // Authenticate as admin
        Sanctum::actingAs($this->adminUser);

        // Try to delete non-existent presence
        $response = $this->deleteJson('/api/admin/presences/999999');

        $response->assertStatus(404)
                ->assertJson([
                    'status' => 'error',
                    'message' => 'Presence not found'
                ]);
    }

    public function test_non_admin_cannot_delete_presence()
    {
        // Authenticate as regular user (non-admin)
        Sanctum::actingAs($this->regularUser);

        // Create a presence record
        $presence = Presence::create([
            'created_by_id' => $this->regularUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => -6.2088,
            'longitude_in' => 106.8456,
            'image_in' => 'test_image.jpg'
        ]);

        // Try to delete the presence
        $response = $this->deleteJson("/api/admin/presences/{$presence->id}");

        $response->assertStatus(403)
                ->assertJson([
                    'status' => 'error',
                    'message' => 'Access denied'
                ]);

        // Verify the presence still exists in database
        $this->assertDatabaseHas('presences', [
            'id' => $presence->id
        ]);
    }

    public function test_unauthenticated_user_cannot_delete_presence()
    {
        // Create a presence record
        $presence = Presence::create([
            'created_by_id' => $this->regularUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => -6.2088,
            'longitude_in' => 106.8456,
            'image_in' => 'test_image.jpg'
        ]);

        // Try to delete the presence without authentication
        $response = $this->deleteJson("/api/admin/presences/{$presence->id}");

        $response->assertStatus(401);

        // Verify the presence still exists in database
        $this->assertDatabaseHas('presences', [
            'id' => $presence->id
        ]);
    }

    public function test_admin_can_get_presence_list()
    {
        // Authenticate as admin
        Sanctum::actingAs($this->adminUser);

        // Create some presence records
        $presences = collect();
        for ($i = 0; $i < 3; $i++) {
            $presences->push(Presence::create([
                'created_by_id' => $this->regularUser->id,
                'store_id' => $this->store->id,
                'shift_store_id' => $this->shiftStore->id,
                'status' => 1,
                'check_in' => now()->subDays($i),
                'latitude_in' => -6.2088,
                'longitude_in' => 106.8456,
                'image_in' => 'test_image.jpg'
            ]));
        }

        // Get presence list
        $response = $this->getJson('/api/admin/presences');

        $response->assertStatus(200)
                ->assertJson([
                    'status' => 'success'
                ])
                ->assertJsonStructure([
                    'status',
                    'data' => [
                        'data' => [
                            '*' => [
                                'id',
                                'created_by_id',
                                'store_id',
                                'shift_store_id',
                                'check_in',
                                'created_by' => [
                                    'id',
                                    'name',
                                    'email'
                                ],
                                'store',
                                'shift_store'
                            ]
                        ],
                        'current_page',
                        'last_page',
                        'per_page',
                        'total'
                    ]
                ]);
    }

    public function test_admin_can_only_see_staff_users_in_user_list()
    {
        // Authenticate as admin
        Sanctum::actingAs($this->adminUser);

        // Create users with different roles
        $staffUser = User::factory()->create(['name' => 'Staff User']);
        $staffUser->assignRole('staff');

        $managerUser = User::factory()->create(['name' => 'Manager User']);
        $managerUser->assignRole('manager');

        $anotherAdminUser = User::factory()->create(['name' => 'Another Admin']);
        $anotherAdminUser->assignRole('admin');

        // Get user list
        $response = $this->getJson('/api/admin/users');

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true
                ]);

        $users = $response->json('data');
        $userNames = collect($users)->pluck('name')->toArray();

        // Should include staff users only
        $this->assertContains('Regular User', $userNames); // from setUp
        $this->assertContains('Staff User', $userNames);

        // Should not include non-staff users
        $this->assertNotContains('Manager User', $userNames);
        $this->assertNotContains('Another Admin', $userNames);
        $this->assertNotContains('Admin User', $userNames);
    }

    public function test_admin_can_only_see_staff_presences()
    {
        // Authenticate as admin
        Sanctum::actingAs($this->adminUser);

        // Create users with different roles
        $staffUser = User::factory()->create(['name' => 'Staff User']);
        $staffUser->assignRole('staff');

        $managerUser = User::factory()->create(['name' => 'Manager User']);
        $managerUser->assignRole('manager');

        // Create presences for different users
        $staffPresence = Presence::create([
            'created_by_id' => $staffUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => -6.2088,
            'longitude_in' => 106.8456,
            'image_in' => 'test_image.jpg'
        ]);

        $managerPresence = Presence::create([
            'created_by_id' => $managerUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
            'latitude_in' => -6.2088,
            'longitude_in' => 106.8456,
            'image_in' => 'test_image.jpg'
        ]);

        // Get presence list
        $response = $this->getJson('/api/admin/presences');

        $response->assertStatus(200);

        $presences = $response->json('data.data');
        $createdByIds = collect($presences)->pluck('created_by_id')->toArray();

        // Should include staff presence only
        $this->assertContains($staffUser->id, $createdByIds);

        // Should not include manager presence
        $this->assertNotContains($managerUser->id, $createdByIds);
    }

    public function test_admin_cannot_create_presence_for_non_staff_user()
    {
        // Authenticate as admin
        Sanctum::actingAs($this->adminUser);

        // Create a manager user
        $managerUser = User::factory()->create(['name' => 'Manager User']);
        $managerUser->assignRole('manager');

        // Try to create presence for manager user
        $response = $this->postJson('/api/admin/presences', [
            'created_by_id' => $managerUser->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now()->format('Y-m-d H:i:s'),
            'latitude_in' => -6.2088,
            'longitude_in' => 106.8456,
            'image_in' => 'test_image.jpg'
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['created_by_id']);
    }
}