<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Store extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shiftStores()
    {
        return $this->hasMany(ShiftStore::class);
    }

    public function presences()
    {
        return $this->hasMany(Presence::class);
    }
}