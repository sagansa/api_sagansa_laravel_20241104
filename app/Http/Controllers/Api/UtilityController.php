<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Utility;

class UtilityController extends Controller
{
    public function index()
    {
        $utilities = Utility::where('status', '1')
            ->with('store:id,nickname')
            ->with('utilityProvider:id,name')
            ->orderBy('number', 'asc')
            ->select('id', 'number', 'name', 'store_id', 'utility_provider_id')
            ->get();

        $data = $utilities->map(function ($utility) {
            return [
                'id' => $utility->id,
                'utility_name' => $utility->utility_name,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
