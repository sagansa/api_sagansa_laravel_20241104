<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot item produksi (bahan baku / output hasil).
 * direction = in  → bahan baku yang dikonsumsi (stok berkurang saat apply)
 * direction = out → produk hasil produksi (stok bertambah saat apply)
 */
class ProductionItem extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function detailInvoice(): BelongsTo
    {
        return $this->belongsTo(DetailInvoice::class);
    }

    public function recipeIngredient(): BelongsTo
    {
        return $this->belongsTo(RecipeIngredient::class);
    }

    public function scopeInputs($query)
    {
        return $query->where('direction', 'in');
    }

    public function scopeOutputs($query)
    {
        return $query->where('direction', 'out');
    }
}
