<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class DetailInvoice extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (self $detailInvoice) {
            if ($detailInvoice->detail_request_id) {
                DetailRequest::where('id', $detailInvoice->detail_request_id)
                    ->where('status', '4')
                    ->update(['status' => 2]);
            }
        });
    }

    public function invoicePurchase()
    {
        return $this->belongsTo(InvoicePurchase::class);
    }

    public function detailRequest()
    {
        return $this->belongsTo(DetailRequest::class);
    }

    /**
     * Riwayat harga beli terakhir (maksimal 5) untuk product yang sama.
     */
    public function lastPurchasePrice(): array
    {
        $productId = optional($this->detailRequest)->product_id;
        if (!$productId) {
            return [];
        }

        // Cari detail_invoices lain dengan product yang sama, lebih dahulu dari
        // invoice saat ini (created_at di invoice_purchase).
        $currentDate = optional($this->invoicePurchase)->created_at;

        $rows = self::query()
            ->join('detail_requests as dr', 'detail_invoices.detail_request_id', '=', 'dr.id')
            ->join('invoice_purchases as ip', 'detail_invoices.invoice_purchase_id', '=', 'ip.id')
            ->leftJoin('suppliers as s', 'ip.supplier_id', '=', 's.id')
            ->where('dr.product_id', $productId)
            ->where('detail_invoices.id', '!=', $this->id)
            ->where('detail_invoices.quantity_product', '>', 0)
            ->when($currentDate, fn($q) => $q->where('ip.created_at', '<', $currentDate))
            ->orderByDesc('ip.created_at')
            ->select(
                DB::raw('detail_invoices.subtotal_invoice / detail_invoices.quantity_product as unit_price'),
                's.name as supplier_name',
                'ip.created_at as date'
            )
            ->limit(5)
            ->get();

        return $rows->map(fn($row) => [
            'unit_price' => (int) round($row->unit_price),
            'supplier_name' => $row->supplier_name,
            'date' => $row->date,
        ])->values()->all();
    }

    /**
     * Accessor: appends last_purchase_price saat serialize (dipakai admin).
     * Return daftar riwayat harga (maksimal 5).
     */
    public function getLastPurchasePriceAttribute(): array
    {
        // Hanya compute bila dipanggil eksplisit (via appends di showInvoice admin)
        // untuk hindari N+1 pada list. Default tidak di-append.
        return $this->lastPurchasePrice();
    }
}
