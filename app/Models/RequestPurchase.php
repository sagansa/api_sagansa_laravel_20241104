<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RequestPurchase extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function detailRequests()
    {
        return $this->hasMany(DetailRequest::class);
    }
}
