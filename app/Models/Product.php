<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    protected $connection = 'mysql';
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_asset' => 'boolean',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function detailRequests()
    {
        return $this->hasMany(DetailRequest::class);
    }

    /**
     * Kategori aset (jika produk ditandai sebagai aset). Menentukan frekuensi
     * pemeriksaan & checklist baku untuk instance aset yang tercipta dari
     * pembelian produk ini.
     */
    public function assetCategory()
    {
        return $this->belongsTo(AssetCategory::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function scopeAsset($query)
    {
        return $query->where('is_asset', true);
    }
}
