<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Room extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function hygieneOfRooms()
    {
        return $this->hasMany(HygieneOfRoom::class);
    }
}
