<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Cashless extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    public function accountCashless()
    {
        return $this->belongsTo(AccountCashless::class);
    }

    public function closingStore()
    {
        return $this->belongsTo(ClosingStore::class);
    }
}
