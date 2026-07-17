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

    private function summaryView(array $range): array
    {
        $baseSo = DB::table('sales_orders as so')
            ->whereNull('so.deleted_at')
            ->where('so.delivery_status', 3)
            ->whereBetween('so.delivery_date', [$range['from'], $range['to']]);

        $omzet   = (clone $baseSo)->sum('so.total_price');
        $orders  = (clone $baseSo)->count('so.id');
        $qty     = (clone $baseSo)
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

        $rows = DB::table('sales_orders')
            ->whereNull('deleted_at')
            ->where('delivery_status', 3)
            ->whereBetween('delivery_date', [$range['from'], $range['to']])
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
        $baseQuery = DB::table('detail_sales_orders as dso')
            ->join('sales_orders as so', 'dso.sales_order_id', '=', 'so.id')
            ->whereNull('so.deleted_at')
            ->where('so.delivery_status', 3)
            ->whereBetween('so.delivery_date', [$range['from'], $range['to']])
            ->whereNotNull('dso.product_id')
            ->select(
                'dso.product_id',
                DB::raw('SUM(dso.quantity) as qty'),
                DB::raw('SUM(dso.subtotal_price) as revenue')
            )
            ->groupBy('dso.product_id');

        $baseQuery->orderBy($sort === 'revenue' ? 'revenue' : 'qty', 'desc');

        $total = DB::table('detail_sales_orders as dso')
            ->join('sales_orders as so', 'dso.sales_order_id', '=', 'so.id')
            ->whereNull('so.deleted_at')
            ->where('so.delivery_status', 3)
            ->whereBetween('so.delivery_date', [$range['from'], $range['to']])
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
        $rows = DB::table('sales_orders as so')
            ->leftJoin('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->whereNull('so.deleted_at')
            ->where('so.delivery_status', 3)
            ->whereBetween('so.delivery_date', [$range['from'], $range['to']])
            ->select(
                'so.for',
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(so.total_price) as omzet'),
                DB::raw('SUM(dso.quantity) as qty')
            )
            ->groupBy('so.for')
            ->get();

        $labels = ['1' => 'Direct', '2' => 'Employee', '3' => 'Online'];
        $totalOmzet = (int) $rows->sum(fn($r) => (int) $r->omzet);

        $items = $rows->map(function ($r) use ($labels, $totalOmzet) {
            $omzet = (int) $r->omzet;
            return [
                'channel'       => $r->for,
                'channel_label' => $labels[$r->for] ?? "Unknown ({$r->for})",
                'omzet'         => $omzet,
                'order_count'   => (int) $r->order_count,
                'qty'           => (int) $r->qty ?: 0,
                'percentage'    => $totalOmzet > 0 ? round(($omzet / $totalOmzet) * 100, 1) : 0.0,
            ];
        })->values();

        return [
            'total_omzet' => $totalOmzet,
            'items'       => $items,
        ];
    }
}
