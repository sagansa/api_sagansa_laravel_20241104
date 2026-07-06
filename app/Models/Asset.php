<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Instance aset yang terlacak. Dapat tercipta:
 *   - Manual via UI manajemen aset (created_by_id terisi).
 *   - Otomatis dari invoice pembelian produk ber-flag `is_asset`
 *     (source_detail_invoice_id + product_id terisi).
 *
 * `next_check_at` adalah inti penjadwalan — diperbarui tiap kali check
 * disubmit (next = check_date + category.frequency_days), dan dipakai
 * scheduler FCM harian untuk menentukan aset yang jatuh tempo.
 */
class Asset extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $connection = 'mysql';
    protected $table = 'assets';
    protected $guarded = [];

    // Konstanta status/kondisi — dokumentasi, tidak divalidasi di sisi model.
    public const CONDITION_BAIK = 1;
    public const CONDITION_RUSAK_RINGAN = 2;
    public const CONDITION_RUSAK_BERAT = 3;
    public const CONDITION_HILANG = 4;

    public const STATUS_AKTIF = 1;
    public const STATUS_DIPELIHARA = 2;
    public const STATUS_NON_AKTIF = 3;

    protected $casts = [
        'next_check_at' => 'datetime',
        'last_check_at' => 'datetime',
        'purchase_date' => 'date',
        'condition' => 'integer',
        'status' => 'integer',
    ];

    // ---- Relasi ----------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(AssetCheck::class, 'asset_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AssetIssue::class, 'asset_id');
    }

    public function openIssues(): HasMany
    {
        return $this->issues()->where('status', AssetIssue::STATUS_OPEN);
    }

    public function lastCheck(): HasMany
    {
        return $this->checks()->latest('check_date')->limit(1);
    }

    // ---- Scopes ----------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_AKTIF);
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    /** Aset yang next_check_at <= akhir hari tertentu (default: akhir hari ini). */
    public function scopeDueBy($query, ?Carbon $by = null)
    {
        $by = $by ?? Carbon::now()->endOfDay();
        return $query->whereNotNull('next_check_at')->where('next_check_at', '<=', $by);
    }

    /** Aset yang sudah melewati tanggal next_check_at (terlambat). */
    public function scopeOverdue($query)
    {
        return $query->whereNotNull('next_check_at')->where('next_check_at', '<', Carbon::now()->startOfDay());
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->where('asset_category_id', $categoryId);
    }

    // ---- Helpers ---------------------------------------------------------

    public function getConditionTextAttribute(): string
    {
        return match ((int) $this->condition) {
            self::CONDITION_BAIK => 'Baik',
            self::CONDITION_RUSAK_RINGAN => 'Rusak Ringan',
            self::CONDITION_RUSAK_BERAT => 'Rusak Berat',
            self::CONDITION_HILANG => 'Hilang',
            default => 'Tidak Diketahui',
        };
    }

    public function getStatusTextAttribute(): string
    {
        return match ((int) $this->status) {
            self::STATUS_AKTIF => 'Aktif',
            self::STATUS_DIPELIHARA => 'Dipelihara',
            self::STATUS_NON_AKTIF => 'Non-Aktif',
            default => 'Tidak Diketahui',
        };
    }

    /**
     * Generate kode aset unik. Format: A-{incrementing 5 digit}. Dipakai saat
     * auto-link dari pembelian maupun saat input manual tanpa kode eksplisit.
     */
    public static function generateCode(): string
    {
        $last = self::withTrashed()->max('id') ?? 0;
        return 'A-' . str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Jadwalkan ulang next_check_at berdasarkan frekuensi kategori, dari titik
     * waktu tertentu. Dipanggil controller saat check disubmit.
     */
    public function rescheduleNextCheck(?Carbon $from = null): void
    {
        $from = $from ?? Carbon::now();
        if ($this->category) {
            $this->next_check_at = $this->category->computeNextCheckAt($from);
        } else {
            $this->next_check_at = $from->addDays(30);
        }
    }
}
