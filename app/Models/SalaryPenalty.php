<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryPenalty extends Model
{
    protected $connection = 'mysql';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function monthlySalary()
    {
        return $this->belongsTo(MonthlySalary::class);
    }
}
