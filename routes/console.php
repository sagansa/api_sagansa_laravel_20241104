<?php

use App\Models\LocationRequest;
use App\Services\AssetCheckDueService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/**
 * Menandai permintaan lokasi on-demand yang sudah lewat batas waktu (tidak
 * direspons device) menjadi 'timeout'. Device yang tidak responsif (app
 * tertutup/force-stop/optimasi baterai agresif) tidak akan pernah membalas,
 * sehingga request perlu ditutup agar admin mendapat status yang jelas.
 *
 * Membutuhkan cron di server: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::call(function () {
    LocationRequest::where('status', 'pending')
        ->where('created_at', '<', now()->subMinutes(5))
        ->update([
            'status' => 'timeout',
            'timed_out_at' => now(),
        ]);
})->everyFifteenMinutes();

/**
 * Pemeriksaan aset berkala: setiap pagi (06:00 WIB) cari aset aktif yang
 * next_check_at-nya jatuh pada hari ini atau sudah lewat, lalu kirim push
 * FCM 'asset_check_due' ke seluruh user ber-role storage-staff/manager/admin
 * yang sedang aktif di store terkait. Diproses oleh AssetCheckDueService.
 *
 * Catatan: APP_TIMEZONE sebaiknya diset 'Asia/Jakarta' agar dailyAt('06:00')
 * benar-benar jam 6 pagi WIB.
 */
Schedule::call(function () {
    app(AssetCheckDueService::class)->processDueChecks();
})->dailyAt('06:00')
    ->name('asset-checks:daily')
    ->withoutOverlapping();

/**
 * Foto kesiapan diri dan kebersihan toko hanya diperlukan untuk verifikasi
 * jangka pendek. Record tetap disimpan untuk histori, tetapi file dan path
 * foto dibersihkan setelah melewati dua bulan.
 */
Schedule::command('readiness:prune-images')
    ->dailyAt('02:30')
    ->name('readiness:prune-images')
    ->withoutOverlapping();
