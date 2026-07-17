<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to'   => $dateTo,
                    'store_ids' => $storeIds,
                ],
                'summary' => [
                    'products_compared' => 0,
                    'match_count' => 0,
                    'mismatch_count' => 0,
                    'no_so_data_count' => 0,
                    'no_stock_data_count' => 0,
                    'total_sold_qty' => 0,
                    'total_stock_out_qty' => 0,
                ],
                'items' => [],
            ],
            'meta' => [
                'current_page' => $page,
                'last_page'    => 1,
                'per_page'     => $perPage,
                'total'        => 0,
            ],
        ]);
    }
}
