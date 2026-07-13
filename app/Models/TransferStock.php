<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferStock extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    const STATUS_BELUM_DIPERIKSA = 1;
    const STATUS_VALID = 2;
    const STATUS_DIPERBAIKI = 3;
    const STATUS_PERIKSA_ULANG = 4;

    protected $guarded = [];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        $path = $this->attributes['image'] ?? null;
        if (!$path) {
            return null;
        }
        return \App\Support\ImageUrlResolver::resolve($path);
    }

    public function storeFrom(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function storeTo(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function productTransferStocks(): HasMany
    {
        return $this->hasMany(ProductTransferStock::class);
    }
}
