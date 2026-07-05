<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Bank extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }
}
