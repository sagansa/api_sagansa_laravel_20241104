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

        // Staff only sees their own records
        if ($request->user()->hasRole('staff')) {
            $query->where('created_by_id', $request->user()->id);
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
     * Check how many stores have reported today and if current user's store has reported.
     */
    public function todayStatus(Request $request)
    {
        $today = Carbon::now()->toDateString();
        
        $totalStores = \App\Models\Store::where('status', '<>', '8')->count();
        $reportedStores = StorageStock::where('date', $today)
            ->distinct('store_id')
            ->count('store_id');
        
        $userStoreReported = false;
        if ($request->user()->hasRole('storage-staff')) {
            $presence = \App\Models\Presence::where('created_by_id', $request->user()->id)
                ->whereDate('check_in', $today)
                ->first();
            if ($presence) {
                $userStoreReported = StorageStock::where('date', $today)
                    ->where('store_id', $presence->store_id)
                    ->exists();
            }
        }
        
        return response()->json([
            'success' => true,
            'total_stores' => $totalStores,
            'reported_stores' => $reportedStores,
            'user_store_reported' => $userStoreReported,
        ]);
    }

    /**
     * Get stock monitoring aggregated from latest storage stocks of all stores.
     */
    public function stockMonitoring(Request $request)
    {
        $now = Carbon::now('Asia/Jakarta');
        $today = $now->toDateString();
        $hour = $now->hour;

        // Before 22:00 today, only show reports up to yesterday.
        // From 22:00 today onwards, show reports up to today.
        $maxAllowedDate = $today;
        if ($hour < 22) {
            $maxAllowedDate = $now->copy()->subDay()->toDateString();
        }

        // 1. Optimized subquery for latest remaining storage stock per product up to $maxAllowedDate
        $latestStockSubquery = DB::table('detail_stock_cards as dsc')
            ->join('stock_cards as sc', 'dsc.stock_card_id', '=', 'sc.id')
            ->join(DB::raw('(
                SELECT dsc2.product_id, MAX(sc2.date) as max_date 
                FROM detail_stock_cards dsc2 
                JOIN stock_cards sc2 ON dsc2.stock_card_id = sc2.id 
                WHERE sc2.for = \'remaining_storage\' 
                  AND sc2.date <= \'' . $maxAllowedDate . '\' 
                GROUP BY dsc2.product_id
            ) as latest'), function($join) {
                $join->on('dsc.product_id', '=', 'latest.product_id')
                     ->on('sc.date', '=', 'latest.max_date');
            })
            ->where('sc.for', 'remaining_storage')
            ->where('sc.date', '<=', $maxAllowedDate)
            ->select('dsc.product_id', DB::raw('SUM(dsc.quantity) as current_quantity'), DB::raw('MAX(sc.date) as latest_date'))
            ->groupBy('dsc.product_id');

        $latestReports = $latestStockSubquery->get();
        $productQuantities = $latestReports->pluck('current_quantity', 'product_id');
        $lastStockDate = $latestReports->max('latest_date');

        // 3. Load stock monitorings
        $stockMonitorings = DB::table('stock_monitorings')
            ->leftJoin('units', 'stock_monitorings.unit_id', '=', 'units.id')
            ->select('stock_monitorings.*', 'units.unit as unit_nickname')
            ->get();

        // 4. Calculate total stock per monitoring group
        foreach ($stockMonitorings as $sm) {
            $details = DB::table('stock_monitoring_details')
                ->join('products', 'stock_monitoring_details.product_id', '=', 'products.id')
                ->where('stock_monitoring_details.stock_monitoring_id', $sm->id)
                ->select('stock_monitoring_details.*', 'products.name as product_name')
                ->get();

            $calculatedTotal = 0;
            foreach ($details as $detail) {
                $qty = isset($productQuantities[$detail->product_id]) ? $productQuantities[$detail->product_id] : 0;
                $calculatedTotal += $qty * $detail->coefficient;
            }

            $sm->calculated_total_stock = $calculatedTotal;
            $sm->details = $details;
            $sm->last_stock_date = $lastStockDate;
        }

        return response()->json([
            'success' => true,
            'data' => $stockMonitorings
        ]);
    }
}
