<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menyimpan FCM device token per user untuk push notifikasi lokasi on-demand.
     *
     * Dibuat di koneksi mysql_auth (sagansa_user) agar satu DB dengan tabel
     * `users` & `personal_access_tokens`, karena model DeviceToken maupun User
     * membaca koneksi ini. Loose reference `user_id` (tanpa FK enforced) mengikuti
     * pola yang sudah ada.
     */
    public function up(): void
    {
        if (Schema::connection('mysql_auth')->hasTable('device_tokens')) {
            return;
        }

        Schema::connection('mysql_auth')->create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();

            // FCM registration token (sangat panjang; gunakan string tanpa batas).
            $table->string('token');
            // Identitas device opsional (Android ID dsb.) untuk diagnostik.
            $table->string('device_id')->nullable();

            $table->timestamps();

            // Satu token hanya dimiliki satu user; tapi satu user bisa banyak device.
            $table->unique(['user_id', 'token']);
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql_auth')->dropIfExists('device_tokens');
    }
};
