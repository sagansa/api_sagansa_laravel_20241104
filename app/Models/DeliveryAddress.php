<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Alamat pengiriman customer. Di mobile sales, yang dipakai adalah
 * delivery_address milik user yang login (`user_id = Auth::id()`).
 *
 * Kembaran App\Models\DeliveryAddress di apps/admin. Label default:
 * `delivery_address_name` = name (jika ada) atau recipient_name.
 */
class DeliveryAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mysql';
    protected $guarded = [];

    protected $appends = ['delivery_address_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function subdistrict(): BelongsTo
    {
        return $this->belongsTo(Subdistrict::class);
    }

    public function postalCode(): BelongsTo
    {
        return $this->belongsTo(PostalCode::class);
    }

    public function getDeliveryAddressNameAttribute(): ?string
    {
        if (!empty($this->name)) {
            return $this->name;
        }
        if (!empty($this->recipient_name)) {
            $phone = $this->recipient_telp_no ? " ({$this->recipient_telp_no})" : '';
            return $this->recipient_name . $phone;
        }
        return $this->address;
    }
}
