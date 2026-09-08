<?php

namespace Tests\Feature\Api;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseInvoiceSalesLinkTest extends TestCase
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

    private function makeSalesOrder(): int
    {
        // DB testing persisten antar-test — insert manual + cleanup pemanggil.
        return \Illuminate\Support\Facades\DB::table('sales_orders')->insertGetId([
            'for' => 1,
            'delivery_date' => now()->toDateString(),
            'payment_status' => 1,
            'delivery_status' => 1,
            'total_price' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_purchase_invoices_forbidden_for_staff(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->getJson('/sales-orders/1/purchase-invoices')->assertStatus(403);
    }

    public function test_purchase_invoices_forbidden_for_sales_role(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));

        $this->getJson('/sales-orders/1/purchase-invoices')->assertStatus(403);
    }

    public function test_purchase_invoices_returns_404_for_missing_order(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));

        $this->getJson('/sales-orders/99999999/purchase-invoices')
            ->assertStatus(404);
    }

    public function test_purchase_invoices_lists_only_invoices_of_that_order(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $orderId = $this->makeSalesOrder();
        $otherOrderId = $this->makeSalesOrder();

        try {
            $linked = $this->makeInvoice(['sales_order_id' => $orderId]);
            $this->makeInvoice(['sales_order_id' => $otherOrderId]);
            $this->makeInvoice(); // tanpa kaitan

            $res = $this->getJson("/sales-orders/{$orderId}/purchase-invoices");

            $res->assertOk()->assertJson(['success' => true]);
            $ids = array_column($res->json('data'), 'id');
            $this->assertEquals([$linked->id], $ids);
            $this->assertArrayHasKey('supplier_name', $res->json('data')[0]);
        } finally {
            \App\Models\InvoicePurchase::whereIn('sales_order_id', [$orderId, $otherOrderId])->delete();
            \Illuminate\Support\Facades\DB::table('sales_orders')
                ->whereIn('id', [$orderId, $otherOrderId])->delete();
        }
    }

    public function test_admin_can_list_purchase_invoices(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeSalesOrder();

        try {
            $this->makeInvoice(['sales_order_id' => $orderId]);

            $this->getJson("/sales-orders/{$orderId}/purchase-invoices")
                ->assertOk()->assertJson(['success' => true]);
        } finally {
            \App\Models\InvoicePurchase::where('sales_order_id', $orderId)->delete();
            \Illuminate\Support\Facades\DB::table('sales_orders')->where('id', $orderId)->delete();
        }
    }

    public function test_invoice_link_candidates_forbidden_for_staff(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->getJson('/procurement/invoices/link-candidates')
            ->assertStatus(403);
    }

    public function test_invoice_link_candidates_defaults_to_paid_and_unlinked(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $orderId = $this->makeSalesOrder();

        try {
            $paidUnlinked = $this->makeInvoice(['payment_status' => '2']);
            $paidLinked = $this->makeInvoice(['payment_status' => '2', 'sales_order_id' => $orderId]);
            $draftUnlinked = $this->makeInvoice(['payment_status' => '1']);

            $res = $this->getJson('/procurement/invoices/link-candidates');

            $res->assertOk();
            $ids = array_column($res->json('data'), 'id');
            $this->assertContains($paidUnlinked->id, $ids);
            $this->assertNotContains($paidLinked->id, $ids);
            $this->assertNotContains($draftUnlinked->id, $ids);
            foreach ($res->json('data') as $row) {
                $this->assertSame('2', (string) $row['payment_status']);
            }

            // Bisa mencari draft jika secara eksplisit meminta ?payment_status=1
            $resDraft = $this->getJson('/procurement/invoices/link-candidates?payment_status=1');
            $resDraft->assertOk();
            $draftIds = array_column($resDraft->json('data'), 'id');
            $this->assertContains($draftUnlinked->id, $draftIds);
            $this->assertNotContains($paidUnlinked->id, $draftIds);
        } finally {
            \App\Models\InvoicePurchase::whereIn('id', [
                $paidUnlinked->id ?? 0, $paidLinked->id ?? 0, $draftUnlinked->id ?? 0,
            ])->orWhere('sales_order_id', $orderId)->delete();
            \Illuminate\Support\Facades\DB::table('sales_orders')->where('id', $orderId)->delete();
        }
    }

    public function test_invoice_link_candidates_search_by_supplier_name(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $db = \Illuminate\Support\Facades\DB::table('suppliers');
        $supplierId = $db->insertGetId([
            'name' => 'PT Kandidat Unik Zzz',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $invoice = $this->makeInvoice([
                'supplier_id' => $supplierId,
                'payment_status' => '2',
            ]);

            $res = $this->getJson('/procurement/invoices/link-candidates?q=' . urlencode('Kandidat Unik'));

            $res->assertOk();
            $ids = array_column($res->json('data'), 'id');
            $this->assertContains($invoice->id, $ids);
        } finally {
            \App\Models\InvoicePurchase::where('supplier_id', $supplierId)->delete();
            $db->where('id', $supplierId)->delete();
        }
    }

    public function test_invoice_link_candidates_search_by_id(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $invoice = $this->makeInvoice(['payment_status' => '2']);

        try {
            $res = $this->getJson("/procurement/invoices/link-candidates?q={$invoice->id}");

            $res->assertOk();
            $ids = array_column($res->json('data'), 'id');
            $this->assertContains($invoice->id, $ids);
        } finally {
            $invoice->delete();
        }
    }

    public function test_storage_staff_can_link_and_unlink_paid_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $orderId = $this->makeSalesOrder();
        $invoice = $this->makeInvoice(['payment_status' => '2']);

        try {
            // Link ke sales order
            $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
                'sales_order_id' => $orderId,
            ]);
            $res->assertOk();
            $this->assertEquals($orderId, $invoice->fresh()->sales_order_id);
            $this->assertSame('2', $invoice->fresh()->payment_status);

            // Unlink dari sales order
            $resUnlink = $this->putJson("/procurement/invoices/{$invoice->id}", [
                'sales_order_id' => '',
            ]);
            $resUnlink->assertOk();
            $this->assertNull($invoice->fresh()->sales_order_id);
        } finally {
            $invoice->delete();
            \Illuminate\Support\Facades\DB::table('sales_orders')->where('id', $orderId)->delete();
        }
    }

    public function test_full_edit_on_paid_invoice_is_still_rejected(): void
    {
        Sanctum::actingAs($this->userWithRole('storage-staff'));
        $orderId = $this->makeSalesOrder();
        $invoice = $this->makeInvoice(['payment_status' => '2']);

        try {
            // Mengubah sales_order_id + field lain (notes) dianggap edit penuh → ditolak 400
            $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
                'sales_order_id' => $orderId,
                'notes' => 'Catatan baru',
            ]);
            $res->assertStatus(400);
            $res->assertJson(['message' => 'Hanya invoice draft yang dapat diedit.']);
        } finally {
            $invoice->delete();
            \Illuminate\Support\Facades\DB::table('sales_orders')->where('id', $orderId)->delete();
        }
    }
}
