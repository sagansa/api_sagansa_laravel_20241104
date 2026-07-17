<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mysql')->table('sales_orders', function (Blueprint $table) {
            $table->index('delivery_date', 'sales_orders_delivery_date_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_delivery_date_index');
        });
    }
};
