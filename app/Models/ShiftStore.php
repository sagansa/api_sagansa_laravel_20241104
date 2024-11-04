<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShiftStore extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getShiftStartTimeAttribute($value)
    {
        return date('H:i:s', strtotime($value));
    }

    public function getShiftEndTimeAttribute($value)
    {
        return date('H:i:s', strtotime($value));
    }

    public function presences()
    {
        return $this->hasMany(Presence::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
