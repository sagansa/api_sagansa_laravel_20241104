<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShiftStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'start_time',
        'end_time',
        'tolerance_minutes',
        'is_active'
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i:s',
        'end_time' => 'datetime:H:i:s',
        'tolerance_minutes' => 'integer',
        'is_active' => 'boolean'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
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