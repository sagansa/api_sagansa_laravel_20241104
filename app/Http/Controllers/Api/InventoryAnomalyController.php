<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // v1 partial: items = sold-only, stock integration di task berikutnya.
        $productIds = array_keys($soldMap);
        $items = [];
        foreach ($productIds as $pid) {
            $items[] = [
                'product_id'    => $pid,
                'product_name'  => null,
                'unit'          => null,
                'sold_qty'      => $soldMap[$pid],
                'stock_before'  => null,
                'stock_after'   => null,
                'stock_diff'    => null,
                'delta'         => null,
                'status'        => 'no_stock_data',
                'store_breakdown' => null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to'   => $dateTo,
                    'store_ids' => $storeIds,
                ],
                'summary' => [
                    'products_compared' => count($items),
                    'match_count' => 0,
                    'mismatch_count' => 0,
                    'no_so_data_count' => 0,
                    'no_stock_data_count' => count($items),
                    'total_sold_qty' => array_sum($soldMap),
                    'total_stock_out_qty' => 0,
                ],
                'items' => $items,
            ],
            'meta' => [
                'current_page' => $page,
                'last_page'    => 1,
                'per_page'     => $perPage,
                'total'        => count($items),
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
}
