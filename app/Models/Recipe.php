<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master resep produksi. Lihat App\Models\Recipe di apps/admin untuk twin.
 * Twin disengaja karena apps/admin & services/api adalah backend terpisah
 * (sesuai doc/prd/design.md).
 */
class Recipe extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $connection = 'mysql';
    protected $guarded = [];

    protected $casts = [
        'output_qty' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function outputUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'output_unit_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
