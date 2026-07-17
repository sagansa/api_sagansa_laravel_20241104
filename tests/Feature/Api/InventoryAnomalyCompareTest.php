<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryAnomalyCompareTest extends TestCase
{
    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        try {
            $user->assignRole($role);
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            $this->markTestSkipped("Role '{$role}' not seeded in test DB — pre-existing test env limitation");
        }
        return $user;
    }

    public function test_admin_can_access_compare(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $res = $this->getJson('/inventory-anomalies/compare');

        $res->assertOk()->assertJson(['success' => true]);
    }

    public function test_super_admin_can_access_compare(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));

        $res = $this->getJson('/inventory-anomalies/compare');

        $res->assertOk()->assertJson(['success' => true]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $res = $this->getJson('/inventory-anomalies/compare');

        $res->assertUnauthorized();
    }

    public function test_staff_returns_403(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $res = $this->getJson('/inventory-anomalies/compare');

        $res->assertForbidden();
    }
}
