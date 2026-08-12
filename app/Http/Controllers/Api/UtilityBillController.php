<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UtilityBill;
use Illuminate\Http\Request;

class UtilityBillController extends Controller
{
    public function index(Request $request)
    {
        $query = UtilityBill::with([
            'utility.store:id,nickname',
            'utility.utilityProvider:id,name',
            'utility.unit:id,unit',
        ]);

        if ($request->filled('store_id')) {
            $query->whereHas('utility', fn($q) => $q->where('store_id', $request->store_id));
        }

        if ($request->filled('utility_id')) {
            $query->where('utility_id', $request->utility_id);
        }

        $bills = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $bills->items(),
            'meta' => [
                'current_page' => $bills->currentPage(),
                'last_page' => $bills->lastPage(),
                'per_page' => $bills->perPage(),
                'total' => $bills->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'utility_id' => 'required|exists:utilities,id',
            'date' => 'required|date',
            'amount' => 'required|string',
            'initial_indicator' => 'required|numeric',
            'last_indicator' => 'required|numeric',
            'image' => 'nullable|string',
        ]);

        $bill = UtilityBill::create([
            'utility_id' => $validated['utility_id'],
            'date' => $validated['date'],
            'amount' => $validated['amount'],
            'initial_indicator' => $validated['initial_indicator'],
            'last_indicator' => $validated['last_indicator'],
            'image' => $validated['image'] ?? null,
        ]);

        $bill->load([
            'utility.store:id,nickname',
            'utility.utilityProvider:id,name',
            'utility.unit:id,unit',
        ]);

        return response()->json([
            'success' => true,
            'data' => $bill,
        ], 201);
    }

    public function show($id)
    {
        $bill = UtilityBill::with([
            'utility.store:id,nickname',
            'utility.utilityProvider:id,name',
            'utility.unit:id,unit',
        ])->find($id);

        if (!$bill) {
            return response()->json([
                'success' => false,
                'message' => 'Tagihan utility tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $bill,
        ]);
    }

    public function update(Request $request, $id)
    {
        $bill = UtilityBill::find($id);

        if (!$bill) {
            return response()->json([
                'success' => false,
                'message' => 'Tagihan utility tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'utility_id' => 'required|exists:utilities,id',
            'date' => 'required|date',
            'amount' => 'required|string',
            'initial_indicator' => 'required|numeric',
            'last_indicator' => 'required|numeric',
            'image' => 'nullable|string',
        ]);

        $bill->update([
            'utility_id' => $validated['utility_id'],
            'date' => $validated['date'],
            'amount' => $validated['amount'],
            'initial_indicator' => $validated['initial_indicator'],
            'last_indicator' => $validated['last_indicator'],
            'image' => array_key_exists('image', $validated) ? $validated['image'] : $bill->image,
        ]);

        $bill->load([
            'utility.store:id,nickname',
            'utility.utilityProvider:id,name',
            'utility.unit:id,unit',
        ]);

        return response()->json([
            'success' => true,
            'data' => $bill,
        ]);
    }

    public function destroy($id)
    {
        $bill = UtilityBill::find($id);

        if (!$bill) {
            return response()->json([
                'success' => false,
                'message' => 'Tagihan utility tidak ditemukan.',
            ], 404);
        }

        $bill->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tagihan utility berhasil dihapus.',
        ]);
    }
}
