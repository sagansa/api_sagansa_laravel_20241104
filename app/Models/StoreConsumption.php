<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model ini dipetakan ke tabel `stock_cards` dengan scope
 * `for = 'store_consumption'`. Merupakan sumber laporan konsumsi toko
 * (konsumsi bahan baku untuk operasional toko).
 */
class StoreConsumption extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'stock_cards';
    protected $guarded = [];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function detailStockCards(): HasMany
    {
        return $this->hasMany(DetailStockCard::class, 'stock_card_id');
    }
}
