<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductStorageStock extends Pivot
{
    protected $connection = 'mysql';
    public $timestamps = false;
    protected $table = 'product_storage_stock';

    public function storageStock(): BelongsTo
    {
        return $this->belongsTo(StorageStock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
