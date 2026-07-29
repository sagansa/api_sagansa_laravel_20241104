<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class DetailRequest extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function requestPurchase()
    {
        return $this->belongsTo(RequestPurchase::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class);
    }

    public function detailInvoices()
    {
        return $this->hasMany(DetailInvoice::class);
    }

    /**
     * Harga beli terakhir untuk product ini (lintas supplier).
     * Query invoice purchase terbaru untuk product yang sama.
     */
    public function lastPurchasePrice(): ?array
    {
        $row = DB::table('detail_invoices as di')
            ->join('invoice_purchases as ip', 'di.invoice_purchase_id', '=', 'ip.id')
            ->leftJoin('suppliers as s', 'ip.supplier_id', '=', 's.id')
            ->join('detail_requests as dr', 'di.detail_request_id', '=', 'dr.id')
            ->where('dr.product_id', $this->product_id)
            ->where('di.quantity_product', '>', 0)
            ->orderByDesc('ip.created_at')
            ->select(
                DB::raw('di.subtotal_invoice / di.quantity_product as unit_price'),
                's.name as supplier_name',
                'ip.created_at as date'
            )
            ->first();

        if (!$row) return null;

        return [
            'unit_price' => (int) round($row->unit_price),
            'supplier_name' => $row->supplier_name,
            'date' => $row->date,
        ];
    }

    /**
     * Accessor: appends last_purchase_price saat serialize (dipakai admin).
     */
    public function getLastPurchasePriceAttribute(): ?array
    {
        return $this->lastPurchasePrice();
    }
}
