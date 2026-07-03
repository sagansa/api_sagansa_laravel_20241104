<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LocationRequest extends Model
{
    use HasFactory;

    // Tabel ini hanya memakai created_at (lihat migration: tidak ada updated_at).
    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'fulfilled_at' => 'datetime:Y-m-d H:i:s',
        'timed_out_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * Pegawai yang dilacak.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Admin yang memicu permintaan.
     */
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * Lokasi yang dikirim device sebagai jawaban atas request ini.
     */
    public function location()
    {
        return $this->hasOne(EmployeeLocation::class, 'request_id', 'request_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
