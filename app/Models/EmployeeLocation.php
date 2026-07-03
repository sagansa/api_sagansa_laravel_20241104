<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeLocation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy' => 'float',
        'captured_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * Pegawai pemilik titik lokasi ini (loose reference, cross-DB ke mysql_auth).
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Relasi opsional ke request on-demand yang memicu lokasi ini.
     */
    public function request()
    {
        return $this->belongsTo(LocationRequest::class, 'request_id', 'request_id');
    }

    public function scopeForEmployee($query, $userId)
    {
        return $query->where('created_by_id', $userId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('captured_at', 'desc');
    }
}
