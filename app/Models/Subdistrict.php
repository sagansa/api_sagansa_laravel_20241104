<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subdistrict extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    public function district()
    {
        return $this->belongsTo(District::class);
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
