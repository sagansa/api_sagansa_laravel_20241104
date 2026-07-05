<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AccountCashless extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    public function cashlessProvider()
    {
        return $this->belongsTo(CashlessProvider::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function storeCashless()
    {
        return $this->belongsTo(StoreCashless::class);
    }

    public function cashlesses()
    {
        return $this->hasMany(Cashless::class);
    }

    public function getAccountCashlessNameAttribute()
    {
        $name = $this->cashlessProvider->name . ' | ' ;
        $email = $this->email ? $this->email . ' | ' : '';
        $username = $this->username ? $this->username . ' | ' : '';
        $store = $this->storeCashless->name;

        return "$name $email $username $store";
    }
}
