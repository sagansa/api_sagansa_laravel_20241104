<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcurementInvoiceStaffUpdateTest extends TestCase
{
    private function userWithRole(string $role): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    private function makeInvoice(array $attributes = []): \App\Models\InvoicePurchase
    {
        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store in database');
        }

        return \App\Models\InvoicePurchase::factory()->create(array_merge([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'payment_status' => '1',
        ], $attributes));
    }

    public function test_sales_order_id_column_exists(): void
    {
        $this->assertTrue(
            Schema::hasColumn('invoice_purchases', 'sales_order_id')
        );
    }

    public function test_staff_can_update_unpaid_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice();

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'notes' => 'koreksi catatan',
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame('koreksi catatan', $invoice->fresh()->notes);
    }

    public function test_staff_cannot_update_paid_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice(['payment_status' => '2']);

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'notes' => 'x',
        ]);

        $res->assertStatus(400);
    }

    public function test_sales_role_cannot_update_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));
        $invoice = $this->makeInvoice();

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'notes' => 'x',
        ]);

        $res->assertStatus(403);
    }

    public function test_can_set_sales_order_link_on_update(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice();
        $order = \Illuminate\Support\Facades\DB::table('sales_orders')->first();
        if (!$order) {
            $this->markTestSkipped('Need at least 1 sales order in database');
        }

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'sales_order_id' => $order->id,
        ]);

        $res->assertOk();
        $this->assertEquals($order->id, $invoice->fresh()->sales_order_id);
    }

    public function test_can_clear_sales_order_link_with_empty_string(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $order = \Illuminate\Support\Facades\DB::table('sales_orders')->first();
        if (!$order) {
            $this->markTestSkipped('Need at least 1 sales order in database');
        }
        $invoice = $this->makeInvoice(['sales_order_id' => $order->id]);

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'sales_order_id' => '',
        ]);

        $res->assertOk();
        $this->assertNull($invoice->fresh()->sales_order_id);
    }

    public function test_invalid_sales_order_id_returns_422(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice();

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'sales_order_id' => 99999999,
        ]);

        $res->assertStatus(422);
    }

    public function test_non_numeric_sales_order_id_returns_422_and_keeps_null(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice();

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'sales_order_id' => 'abc',
        ]);

        $res->assertStatus(422);
        $this->assertNull($invoice->fresh()->sales_order_id);
    }

    public function test_show_invoice_includes_sales_order_summary(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $order = \Illuminate\Support\Facades\DB::table('sales_orders')->first();
        if (!$order) {
            $this->markTestSkipped('Need at least 1 sales order in database');
        }
        $invoice = $this->makeInvoice(['sales_order_id' => $order->id]);

        $res = $this->getJson("/procurement/invoices/{$invoice->id}");

        $res->assertOk()
            ->assertJsonPath('data.sales_order_id', $order->id)
            ->assertJsonPath('data.sales_order.id', $order->id);
    }

    public function test_show_invoice_without_link_has_null_sales_order(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice();

        $res = $this->getJson("/procurement/invoices/{$invoice->id}");

        $res->assertOk()->assertJsonPath('data.sales_order', null);
    }

    public function test_show_invoice_with_soft_deleted_sales_order_has_null_sales_order(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $order = \Illuminate\Support\Facades\DB::table('sales_orders')->first();
        if (!$order) {
            $this->markTestSkipped('Need at least 1 sales order in database');
        }

        // Soft-delete via raw update (deleted_at), lalu tautkan ke invoice.
        // DB testing persisten antar-test — selalu pulihkan deleted_at asli.
        $originalDeletedAt = $order->deleted_at;
        \Illuminate\Support\Facades\DB::table('sales_orders')
            ->where('id', $order->id)
            ->update(['deleted_at' => now()]);

        try {
            $invoice = $this->makeInvoice(['sales_order_id' => $order->id]);

            $res = $this->getJson("/procurement/invoices/{$invoice->id}");

            $res->assertOk()
                ->assertJsonPath('data.sales_order_id', (int) $order->id)
                ->assertJsonPath('data.sales_order', null);
        } finally {
            \Illuminate\Support\Facades\DB::table('sales_orders')
                ->where('id', $order->id)
                ->update(['deleted_at' => $originalDeletedAt]);
        }
    }

    public function test_link_candidates_search_by_receipt_no(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $order = \Illuminate\Support\Facades\DB::table('sales_orders')
            ->whereNotNull('receipt_no')->first();
        if (!$order) {
            $this->markTestSkipped('Need a sales order with receipt_no');
        }

        $res = $this->getJson('/sales-orders/link-candidates?q=' . $order->receipt_no);

        $res->assertOk()->assertJson(['success' => true]);
        $ids = array_column($res->json('data'), 'id');
        $this->assertContains((int) $order->id, $ids);
    }

    public function test_link_candidates_search_by_id(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $order = \Illuminate\Support\Facades\DB::table('sales_orders')->first();
        if (!$order) {
            $this->markTestSkipped('Need at least 1 sales order');
        }

        $res = $this->getJson('/sales-orders/link-candidates?q=' . $order->id);

        $res->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $this->assertContains((int) $order->id, $ids);
    }

    public function test_link_candidates_forbidden_for_sales_role(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));

        $this->getJson('/sales-orders/link-candidates?q=x')->assertStatus(403);
    }

    public function test_link_candidates_lists_recent_online_shop_orders(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $db = \Illuminate\Support\Facades\DB::table('sales_orders');
        $recentId = $db->insertGetId([
            'for' => 1,
            'delivery_date' => now()->toDateString(),
            'payment_status' => 1,
            'delivery_status' => 1,
            'total_price' => 12345,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldId = $db->insertGetId([
            'for' => 1,
            'delivery_date' => now()->toDateString(),
            'payment_status' => 1,
            'delivery_status' => 1,
            'total_price' => 999,
            'created_at' => now()->subDays(6),
            'updated_at' => now(),
        ]);

        try {
            $res = $this->getJson('/sales-orders/link-candidates');

            $res->assertOk()->assertJson(['success' => true]);
            $rows = $res->json('data');
            $ids = array_column($rows, 'id');
            // Order yang dibuat dalam 5 hari terakhir masuk list;
            // yang lebih tua tidak.
            $this->assertContains($recentId, $ids);
            $this->assertNotContains($oldId, $ids);
            // Mode daftar (tanpa q) hanya menampilkan order toko online (for=1).
            foreach ($rows as $row) {
                $this->assertSame(1, $row['for']);
            }
        } finally {
            $db->whereIn('id', [$recentId, $oldId])->delete();
        }
    }

    public function test_link_candidates_list_forbidden_for_sales_role(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));

        $this->getJson('/sales-orders/link-candidates')->assertStatus(403);
    }

    public function test_staff_can_replace_image_on_paid_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice(['payment_status' => '2', 'image' => 'images/InvoicePurchase/old.webp']);

        $res = $this->postJson("/procurement/invoices/{$invoice->id}/image", [
            'image' => 'images/InvoicePurchase/final.webp',
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame('images/InvoicePurchase/final.webp', $invoice->fresh()->image);
        $this->assertSame('2', $invoice->fresh()->payment_status);
        $this->assertNotNull($res->json('data.image_url'));
    }

    public function test_image_update_forbidden_for_sales_role(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));
        $invoice = $this->makeInvoice();

        $this->postJson("/procurement/invoices/{$invoice->id}/image", [
            'image' => 'images/InvoicePurchase/x.webp',
        ])->assertStatus(403);
    }

    public function test_image_update_requires_image(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice();

        $this->postJson("/procurement/invoices/{$invoice->id}/image", [])
            ->assertStatus(422);
    }

    public function test_image_update_rejects_path_outside_invoice_folder(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice(['image' => 'images/InvoicePurchase/old.webp']);

        $res = $this->postJson("/procurement/invoices/{$invoice->id}/image", [
            'image' => 'images/Delivery/evil.webp',
        ]);

        $res->assertStatus(422);
        $this->assertSame('images/InvoicePurchase/old.webp', $invoice->fresh()->image);
    }
}
