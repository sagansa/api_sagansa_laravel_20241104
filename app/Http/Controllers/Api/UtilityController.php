<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Utility;
use Illuminate\Http\Request;

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
            ->select('id', 'number', 'name', 'store_id', 'utility_provider_id', 'unit_id', 'category', 'status', 'pre_post')
            ->get();

        $data = $utilities->map(function ($utility) {
            return [
                'id' => $utility->id,
                'number' => $utility->number,
                'name' => $utility->name,
                'status' => (int) $utility->status,
                'pre_post' => (int) $utility->pre_post,
                'category' => (int) $utility->category,
                'store_id' => $utility->store_id,
                'store_nickname' => $utility->store?->nickname,
                'utility_provider_id' => $utility->utility_provider_id,
                'utility_provider_name' => $utility->utilityProvider?->name,
                'unit_id' => $utility->unit_id,
                'unit' => $utility->unit?->unit,
                'utility_name' => $utility->utility_name,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
