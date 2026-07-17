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

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['date_from' => null, 'date_to' => null, 'store_ids' => []],
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
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
        ]);
    }
}
