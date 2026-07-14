<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FuelService extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'service_details' => 'array',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function closingStores()
    {
        return $this->belongsToMany(ClosingStore::class);
    }

    public function paymentReceipts()
    {
        return $this->belongsToMany(PaymentReceipt::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function getFuelServiceNameAttribute()
    {
        $creatorName = '';
        if ($this->relationLoaded('createdBy') && $this->createdBy) {
            $creatorName = $this->createdBy->name;
        } elseif ($this->created_by_id) {
            if (!is_numeric($this->created_by_id)) {
                $user = \App\Models\User::withTrashed()->where('uuid', $this->created_by_id)->first();
                if ($user) $creatorName = $user->name;
            } else {
                $user = \App\Models\User::withTrashed()->find($this->created_by_id);
                if ($user) $creatorName = $user->name;
            }
        }

        $typeStr = $this->fuel_service == 1 ? 'Fuel' : ($this->fuel_service == 2 ? 'Service' : '');

        $fuelServiceDetails = [
            ($this->vehicle?->no_register ? : ''),
            ($typeStr ? : ''),
            ($this->date ? : ''),
            ($creatorName ? : ''),
            ('Rp ' . number_format($this->amount) ? : ''),
        ];

        return implode(" | ", array_filter($fuelServiceDetails));
    }
}
