<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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

    public function validation()
    {
        return $this->hasOne(PresenceValidation::class);
    }

    // Admin-specific scopes
    public function scopeWithFullDetails($query)
    {
        return $query->with(['createdBy', 'store', 'shiftStore', 'validation']);
    }
    
    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('check_in', [$startDate, $endDate]);
    }
    
    public function scopeForEmployee($query, $userId)
    {
        return $query->where('created_by_id', $userId);
    }
}
