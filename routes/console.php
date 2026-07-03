<?php

use App\Models\LocationRequest;
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
