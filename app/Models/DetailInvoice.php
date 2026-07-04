<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
}
