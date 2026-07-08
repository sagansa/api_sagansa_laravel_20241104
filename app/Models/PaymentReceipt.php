<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'image',
        'amount',
        'payment_for',
        'image_adjust',
        'notes',
        'total_amount',
        'transfer_amount',
        'supplier_id',
        'user_id',
    ];

    public function invoicePurchases()
    {
        return $this->belongsToMany(InvoicePurchase::class)
            ->distinct();
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
