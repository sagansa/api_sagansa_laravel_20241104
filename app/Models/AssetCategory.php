<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Kategori aset. Menentukan frekuensi pemeriksaan (frequency_days) dan
 * checklist baku (checklist_definition JSON) yang akan dipakai saat user
 * mengeksekusi pemeriksaan berkala.
 */
class AssetCategory extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'asset_categories';
    protected $guarded = [];

    protected $casts = [
        'checklist_definition' => 'array',
        'is_active' => 'boolean',
        'frequency_days' => 'integer',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'asset_category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'asset_category_id');
    }

    /**
     * Hitung tanggal next_check berdasarkan frekuensi kategori, dari titik
     * waktu yang diberikan (default now()).
     */
    public function computeNextCheckAt(?Carbon $from = null): Carbon
    {
        return ($from ?? Carbon::now())->addDays($this->frequency_days);
    }

    /**
     * Label ramah-tampilan untuk frekuensi, mis. "Setiap 30 hari".
     */
    public function getFrequencyLabelAttribute(): string
    {
        $days = $this->frequency_days;
        if ($days <= 1) return 'Setiap hari';
        if ($days == 7) return 'Mingguan';
        if ($days == 30) return 'Bulanan';
        if ($days == 90) return 'Triwulan';
        if ($days == 180) return 'Semester';
        if ($days == 365) return 'Tahunan';
        return "Setiap {$days} hari";
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
