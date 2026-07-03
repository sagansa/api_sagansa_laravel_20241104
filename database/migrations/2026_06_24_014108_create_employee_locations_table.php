<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menyimpan titik lokasi pegawai (on-demand & periodic).
     *
     * Tabel ini berada di DB bisnis (mysql/sagansa). Referensi pegawai memakai
     * `created_by_id` (id user) sebagai loose reference — tanpa FK yang dipaksa —
     * karena tabel `users` berada di koneksi DB terpisah (mysql_auth/sagansa_user),
     * sama pola yang sudah digunakan oleh tabel presences.
     */
    public function up(): void
    {
        Schema::create('employee_locations', function (Blueprint $table) {
            $table->id();
            // Id user pegawai (loose reference, cross-DB — tidak enforced FK).
            $table->unsignedBigInteger('created_by_id')->index();

            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            // Akurasi GPS dalam meter (nullable bila tidak tersedia).
            $table->decimal('accuracy', 8, 2)->nullable();

            // Sumber lokasi: 'on_demand' (admin trigger) | 'periodic' (background 2 jam).
            $table->string('source')->default('periodic'); // on_demand | periodic
            // request_id (UUID) hanya diisi bila source='on_demand', untuk merelasikan
            // ke tabel location_requests.
            $table->uuid('request_id')->nullable()->index();

            // Waktu pengambilan GPS di sisi device (lebih akurat daripada created_at
            // karena ada delay jaringan saat mengunggah).
            $table->datetime('captured_at');
            $table->timestamps();

            $table->index(['created_by_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_locations');
    }
};
