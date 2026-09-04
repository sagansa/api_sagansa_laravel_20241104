<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrder extends Model
{
    protected $connection = 'mysql';

    protected $table = 'sales_orders';

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
