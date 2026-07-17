<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InventoryAnomalyController extends Controller
{
    public function compare(Request $request)
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('admin') && !$user->hasRole('super_admin'))) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
            'store_ids' => ['nullable', 'string'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $yesterday = now()->subDay()->toDateString();
        $dateFrom = $validated['date_from'] ?? $yesterday;
        $dateTo   = $validated['date_to']   ?? $dateFrom;
        $page     = max(1, (int) ($validated['page'] ?? 1));
        $perPage  = min(max(1, (int) ($validated['per_page'] ?? 50)), 200);
        $storeIds = array_values(array_filter(
            array_map('intval', explode(',', (string) ($validated['store_ids'] ?? ''))),
            fn($id) => $id > 0
        ));

        $soldMap = $this->buildSoldMap($dateFrom, $dateTo, $storeIds);

        $prevCutoff = Carbon::parse($dateFrom)->subDay()->toDateString();
        $stockBeforeMap = $this->buildStockMap($prevCutoff, $storeIds);
        $stockAfterMap  = $this->buildStockMap($dateTo, $storeIds);

        // Union product IDs.
        $allProductIds = array_unique(array_merge(
            array_keys($soldMap),
            array_keys($stockBeforeMap),
            array_keys($stockAfterMap)
        ));
        sort($allProductIds);

        // Hitung item + status, skip inactive.
        $allItems = [];
        foreach ($allProductIds as $pid) {
            $sold   = $soldMap[$pid] ?? 0;
            $before = array_key_exists($pid, $stockBeforeMap) ? $stockBeforeMap[$pid] : null;
            $after  = array_key_exists($pid, $stockAfterMap)  ? $stockAfterMap[$pid]  : null;
            $diff   = ($before !== null && $after !== null) ? ($after - $before) : null;

            // Skip inactive: tidak ada SO dan tidak ada pergerakan stok.
            if ($sold === 0 && ($diff === 0 || $diff === null)) {
                continue;
            }

            $stockOut = ($diff !== null && $diff < 0) ? abs($diff) : 0;
            $delta = $diff !== null ? ($sold - $stockOut) : null;

            if ($sold > 0 && $diff === null) {
                $status = 'no_stock_data';
            } elseif ($sold === 0 && $diff !== null && $diff !== 0) {
                $status = 'no_so_data';
            } elseif ($delta === 0) {
                $status = 'cocok';
            } else {
                $status = 'selisih';
            }

            $allItems[] = [
                'product_id'    => $pid,
                'product_name'  => null,
                'unit'          => null,
                'sold_qty'      => $sold,
                'stock_before'  => $before,
                'stock_after'   => $after,
                'stock_diff'    => $diff,
                'delta'         => $delta,
                'status'        => $status,
                'store_breakdown' => null,
            ];
        }

        // Sortir: anomaly (delta != 0) dulu, by |delta| desc.
        usort($allItems, function ($a, $b) {
            $aAnom = ($a['delta'] !== null && $a['delta'] !== 0) ? 1 : 0;
            $bAnom = ($b['delta'] !== null && $b['delta'] !== 0) ? 1 : 0;
            if ($aAnom !== $bAnom) {
                return $bAnom - $aAnom;
            }
            $aAbs = abs($a['delta'] ?? 0);
            $bAbs = abs($b['delta'] ?? 0);
            return $bAbs - $aAbs;
        });

        // Pagination manual.
        $total = count($allItems);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($allItems, $offset, $perPage);

        // Join product names & units untuk items yang di-page.
        if (!empty($pagedItems)) {
            $pagedIds = array_column($pagedItems, 'product_id');
            $products = DB::table('products')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->whereIn('products.id', $pagedIds)
                ->select('products.id', 'products.name', 'units.unit')
                ->get()
                ->keyBy('id');
            foreach ($pagedItems as &$it) {
                $p = $products->get($it['product_id']);
                $it['product_name'] = $p?->name;
                $it['unit']         = $p?->unit;
            }
            unset($it);
        }

        // Summary counts.
        $matchCount     = count(array_filter($allItems, fn($i) => $i['status'] === 'cocok'));
        $mismatchCount  = count(array_filter($allItems, fn($i) => $i['status'] === 'selisih'));
        $noSoCount      = count(array_filter($allItems, fn($i) => $i['status'] === 'no_so_data'));
        $noStockCount   = count(array_filter($allItems, fn($i) => $i['status'] === 'no_stock_data'));
        $totalSoldQty   = array_sum(array_map(fn($i) => $i['sold_qty'], $allItems));
        $totalStockOutQty = array_sum(array_map(
            fn($i) => ($i['stock_diff'] !== null && $i['stock_diff'] < 0) ? abs($i['stock_diff']) : 0,
            $allItems
        ));

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to'   => $dateTo,
                    'store_ids' => $storeIds,
                ],
                'summary' => [
                    'products_compared' => $total,
                    'match_count' => $matchCount,
                    'mismatch_count' => $mismatchCount,
                    'no_so_data_count' => $noSoCount,
                    'no_stock_data_count' => $noStockCount,
                    'total_sold_qty' => $totalSoldQty,
                    'total_stock_out_qty' => $totalStockOutQty,
                ],
                'items' => array_values($pagedItems),
            ],
            'meta' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ]);
    }

    /**
     * SUM(quantity) per product_id dari SO delivery_status=3 (terkirim),
     * deleted_at IS NULL, delivery_date BETWEEN [from,to], optional store filter.
     *
     * @return array<int,int>  [product_id => sold_qty]
     */
    private function buildSoldMap(string $dateFrom, string $dateTo, array $storeIds): array
    {
        $rows = DB::table('detail_sales_orders as dso')
            ->join('sales_orders as so', 'dso.sales_order_id', '=', 'so.id')
            ->whereNull('so.deleted_at')
            ->where('so.delivery_status', 3) // 3 = terkirim
            ->whereBetween('so.delivery_date', [$dateFrom, $dateTo])
            ->when(!empty($storeIds), fn($q) => $q->whereIn('so.store_id', $storeIds))
            ->select('dso.product_id', DB::raw('SUM(dso.quantity) as sold_qty'))
            ->groupBy('dso.product_id')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = (int) $r->sold_qty;
        }
        return $map;
    }

    /**
     * Snapshot terbaru per product_id sampai tanggal $cutoff (inclusive),
     * dari stock_cards for='remaining_storage'. Multi-store di-SUM.
     *
     * @return array<int,int>  [product_id => snapshot_qty]
     */
    private function buildStockMap(string $cutoff, array $storeIds): array
    {
        $storePlaceholders = '';
        $bindings = [$cutoff];
        if (!empty($storeIds)) {
            $storePlaceholders = 'AND sc2.store_id IN (' . implode(',', array_fill(0, count($storeIds), '?')) . ') ';
            $bindings = array_merge([$cutoff], $storeIds, [$cutoff], $storeIds);
        } else {
            $bindings = [$cutoff, $cutoff];
        }

        $storeClauseOuter = $storePlaceholders ? str_replace('sc2.', 'sc.', $storePlaceholders) : '';

        $sql = "
            SELECT dsc.product_id, SUM(dsc.quantity) as qty
            FROM detail_stock_cards dsc
            JOIN stock_cards sc ON dsc.stock_card_id = sc.id
            JOIN (
                SELECT dsc2.product_id, MAX(sc2.date) as max_date
                FROM detail_stock_cards dsc2
                JOIN stock_cards sc2 ON dsc2.stock_card_id = sc2.id
                WHERE sc2.for = 'remaining_storage'
                  AND sc2.date <= ? {$storePlaceholders}
                GROUP BY dsc2.product_id
            ) AS latest ON dsc.product_id = latest.product_id AND sc.date = latest.max_date
            WHERE sc.for = 'remaining_storage'
              AND sc.date <= ? {$storeClauseOuter}
            GROUP BY dsc.product_id
        ";

        $rows = DB::select($sql, $bindings);

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = (int) $r->qty;
        }
        return $map;
    }
}
