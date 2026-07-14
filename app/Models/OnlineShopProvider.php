<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OnlineShopProvider extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $guarded = [];
}
