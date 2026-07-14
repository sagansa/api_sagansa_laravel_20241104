<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Utility;

class UtilityController extends Controller
{
    public function index(Request $request)
    {
        $query = Utility::where('status', '1')
            ->with('store:id,nickname')
            ->with('utilityProvider:id,name')
            ->with('unit:id,unit');

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $utilities = $query
            ->orderBy('number', 'asc')
            ->select('id', 'number', 'name', 'store_id', 'utility_provider_id', 'unit_id', 'category')
            ->get();

        $data = $utilities->map(function ($utility) {
            return [
                'id' => $utility->id,
                'utility_name' => $utility->utility_name,
                'unit' => $utility->unit?->unit,
                'unit_id' => $utility->unit_id,
                'category' => (int) $utility->category,
                'store_id' => $utility->store_id,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
