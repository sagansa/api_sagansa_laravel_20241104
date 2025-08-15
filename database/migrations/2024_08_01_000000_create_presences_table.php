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
        Schema::create('presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('shift_store_id')->constrained('shift_stores')->onDelete('cascade');
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->datetime('check_in');
            $table->datetime('check_out')->nullable();
            
            $table->decimal('latitude_in', 10, 8);
            $table->decimal('longitude_in', 11, 8);
            $table->decimal('latitude_out', 10, 8)->nullable();
            $table->decimal('longitude_out', 11, 8)->nullable();
            
            $table->text('image_in');
            $table->text('image_out')->nullable();
            
            $table->integer('status')->default(1); // 1: on time, 2: late, 3: early, etc.
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['created_by_id', 'check_in']);
            $table->index(['store_id', 'check_in']);
            $table->index('check_in');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presences');
    }
};