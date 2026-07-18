<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Tanggal "bisnis" untuk laporan harian (Kesiapan Diri, Kebersihan Toko,
 * dan Sisa Stok / Remaining Storage).
 *
 * Laporan dibatasi dalam window:
 *   22:00 tanggal D-1  sampai  11:00 tanggal D   -> dihitung sebagai tanggal D-1.
 *
 * Artinya jika sekarang jam kurang dari 11:00, laporan dianggap milik
 * hari sebelumnya. Mis. jam 00:12 tanggal 18 Juli tetap dihitung laporan
 * tanggal 17 Juli.
 */
class BusinessDate
{
    /** Batas akhir window laporan (setelah jam ini dianggap hari baru). */
    public const CUTOFF_HOUR = 11;

    public static function now(Carbon $now): Carbon
    {
        $reference = $now->copy();

        if ($reference->hour < self::CUTOFF_HOUR) {
            return $reference->subDay()->startOfDay();
        }

        return $reference->startOfDay();
    }

    public static function todayString(): string
    {
        return self::now(Carbon::now())->toDateString();
    }
}
