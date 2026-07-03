<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollPeriodSetting extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $table = 'payroll_period_settings';

    protected $guarded = [];

    protected $casts = [
        'start_day' => 'integer',
        'end_day' => 'integer',
        'transport_allowance_per_day' => 'decimal:2',
        'meal_allowance_per_day' => 'decimal:2',
        'late_penalty_per_hour' => 'decimal:2',
        'no_checkout_penalty' => 'decimal:2',
    ];
}
