<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MonthlySalary extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $table = 'monthly_salaries';

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_work_days' => 'integer',
        'total_hours' => 'decimal:2',
        'base_salary' => 'decimal:2',
        'daily_salary_total' => 'decimal:2',
        'allowances' => 'array',
        'deductions' => 'array',
        'total_salary' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'status' => 'integer',
        'payment_date' => 'datetime',
    ];

    const STATUS_DRAFT = 1;
    const STATUS_APPROVED = 2;
    const STATUS_PAID = 3;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function presences()
    {
        return $this->belongsToMany(
            Presence::class,
            'monthly_salary_presence',
            'monthly_salary_id',
            'presence_id'
        );
    }
}
