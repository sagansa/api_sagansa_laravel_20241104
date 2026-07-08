<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InvoicePurchase extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $fillable = [
        'image',
        'payment_type_id',
        'store_id',
        'supplier_id',
        'date',
        'taxes',
        'discounts',
        'total_price',
        'notes',
        'created_by_id',
        'payment_status',
        'order_status',
    ];

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function detailInvoices()
    {
        return $this->hasMany(DetailInvoice::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
