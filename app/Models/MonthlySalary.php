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

    public function dailySalaries()
    {
        return $this->hasMany(DailySalary::class, 'monthly_salary_id');
    }

    /**
     * Total gaji harian dihitung on-the-fly berdasarkan tanggal periode slip
     * (period_start s/d period_end), user pemilik slip, dan status daily salary.
     *
     * Hanya daily salary berstatus sudah dibayar (2) atau siap dibayar (3) yang
     * dijumlahkan. Pendekatan berbasis tanggal (bukan link monthly_salary_id)
     * dipakai karena link mudah ter-reset/hilang saat regenerate, sedangkan
     * tanggal & status selalu reliable.
     *
     * Mengesampingkan nilai snapshot pada kolom `daily_salary_total`. Setiap kali
     * status daily salary berubah, total slip otomatis menyesuaikan tanpa perlu
     * regenerate.
     */
    public function getDailySalaryTotalAttribute($value)
    {
        return (float) DailySalary::whereBetween('date', [
                $this->period_start,
                $this->period_end,
            ])
            ->where(function ($q) {
                $q->where('created_by_id', $this->user_id)
                  ->orWhereHas('presence', fn ($pq) => $pq->where('created_by_id', $this->user_id));
            })
            ->whereIn('status', [2, 3])
            ->sum('amount');
    }

    /**
     * Total gaji akhir dihitung on-the-fly: base_salary - potongan + gaji harian.
     *
     * Mengesampingkan nilai snapshot kolom `total_salary`/`amount` agar selalu
     * konsisten dengan accessor daily_salary_total. Dengan begini, ketika status
     * daily salary berubah (mis. dari unpaid menjadi paid), total slip otomatis
     * menyesuaikan tanpa perlu regenerate.
     */
    public function getTotalSalaryAttribute($value)
    {
        $deductions = $this->deductions ?? [];
        $totalDeductions = (float) ($deductions['late_penalties'] ?? 0)
            + (float) ($deductions['manual_penalties'] ?? 0)
            + (float) ($deductions['loan_installments'] ?? 0);

        $result = (float) $this->base_salary - $totalDeductions + $this->daily_salary_total;

        return max(0, $result);
    }
}
