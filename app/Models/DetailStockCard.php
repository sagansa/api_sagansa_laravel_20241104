<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetailStockCard extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'detail_stock_cards';
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockCard(): BelongsTo
    {
        return $this->belongsTo(StockCard::class, 'stock_card_id');
    }
}
