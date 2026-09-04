<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoice_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_purchases', function (Blueprint $table) {
            $table->dropIndex(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
    }
};
