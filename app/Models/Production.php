<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Production extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $guarded = [];

    protected $casts = [
        'applied_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionItem::class);
    }

    public function inputItems(): HasMany
    {
        return $this->hasMany(ProductionItem::class)->where('direction', 'in');
    }

    public function outputItems(): HasMany
    {
        return $this->hasMany(ProductionItem::class)->where('direction', 'out');
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }
}
