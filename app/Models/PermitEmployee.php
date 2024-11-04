<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class PermitEmployee extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'from_date' => 'date',
        'until_date' => 'date',
    ];

    // Reason constants
    const REASON_MARRIED = '1';
    const REASON_SICK = '2';
    const REASON_HOMETOWN = '3';
    const REASON_HOLIDAY = '4';
    const REASON_FAMILY_DEATH = '5';

    // Status constants
    const STATUS_PENDING = '1';
    const STATUS_APPROVED = '2';
    const STATUS_REJECTED = '3';
    const STATUS_RESUBMIT = '4';

    public static function getReasonText($reason)
    {
        return [
            self::REASON_MARRIED => 'menikah',
            self::REASON_SICK => 'sakit',
            self::REASON_HOMETOWN => 'pulkam',
            self::REASON_HOLIDAY => 'libur',
            self::REASON_FAMILY_DEATH => 'keluarga meninggal',
        ][$reason] ?? null;
    }

    public static function getStatusText($status)
    {
        return [
            self::STATUS_PENDING => 'belum disetujui',
            self::STATUS_APPROVED => 'disetujui',
            self::STATUS_REJECTED => 'tidak disetujui',
            self::STATUS_RESUBMIT => 'pengajuan ulang',
        ][$status] ?? null;
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    // Scope untuk filter berdasarkan status
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeResubmit($query)
    {
        return $query->where('status', self::STATUS_RESUBMIT);
    }

    public static function isOnLeave($userId, $date = null)
    {
        $date = $date ?: Carbon::now();

        return static::where('created_by_id', $userId)
            ->where('status', static::STATUS_APPROVED)
            ->where(function ($query) use ($date) {
                $query->whereDate('from_date', '<=', $date)
                    ->whereDate('until_date', '>=', $date);
            })
            ->exists();
    }

    public static function getActiveLeave($userId, $date = null)
    {
        $date = $date ?: Carbon::now();

        return static::where('created_by_id', $userId)
            ->where('status', static::STATUS_APPROVED)
            ->where(function ($query) use ($date) {
                $query->whereDate('from_date', '<=', $date)
                    ->whereDate('until_date', '>=', $date);
            })
            ->first();
    }
}
