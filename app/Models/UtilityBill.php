<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UtilityBill extends Model
{
    protected $connection = 'mysql';
    use HasFactory;
    protected $guarded = [];

    public function utility(): BelongsTo
    {
        return $this->belongsTo(Utility::class);
    }
}
