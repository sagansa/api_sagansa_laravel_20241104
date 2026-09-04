<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'sales_orders';

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
