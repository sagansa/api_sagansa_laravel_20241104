<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot hasil checklist per sesi check. Label berasal dari
 * `asset_categories.checklist_definition` dan disalin ke sini agar historis
 * (label tidak akan berubah walau kategori diperbarui di kemudian hari).
 */
class AssetCheckItem extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'asset_check_items';
    protected $guarded = [];

    public const VALUE_NOT_OK = 0;
    public const VALUE_OK = 1;

    protected $casts = [
        'value' => 'integer',
    ];

    public function assetCheck(): BelongsTo
    {
        return $this->belongsTo(AssetCheck::class, 'asset_check_id');
    }

    public function getValueTextAttribute(): string
    {
        return ((int) $this->value) === self::VALUE_OK ? 'OK' : 'Not OK';
    }
}
