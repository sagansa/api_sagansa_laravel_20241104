<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\InvoicePurchase;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InvoicePurchase>
 */
class InvoicePurchaseFactory extends Factory
{
    protected $model = InvoicePurchase::class;

    public function definition(): array
    {
        return [
            'date' => now()->format('Y-m-d'),
            'taxes' => 0,
            'discounts' => 0,
            'total_price' => 0,
            'payment_status' => '1',
            'order_status' => '1',
            'notes' => null,
            'payment_type_id' => 1,
            'store_id' => 1,
            'supplier_id' => null,
            'created_by_id' => null,
            'approved_by_id' => null,
        ];
    }
}
