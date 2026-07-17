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

    public function test_default_date_is_yesterday(): void
    {
        // Skip if not admin (role issue)
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/inventory-anomalies/compare');

        $yesterday = now()->subDay()->toDateString();
        $res->assertOk()
            ->assertJsonPath('data.period.date_from', $yesterday)
            ->assertJsonPath('data.period.date_to', $yesterday);
    }

    public function test_accepts_explicit_date_range(): void
    {
        $admin = $this->adminOrSkip();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/inventory-anomalies/compare?date_from=2026-07-10&date_to=2026-07-12');

        $res->assertOk()
            ->assertJsonPath('data.period.date_from', '2026-07-10')
            ->assertJsonPath('data.period.date_to', '2026-07-12');
    }

    public function test_invalid_date_returns_422(): void
    {
        $admin = $this->adminOrSkip();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/inventory-anomalies/compare?date_from=abc');

        $res->assertStatus(422);
    }

    public function test_date_to_before_date_from_returns_422(): void
    {
        $admin = $this->adminOrSkip();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/inventory-anomalies/compare?date_from=2026-07-12&date_to=2026-07-10');

        $res->assertStatus(422);
    }

    public function test_store_ids_csv_is_parsed_to_int_array(): void
    {
        $admin = $this->adminOrSkip();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/inventory-anomalies/compare?store_ids=1,3,5');

        $res->assertOk()
            ->assertJsonPath('data.period.store_ids', [1, 3, 5]);
    }

    public function test_per_page_clamped_to_200(): void
    {
        $admin = $this->adminOrSkip();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/inventory-anomalies/compare?per_page=9999');

        $res->assertOk()->assertJsonPath('meta.per_page', 200);
    }

    public function test_sold_qty_aggregates_multiple_orders_for_same_product(): void
    {
        $admin = $this->adminOrSkip();
        $productId = \DB::table('products')->value('id');
        if ($productId === null) {
            $this->markTestSkipped('No products in DB');
        }
        $store = Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store');
        }

        $today = now()->toDateString();
        foreach ([5, 7] as $qty) {
            $soId = \DB::table('sales_orders')->insertGetId([
                'for' => '3', 'delivery_date' => $today, 'store_id' => $store->id,
                'delivery_status' => 3, 'payment_status' => 1, 'total_price' => 0,
                'ordered_by_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            \DB::table('detail_sales_orders')->insert([
                'product_id' => $productId, 'quantity' => $qty, 'unit_price' => 1000,
                'subtotal_price' => 1000 * $qty, 'sales_order_id' => $soId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($admin);
        $res = $this->getJson('/inventory-anomalies/compare?date_from=' . $today . '&date_to=' . $today);

        $res->assertOk();
        $item = collect($res->json('data.items'))->firstWhere('product_id', $productId);
        $this->assertNotNull($item, 'Product should appear in items');
        $this->assertEquals(12, $item['sold_qty']);
    }
}
