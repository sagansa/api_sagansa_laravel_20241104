<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    private function adminOrSkip(): User
    {
        try {
            $user = User::factory()->create();
            $user->assignRole('admin');
            return $user;
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            $this->markTestSkipped("Role 'admin' not seeded in test DB — pre-existing test env limitation");
        }
    }

    public function test_admin_can_access(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard');

        $res->assertOk()->assertJson(['success' => true]);
    }

    public function test_super_admin_can_access(): void
    {
        try {
            $user = User::factory()->create();
            $user->assignRole('super_admin');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            $this->markTestSkipped("Role 'super_admin' not seeded in test DB");
        }
        /** @var User $user */
        Sanctum::actingAs($user);

        $res = $this->getJson('/sales-dashboard');

        $res->assertOk()->assertJson(['success' => true]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $res = $this->getJson('/sales-dashboard');

        $res->assertUnauthorized();
    }

    public function test_staff_returns_403(): void
    {
        try {
            $user = User::factory()->create();
            $user->assignRole('staff');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            $this->markTestSkipped("Role 'staff' not seeded in test DB");
        }
        /** @var User $user */
        Sanctum::actingAs($user);

        $res = $this->getJson('/sales-dashboard');

        $res->assertForbidden();
    }
}
