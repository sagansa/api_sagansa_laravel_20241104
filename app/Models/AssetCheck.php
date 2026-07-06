<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hasil satu sesi pemeriksaan aset. Saat check disubmit, controller akan:
 *   - insert baris ini + item checklist (asset_check_items).
 *   - update asset.last_check_at & asset.condition.
 *   - reschedule asset.next_check_at = check_date + category.frequency_days.
 *   - bila severity >= ringan, auto-create asset_issues.
 */
class AssetCheck extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'asset_checks';
    protected $guarded = [];

    public const SEVERITY_OK = 1;
    public const SEVERITY_RINGAN = 2;
    public const SEVERITY_SEDANG = 3;
    public const SEVERITY_BERAT = 4;

    public const STATUS_SUBMITTED = 1;
    public const STATUS_APPROVED = 2;

    protected $casts = [
        'check_date' => 'date',
        'photos' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'severity' => 'integer',
        'status' => 'integer',
        'condition_before' => 'integer',
        'condition_after' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssetCheckItem::class, 'asset_check_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AssetIssue::class, 'asset_check_id');
    }

    public function getSeverityTextAttribute(): string
    {
        return match ((int) $this->severity) {
            self::SEVERITY_OK => 'OK',
            self::SEVERITY_RINGAN => 'Ringan',
            self::SEVERITY_SEDANG => 'Sedang',
            self::SEVERITY_BERAT => 'Berat',
            default => 'Tidak Diketahui',
        };
    }

    public function scopeForDateRange($query, ?string $from, ?string $to)
    {
        if ($from) $query->where('check_date', '>=', $from);
        if ($to) $query->where('check_date', '<=', $to);
        return $query;
    }
}
