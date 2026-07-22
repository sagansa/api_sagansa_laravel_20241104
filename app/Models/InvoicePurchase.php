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

    /**
     * Cast payment_status & order_status ke string.
     *
     * Historis, kolom ini ditulis dengan tipe tidak konsisten:
     * - create: '1' (string) — ProcurementController:467
     * - bulk update via ClosingStoreController: 2 / 1 (integer)
     * - query: where('payment_status', 3) (integer literal)
     *
     * Tanpa cast, strict comparison `$inv->payment_status !== '1'` gagal
     * karena nilai DB bisa int(1). Cast ke string menormalisasi semua
     * baca sehingga comparison konsisten.
     */
    protected $casts = [
        'payment_status' => 'string',
        'order_status' => 'string',
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

    public function paymentReceipts()
    {
        return $this->belongsToMany(PaymentReceipt::class)
            ->distinct();
    }

    public function closingStores()
    {
        return $this->belongsToMany(ClosingStore::class);
    }
}
