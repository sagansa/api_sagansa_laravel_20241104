<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Utility extends Model
{
    protected $connection = 'mysql';
    use HasFactory;
    protected $guarded = [];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function utilityProvider(): BelongsTo
    {
        return $this->belongsTo(UtilityProvider::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function utilityUsages()
    {
        return $this->hasMany(UtilityUsage::class);
    }

    public function getUtilityNameAttribute()
    {
        return $this->store->nickname . ' | ' . $this->number . ' | ' . $this->utilityProvider->name;
    }

    public function getUtilityColumnNameAttribute()
    {
        return $this->store->nickname . ' | ' . $this->utilityProvider->name;
    }
}
