<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vehicle extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fuelServices()
    {
        return $this->hasMany(FuelService::class);
    }

    public function getVehicleStatusAttribute()
    {
        $statuses = [
            1 => 'active',
            2 => 'inactive',
        ];

        return $this->no_register . ' - ' . $statuses[$this->status];
    }

    /**
     * KM terakhir kendaraan, diambil dari fuel service terbaru (apapun status-nya)
     * yang memiliki km > 0. Dipakai oleh form mobile untuk validasi agar
     * km input tidak lebih kecil dari km terakhir.
     *
     * Di-append ke response array lewat $append di controller (lihat vehicles()).
     */
    public function getLastKmAttribute(): ?float
    {
        $latest = $this->fuelServices()
            ->where('km', '>', 0)
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->first();

        return $latest?->km;
    }
}
