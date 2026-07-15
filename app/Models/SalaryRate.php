<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryRate extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'name',
        'effective_date',
        'notes',
    ];

    public function salaryRateDetails()
    {
        return $this->hasMany(SalaryRateDetail::class);
    }
}
