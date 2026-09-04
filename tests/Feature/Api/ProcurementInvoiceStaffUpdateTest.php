<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcurementInvoiceStaffUpdateTest extends TestCase
{
    private function userWithRole(string $role): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    public function test_sales_order_id_column_exists(): void
    {
        $this->assertTrue(
            Schema::hasColumn('invoice_purchases', 'sales_order_id')
        );
    }
}
