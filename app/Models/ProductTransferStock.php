<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductTransferStock extends Pivot
{
    protected $connection = 'mysql';
    protected $guarded = [];
    public $timestamps = false;

    public function transferStock(): BelongsTo
    {
        return $this->belongsTo(TransferStock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
