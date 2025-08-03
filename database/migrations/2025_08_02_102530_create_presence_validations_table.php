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
        Schema::create('presence_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presence_id')->constrained()->onDelete('cascade');
            $table->decimal('face_confidence', 5, 4)->nullable()->comment('Face recognition confidence score (0.0000-1.0000)');
            $table->decimal('gps_accuracy', 8, 2)->nullable()->comment('GPS accuracy in meters');
            $table->string('location_source')->nullable()->comment('GPS, Network, Passive, etc.');
            $table->enum('validation_status', ['pending', 'passed', 'failed', 'retry_required'])->default('pending');
            $table->json('security_flags')->nullable()->comment('Array of security warnings/flags');
            $table->integer('retry_count')->default(0)->comment('Number of face recognition retry attempts');
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            
            $table->index(['presence_id', 'validation_status']);
            $table->index('validated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presence_validations');
    }
};
