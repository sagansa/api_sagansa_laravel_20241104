<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekening tujuan transfer (master data). Memakai accessor transfer_name
 * dan transfer_account_name agar mudah ditampilkan di dropdown mobile/web.
 *
 * Kembaran App\Models\TransferToAccount di apps/admin.
 */
class TransferToAccount extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $guarded = [];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Label lengkap: "{bank} - {number} - {name}".
     */
    public function getTransferNameAttribute(): string
    {
        return ($this->bank?->name ?? '') . ' - ' . $this->number . ' - ' . $this->name;
    }

    /**
     * Label singkat: "{bank} - {number}".
     */
    public function getTransferAccountNameAttribute(): string
    {
        return ($this->bank?->name ?? '') . ' - ' . $this->number;
    }
}
