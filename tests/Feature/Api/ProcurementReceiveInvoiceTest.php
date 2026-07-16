<?php

namespace Tests\Feature\Api;

use App\Models\InvoicePurchase;
use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcurementReceiveInvoiceTest extends TestCase
{
    private function makeInvoice(string $orderStatus = '1'): InvoicePurchase
    {
        $store = Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store in database');
        }

        return InvoicePurchase::factory()->create([
            'order_status' => $orderStatus,
            'store_id' => $store->id,
            'payment_type_id' => 1,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    public function test_staff_can_receive_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $invoice = $this->makeInvoice('1');

        $res = $this->postJson("/api/procurement/invoices/{$invoice->id}/receive");

        $res->assertOk()
            ->assertJson(['success' => true]);
        $this->assertEquals('2', $invoice->fresh()->order_status);
    }

    public function test_admin_can_receive_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $invoice = $this->makeInvoice('1');

        $res = $this->postJson("/api/procurement/invoices/{$invoice->id}/receive");

        $res->assertOk();
        $this->assertEquals('2', $invoice->fresh()->order_status);
    }

    public function test_super_admin_can_receive_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        $invoice = $this->makeInvoice('1');

        $res = $this->postJson("/api/procurement/invoices/{$invoice->id}/receive");

        $res->assertOk();
        $this->assertEquals('2', $invoice->fresh()->order_status);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $invoice = $this->makeInvoice('1');

        $res = $this->postJson("/api/procurement/invoices/{$invoice->id}/receive");

        $res->assertUnauthorized();
    }

    public function test_invoice_not_found_returns_404(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $res = $this->postJson('/api/procurement/invoices/99999999/receive');

        $res->assertNotFound();
    }

    public function test_cannot_receive_already_received_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $invoice = $this->makeInvoice('2'); // already received

        $res = $this->postJson("/api/procurement/invoices/{$invoice->id}/receive");

        $res->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    public function test_unauthorized_role_returns_403(): void
    {
        $user = User::factory()->create(); // no role assigned
        Sanctum::actingAs($user);
        $invoice = $this->makeInvoice('1');

        $res = $this->postJson("/api/procurement/invoices/{$invoice->id}/receive");

        $res->assertForbidden();
    }
}
