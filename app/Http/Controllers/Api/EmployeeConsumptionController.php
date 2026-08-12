<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeConsumption;
use App\Models\DetailStockCard;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmployeeConsumptionController extends Controller
{
    /**
     * Get list of products for creating employee consumption (remaining = 1).
     */
    public function products(Request $request)
    {
        $products = Product::with('unit')
            ->where('remaining', '1')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Get list of employee consumptions (stock_cards where for = 'employee_consumption').
     */
    public function index(Request $request)
    {
        $query = EmployeeConsumption::with(['store', 'user', 'detailStockCards.product.unit'])
            ->where('for', 'employee_consumption');

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Staff only sees their own records
        if ($request->user()->hasRole('staff')) {
            $query->where('user_id', $request->user()->id);
        }

        $perPage = $request->integer('per_page', 20);
        $items = $query->orderBy('date', 'desc')->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Get detail of a specific employee consumption.
     */
    public function show($id, Request $request)
    {
        $consumption = EmployeeConsumption::with([
            'store',
            'user',
            'detailStockCards.product.unit',
        ])
            ->where('for', 'employee_consumption')
            ->find($id);

        if (!$consumption) {
            return response()->json([
                'success' => false,
                'message' => 'Sisa stok karyawan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $consumption,
        ]);
    }

    /**
     * Store new employee consumption (writes to stock_cards + detail_stock_cards).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $consumption = EmployeeConsumption::create([
                'for' => 'employee_consumption',
                'store_id' => $request->store_id,
                'date' => $request->date,
                'user_id' => $request->user()->id,
                'status' => 1, // Default status: Belum diperiksa
            ]);

            foreach ($request->items as $item) {
                DetailStockCard::create([
                    'stock_card_id' => $consumption->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sisa stok karyawan berhasil disimpan.',
                'data' => $consumption->load('detailStockCards'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan sisa stok karyawan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing employee consumption. Tidak mengizinkan edit ketika
     * status sudah valid (2) — konsisten dengan policy admin.
     */
    public function update(Request $request, $id)
    {
        $consumption = EmployeeConsumption::where('for', 'employee_consumption')->find($id);

        if (!$consumption) {
            return response()->json([
                'success' => false,
                'message' => 'Sisa stok karyawan tidak ditemukan.',
            ], 404);
        }

        if ((int) $consumption->status === 2) {
            return response()->json([
                'success' => false,
                'message' => 'Sisa stok karyawan dengan status valid tidak dapat diubah.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $consumption->update([
                'store_id' => $request->store_id,
                'date' => $request->date,
            ]);

            // Replace detail rows: delete existing + insert baru.
            $consumption->detailStockCards()->delete();
            foreach ($request->items as $item) {
                DetailStockCard::create([
                    'stock_card_id' => $consumption->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sisa stok karyawan berhasil diperbarui.',
                'data' => $consumption->load([
                    'store',
                    'user',
                    'detailStockCards.product.unit',
                ]),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui sisa stok karyawan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update status konsumsi (2 = valid, 3 = diperbaiki). Khusus admin.
     */
    public function updateStatus(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat mengubah status sisa stok karyawan.',
            ], 403);
        }

        $consumption = EmployeeConsumption::where('for', 'employee_consumption')->find($id);

        if (!$consumption) {
            return response()->json([
                'success' => false,
                'message' => 'Sisa stok karyawan tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:2,3',
        ]);

        $consumption->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Status sisa stok karyawan berhasil diperbarui.',
            'data' => $consumption->load([
                'store',
                'user',
                'detailStockCards.product.unit',
            ]),
        ]);
    }
}
