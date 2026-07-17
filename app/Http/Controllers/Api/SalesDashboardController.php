<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validated = $request->validate([
            'periode' => ['nullable', 'in:today,yesterday,month,year'],
            'view'    => ['nullable', 'in:summary,trend,products,channels'],
            'page'    => ['nullable', 'integer', 'min:1'],
            'per_page'=> ['nullable', 'integer', 'min:1', 'max:200'],
            'sort'    => ['nullable', 'in:qty,revenue'],
        ]);

        $periode = $validated['periode'] ?? 'today';
        $view    = $validated['view']    ?? 'summary';
        $page    = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(max(1, (int) ($validated['per_page'] ?? 50)), 200);
        $sort    = $validated['sort']    ?? 'qty';

        $range = $this->resolveRange($periode);

        return match($view) {
            'summary'  => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'summary', 'periode' => $periode],
                    $this->summaryView($range),
                ),
            ]),
            'trend'    => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'trend', 'periode' => $periode],
                    $this->trendView($range, $periode),
                ),
            ]),
            'products' => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'products', 'periode' => $periode, 'sort' => $sort],
                    $this->productsView($range, $page, $perPage, $sort),
                ),
            ]),
            'channels' => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'channels', 'periode' => $periode],
                    $this->channelsView($range),
                ),
            ]),
        };
    }

    private function resolveRange(string $periode): array
    {
        $now = Carbon::now('Asia/Jakarta');
        return match($periode) {
            'today'     => [
                'from'  => $now->copy()->startOfDay()->toDateTimeString(),
                'to'    => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => $now->format('d M Y'),
            ],
            'yesterday' => [
                'from'  => $now->copy()->subDay()->startOfDay()->toDateTimeString(),
                'to'    => $now->copy()->subDay()->endOfDay()->toDateTimeString(),
                'label' => $now->copy()->subDay()->format('d M Y'),
            ],
            'month'     => [
                'from'  => $now->copy()->startOfMonth()->startOfDay()->toDateTimeString(),
                'to'    => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => $now->format('M Y') . ' (s/d hari ini)',
            ],
            'year'      => [
                'from'  => $now->copy()->startOfYear()->startOfDay()->toDateTimeString(),
                'to'    => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => $now->format('Y') . ' (s/d hari ini)',
            ],
        };
    }

    /**
     * Base query: delivered sales orders (delivery_status=3, not soft-deleted)
     * within the given date range. Use as starting point for all view queries.
     */
    private function deliveredSalesOrders(array $range): \Illuminate\Database\Query\Builder
    {
        return DB::table('sales_orders as so')
            ->whereNull('so.deleted_at')
            ->where('so.delivery_status', 3) // 3 = terkirim
            ->whereBetween('so.delivery_date', [$range['from'], $range['to']]);
    }

    private function summaryView(array $range): array
    {
        $omzet   = $this->deliveredSalesOrders($range)->sum('so.total_price');
        $orders  = $this->deliveredSalesOrders($range)->count('so.id');
        $qty     = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->sum('dso.quantity');

        return [
            'periode_label' => $range['label'],
            'omzet'         => (int) $omzet,
            'order_count'   => (int) $orders,
            'total_qty'     => (int) $qty,
        ];
    }

    private function trendView(array $range, string $periode): array
    {
        [$selectExpr, $interval, $allBuckets] = $this->trendConfig($periode);

        $rows = $this->deliveredSalesOrders($range)
            ->selectRaw($selectExpr . " as bucket, SUM(total_price) as omzet")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = collect($allBuckets)->map(function ($bucket) use ($rows) {
            return [
                'label' => $bucket,
                'omzet' => (int) ($rows[$bucket]->omzet ?? 0),
            ];
        })->values();

        return [
            'interval' => $interval,
            'points'   => $points,
        ];
    }

    private function trendConfig(string $periode): array
    {
        $now = Carbon::now('Asia/Jakarta');
        return match($periode) {
            'today', 'yesterday' => [
                "DATE_FORMAT(delivery_date, '%H:00')",
                'hour',
                array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23)),
            ],
            'month' => [
                "DATE(delivery_date)",
                'day',
                collect(range(1, $now->day))->map(fn($d) =>
                    $now->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT)
                )->all(),
            ],
            'year' => [
                "DATE_FORMAT(delivery_date, '%Y-%m')",
                'month',
                array_map(fn($m) =>
                    $now->format('Y-') . str_pad($m, 2, '0', STR_PAD_LEFT), range(1, 12)
                ),
            ],
        };
    }

    private function productsView(array $range, int $page, int $perPage, string $sort): array
    {
        $baseQuery = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->whereNotNull('dso.product_id')
            ->select(
                'dso.product_id',
                DB::raw('SUM(dso.quantity) as qty'),
                DB::raw('SUM(dso.subtotal_price) as revenue')
            )
            ->groupBy('dso.product_id')
            ->orderBy($sort === 'revenue' ? 'revenue' : 'qty', 'desc')
            ->orderBy('dso.product_id', 'asc');

        $total = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->whereNotNull('dso.product_id')
            ->distinct()
            ->count('dso.product_id');

        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $rows = (clone $baseQuery)->skip($offset)->take($perPage)->get();

        $items = $rows;
        if ($rows->isNotEmpty()) {
            $products = DB::table('products')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->whereIn('products.id', $rows->pluck('product_id'))
                ->select('products.id', 'products.name', 'units.unit')
                ->get()
                ->keyBy('id');

            $items = $rows->map(function ($r) use ($products) {
                $p = $products->get($r->product_id);
                return [
                    'product_id'   => (int) $r->product_id,
                    'product_name' => $p?->name,
                    'unit'         => $p?->unit,
                    'qty'          => (int) $r->qty,
                    'revenue'      => (int) $r->revenue,
                ];
            })->values();
        }

        return [
            'items' => $items,
            'meta'  => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ];
    }

    private function channelsView(array $range): array
    {
        // Omzet + order_count per channel — NO detail join (avoid fan-out).
        $omzetRows = $this->deliveredSalesOrders($range)
            ->select('so.for', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(so.total_price) as omzet'))
            ->groupBy('so.for')
            ->get()
            ->keyBy('for');

        // Qty per channel — detail join is safe here.
        $qtyRows = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->select('so.for', DB::raw('SUM(dso.quantity) as qty'))
            ->groupBy('so.for')
            ->get()
            ->keyBy('for');

        $labels = ['1' => 'Direct', '2' => 'Employee', '3' => 'Online'];
        $totalOmzet = (int) $omzetRows->sum(fn($r) => (int) $r->omzet);

        $allFors = $omzetRows->keys()->merge($qtyRows->keys())->unique();
        $items = $allFors->map(function ($for) use ($omzetRows, $qtyRows, $labels, $totalOmzet) {
            $omzet = (int) ($omzetRows[$for]->omzet ?? 0);
            return [
                'channel'       => $for,
                'channel_label' => $labels[$for] ?? "Unknown ({$for})",
                'omzet'         => $omzet,
                'order_count'   => (int) ($omzetRows[$for]->order_count ?? 0),
                'qty'           => (int) ($qtyRows[$for]->qty ?? 0),
                'percentage'    => $totalOmzet > 0 ? round(($omzet / $totalOmzet) * 100, 1) : 0.0,
            ];
        })->values();

        return [
            'total_omzet' => $totalOmzet,
            'items'       => $items,
        ];
    }
}
