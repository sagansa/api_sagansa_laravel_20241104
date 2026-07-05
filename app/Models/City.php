<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class City extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    public function districts()
    {
        return $this->hasMany(District::class);
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }

    public function postalCodes()
    {
        return $this->hasMany(PostalCode::class);
    }
}
