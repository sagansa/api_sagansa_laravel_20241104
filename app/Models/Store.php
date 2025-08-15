<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'nickname',
        'address',
        'latitude',
        'longitude',
        'radius',
        'is_active'
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius' => 'integer',
        'is_active' => 'boolean'
    ];

    public function shiftStores()
    {
        return $this->hasMany(ShiftStore::class);
    }

    public function presences()
    {
        return $this->hasMany(Presence::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}