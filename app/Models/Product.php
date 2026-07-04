<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    protected $connection = 'mysql';
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function detailRequests()
    {
        return $this->hasMany(DetailRequest::class);
    }
}
