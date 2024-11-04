<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShiftStore extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'name' => 'string',
        'shift_start_time' => 'datetime:H:i:s',
        'shift_end_time' => 'datetime:H:i:s',
    ];

    public function presences()
    {
        return $this->hasMany(Presence::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
