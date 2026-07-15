<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class Presence extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'check_in' => 'datetime:Y-m-d H:i:s',
        'check_out' => 'datetime:Y-m-d H:i:s',
        'latitude_in' => 'float',
        'longitude_in' => 'float',
        'latitude_out' => 'float',
        'longitude_out' => 'float',
        'status' => 'integer'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function shiftStore()
    {
        return $this->belongsTo(ShiftStore::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }


    // Admin-specific scopes
    public function scopeWithFullDetails($query)
    {
        return $query->with(['createdBy', 'store', 'shiftStore']);
    }
    
    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('check_in', [$startDate, $endDate]);
    }
    
    public function scopeForEmployee($query, $userId)
    {
        return $query->where('created_by_id', $userId);
    }

    /**
     * Hitung jam keterlambatan (max 2 jam). Null check-in = 2 jam.
     * Di-port dari apps/admin/app/Models/Presence.php (Opsi A).
     */
    public function calculateLateHours()
    {
        if (!$this->shiftStore) {
            return 2;
        }

        if (is_null($this->check_in)) {
            return 2;
        }

        $checkInTime = Carbon::parse($this->check_in)->format('H:i:s');
        $shiftStartTime = Carbon::parse($this->shiftStore->shift_start_time)->format('H:i:s');

        if (Carbon::parse($checkInTime)->lessThanOrEqualTo($shiftStartTime)) {
            return 0;
        }

        $lateSeconds = Carbon::parse($shiftStartTime)->diffInSeconds($checkInTime);
        $lateHours = ceil($lateSeconds / 3600);

        return min($lateHours, 2);
    }

    /**
     * Hitung penalti jam checkout cepat/null. Null check-out = 2 jam.
     * Di-port dari apps/admin/app/Models/Presence.php (Opsi A).
     */
    public function calculateCheckOutPenalty()
    {
        if (!$this->shiftStore) {
            return 2;
        }

        if (is_null($this->check_out)) {
            return 2;
        }

        $checkOutTime = Carbon::parse($this->check_out);
        $shiftEndTime = Carbon::parse($this->shiftStore->shift_end_time);

        if ($checkOutTime->lessThan($shiftEndTime) && $checkOutTime->isNextDay()) {
            $shiftEndTime->addDay();
        }

        if ($shiftEndTime->greaterThanOrEqualTo($checkOutTime)) {
            return 0;
        }

        $penaltySeconds = $shiftEndTime->diffInSeconds($checkOutTime);
        $penaltyHours = ceil($penaltySeconds / 3600);

        return $penaltyHours;
    }

    /**
     * Total penalti jam = late + checkout.
     */
    public function calculateTotalPenalty()
    {
        return $this->calculateLateHours() + $this->calculateCheckOutPenalty();
    }
}
