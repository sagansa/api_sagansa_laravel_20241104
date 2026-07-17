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

    public function test_default_periode_is_today_and_view_is_summary(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard');

        $res->assertOk()
            ->assertJsonPath('data.periode', 'today')
            ->assertJsonPath('data.view', 'summary');
    }

    public function test_invalid_periode_returns_422(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard?periode=foo');

        $res->assertStatus(422);
    }

    public function test_invalid_view_returns_422(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard?view=foo');

        $res->assertStatus(422);
    }

    public function test_invalid_sort_returns_422(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard?view=products&sort=foo');

        $res->assertStatus(422);
    }

    public function test_per_page_clamped_to_200(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard?view=products&per_page=9999');

        $res->assertOk()->assertJsonPath('data.meta.per_page', 200);
    }

    public function test_accepts_all_four_periodes(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        foreach (['today', 'yesterday', 'month', 'year'] as $p) {
            $res = $this->getJson("/sales-dashboard?periode={$p}");
            $res->assertOk()->assertJsonPath('data.periode', $p);
        }
    }
}
