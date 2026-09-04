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

    public function test_link_candidates_requires_q(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->getJson('/sales-orders/link-candidates')->assertStatus(422);
    }
}
