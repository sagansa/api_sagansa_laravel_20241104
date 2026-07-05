<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorageStock;
use App\Models\ProductStorageStock;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class StorageStockController extends Controller
{
    /**
     * Get list of products for creating storage stock (Stock Opname).
     */
    public function products(Request $request)
    {
        // Mendapatkan semua produk dengan request = 1, urut abjad
        $products = Product::with('unit')
            ->where('request', '1')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get list of storage stocks.
     */
    public function index(Request $request)
    {
        $query = StorageStock::with(['store', 'createdBy', 'productStorageStocks.product.unit']);

        // Staff see requests for the stores they belong to (determined by active check-in today)
        if ($request->user()->hasRole('storage-staff')) {
            $today = Carbon::now()->toDateString();
            $presence = \App\Models\Presence::where('created_by_id', $request->user()->id)
                ->whereDate('check_in', $today)
                ->first();

            if ($presence) {
                $query->where('store_id', $presence->store_id);
            } else {
                $query->where('created_by_id', $request->user()->id);
            }
        }

        $requests = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Get detail of a specific storage stock.
     */
    public function show($id, Request $request)
    {
        $storageStock = StorageStock::with([
            'store', 
            'createdBy', 
            'productStorageStocks.product.unit'
        ])->find($id);

        if (!$storageStock) {
            return response()->json([
                'success' => false,
                'message' => 'Storage Stock tidak ditemukan.'
            ], 404);
        }

        // Staff checking guard
        if ($request->user()->hasRole('storage-staff') && $storageStock->created_by_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke data ini.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $storageStock
        ]);
    }

    /**
     * Store new storage stock.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $today = Carbon::now()->toDateString();

        // 2. Validasi Duplikasi: Hanya 1 kali sehari untuk store yang sama
        $existingReport = StorageStock::where('store_id', $request->store_id)
            ->where('date', $today)
            ->first();

        if ($existingReport) {
            return response()->json([
                'success' => false,
                'message' => 'Laporan stok untuk gudang ini sudah dilakukan hari ini. Anda tidak dapat melakukan laporan ganda untuk menghindari salah hitung.'
            ], 422);
        }

        return DB::transaction(function () use ($request, $today) {
            $storageStock = StorageStock::create([
                'store_id' => $request->store_id,
                'date' => $today,
                'created_by_id' => $request->user()->id,
                'status' => 1, // Default status: Draft/Pending/Submitted
            ]);

            foreach ($request->items as $item) {
                ProductStorageStock::create([
                    'storage_stock_id' => $storageStock->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Laporan Stok Sisa berhasil disimpan.',
                'data' => $storageStock->load('productStorageStocks')
            ], 201);
        });
    }

    /**
     * Check if current user has reported today
     */
    public function todayStatus(Request $request)
    {
        $today = Carbon::now()->toDateString();
        $query = StorageStock::where('date', $today);
        
        if ($request->user()->hasRole('storage-staff')) {
            $presence = \App\Models\Presence::where('created_by_id', $request->user()->id)
                ->whereDate('check_in', $today)
                ->first();

            if ($presence) {
                $query->where('store_id', $presence->store_id);
            } else {
                $query->where('created_by_id', $request->user()->id);
            }
        }
        
        return response()->json([
            'success' => true,
            'has_reported' => $query->exists()
        ]);
    }
}
