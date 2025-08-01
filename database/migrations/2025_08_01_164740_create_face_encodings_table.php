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
        Schema::create('face_encodings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('encoding'); // Encrypted face encoding data
            $table->string('encoding_version')->default('1.0'); // Version for future compatibility
            $table->boolean('is_active')->default(true);
            $table->timestamp('registered_at');
            $table->timestamps();
            
            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('face_encodings');
    }
};
