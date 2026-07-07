<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HygieneOfRoom extends Model
{
    use HasFactory;

    protected $casts = ['image' => 'array'];

    protected $guarded = [];

    public function hygiene()
    {
        return $this->belongsTo(Hygiene::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
