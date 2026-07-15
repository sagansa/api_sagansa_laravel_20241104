<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLoan extends Model
{
    protected $connection = 'mysql';

    protected $guarded = [];

    protected $casts = [
        'loan_date' => 'date',
        'amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function installments()
    {
        return $this->hasMany(LoanInstallment::class);
    }
}
