<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mencatat permintaan lokasi on-demand dari admin ke pegawai.
     *
     * Berada di DB bisnis (mysql/sagansa). `user_id` = pegawai yang dilacak,
     * `requested_by_id` = admin yang memicu. Keduanya loose reference (cross-DB)
     * seperti pola presences.
     */
    public function up(): void
    {
        if (Schema::hasTable('location_requests')) {
            return;
        }

        Schema::create('location_requests', function (Blueprint $table) {
            $table->id();
            // Pegawai yang dilacak.
            $table->unsignedBigInteger('user_id')->index();
            // Admin yang memicu permintaan.
            $table->unsignedBigInteger('requested_by_id')->index();

            // Token korelasi yang dikirim lewat FCM dan dikembalikan oleh device
            // saat mengunggah lokasi, untuk mencocokkan request -> hasil.
            $table->uuid('request_id')->unique();

            // Status: pending | fulfilled | failed | timeout
            $table->string('status')->default('pending');
            $table->text('error')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('timed_out_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_requests');
    }
};
