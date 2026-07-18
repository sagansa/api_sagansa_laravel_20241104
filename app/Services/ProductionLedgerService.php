<?php

namespace App\Services;

use App\Models\Production;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Twin dari App\Services\ProductionLedgerService di apps/admin.
 *
 * Twin disengaja karena apps/admin & services/api adalah backend terpisah
 * (sesuai doc/prd/design.md). Kedua twin harus dijaga sinkron bila ada
 * perubahan logika ledger.
 *
 * Idempoten via kolom `productions.applied_at`:
 *  • apply() hanya jalan kalau applied_at masih null → set applied_at = now()
 *  • revert() hanya jalan kalau applied_at tidak null → set applied_at = null
 */
class ProductionLedgerService
{
    /**
     * Apply mutasi stok: kurangi ingredient, tambah output.
     *
     * @return bool true jika sukses (atau sudah pernah di-apply).
     */
    public function apply(Production $production): bool
    {
        if ($production->isApplied()) {
            return true;
        }

        try {
            DB::connection('mysql')->transaction(function () use ($production) {
                foreach ($production->items as $item) {
                    $delta = $item->direction === 'out'
                        ? (int) $item->quantity
                        : -1 * (int) $item->quantity;

                    Product::where('id', $item->product_id)
                        ->increment('stock', $delta);
                }

                $production->forceFill(['applied_at' => now()])->save();
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('ProductionLedgerService::apply gagal', [
                'production_id' => $production->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Balik mutasi stok: kembalikan ingredient, hapus output.
     */
    public function revert(Production $production): bool
    {
        if (!$production->isApplied()) {
            return true;
        }

        try {
            DB::connection('mysql')->transaction(function () use ($production) {
                foreach ($production->items as $item) {
                    $delta = $item->direction === 'out'
                        ? -1 * (int) $item->quantity
                        : (int) $item->quantity;

                    Product::where('id', $item->product_id)
                        ->increment('stock', $delta);
                }

                $production->forceFill(['applied_at' => null])->save();
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('ProductionLedgerService::revert gagal', [
                'production_id' => $production->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
