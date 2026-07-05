<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class District extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function subdistricts()
    {
        return $this->hasMany(Subdistrict::class);
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
