<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sales order kategori Employee (for = 2) — penjualan oleh sales ke customer.
 *
 * Memakai tabel `sales_orders` yang sama dengan tipe penjualan lainnya
 * (Direct = 1, Online = 3). Filter `for = 2` diterapkan di controller,
 * BUKAN global scope, agar tetap kompatibel dengan query yang join/union
 * antar tipe (mis. SalesDashboardController).
 *
 * Sumber referensi: apps/admin/app/Filament/Resources/Panel/SalesOrderEmployeesResource
 * (model kembaran: App\Models\SalesOrderOnline untuk for=3).
 */
class SalesOrderEmployee extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mysql';
    protected $table = 'sales_orders';
    protected $guarded = [];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function transferToAccount(): BelongsTo
    {
        return $this->belongsTo(TransferToAccount::class);
    }

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(DeliveryAddress::class);
    }

    public function detailSalesOrders(): HasMany
    {
        return $this->hasMany(DetailSalesOrder::class, 'sales_order_id');
    }
}
