<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryRateDetail extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'salary_rate_id',
        'years_of_service',
        'rate_per_hour',
    ];

    public function salaryRate()
    {
        return $this->belongsTo(SalaryRate::class);
    }
}
