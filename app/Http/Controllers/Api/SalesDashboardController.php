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
        return ['periode_label' => $range['label'], 'omzet' => 0, 'order_count' => 0, 'total_qty' => 0];
    }

    private function trendView(array $range, string $periode): array
    {
        return ['interval' => 'day', 'points' => []];
    }

    private function productsView(array $range, int $page, int $perPage, string $sort): array
    {
        return ['items' => [], 'meta' => ['current_page' => $page, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0]];
    }

    private function channelsView(array $range): array
    {
        return ['total_omzet' => 0, 'items' => []];
    }
}
