<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mysql')->table('sales_orders', function (Blueprint $table) {
            $table->index('created_at', 'sales_orders_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_created_at_index');
        });
    }
};
