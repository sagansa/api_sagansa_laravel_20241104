<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Temuan/tindak lanjut dari check aset (modul sederhana: status open/closed).
 * Dapat terbentuk otomatis saat asset_check.severity >= ringan, atau dibuat
 * manual dari UI detail aset.
 */
class AssetIssue extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'asset_issues';
    protected $guarded = [];

    public const SEVERITY_RINGAN = 2;
    public const SEVERITY_SEDANG = 3;
    public const SEVERITY_BERAT = 4;

    public const STATUS_OPEN = 1;
    public const STATUS_CLOSED = 2;

    protected $casts = [
        'severity' => 'integer',
        'status' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function assetCheck(): BelongsTo
    {
        return $this->belongsTo(AssetCheck::class, 'asset_check_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function getSeverityTextAttribute(): string
    {
        return match ((int) $this->severity) {
            self::SEVERITY_RINGAN => 'Ringan',
            self::SEVERITY_SEDANG => 'Sedang',
            self::SEVERITY_BERAT => 'Berat',
            default => 'Tidak Diketahui',
        };
    }

    public function getStatusTextAttribute(): string
    {
        return ((int) $this->status) === self::STATUS_CLOSED ? 'Closed' : 'Open';
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', self::STATUS_CLOSED);
    }
}
