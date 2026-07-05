<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Supplier extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    public function fuelServices()
    {
        return $this->hasMany(FuelService::class);
    }
}
