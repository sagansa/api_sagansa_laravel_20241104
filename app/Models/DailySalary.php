<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailySalary extends Model
{
    use HasFactory;

    protected $table = 'daily_salaries';

    protected $fillable = [
        'store_id',
        'shift_store_id',
        'date',
        'amount',
        'payment_type_id',
        'status',
        'presence_id',
        'created_by_id',
        'approved_by_id',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function shiftStore()
    {
        return $this->belongsTo(ShiftStore::class);
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function presence()
    {
        return $this->belongsTo(Presence::class);
    }

    public function closingStores()
    {
        return $this->belongsToMany(ClosingStore::class);
    }
}
