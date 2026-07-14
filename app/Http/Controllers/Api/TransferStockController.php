<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransferStock;
use App\Models\ProductTransferStock;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransferStockController extends Controller
{
    /**
     * Get list of products available for transfer stock.
     */
    public function products(Request $request)
    {
        $products = Product::with('unit')
            ->where('request', '1')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Get list of transfer stocks for the authenticated user's store.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = TransferStock::with([
            'storeFrom',
            'storeTo',
            'sentBy',
            'receivedBy',
            'productTransferStocks.product.unit',
        ]);

        // Staff: only see transfers they sent or received
        if ($user->hasRole('staff')) {
            $query->where(function ($q) use ($user) {
                $q->where('sent_by_id', $user->id)
                  ->orWhere('received_by_id', $user->id);
            });
        } elseif ($user->store_id && !$user->hasRole('admin') && !$user->hasRole('super_admin')) {
            // Other non-admin roles: filter by store
            $query->where(function ($q) use ($user) {
                $q->where('from_store_id', $user->store_id)
                  ->orWhere('to_store_id', $user->store_id);
            });
        }

        $transfers = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->get();

        $data = $transfers->map(function ($t) {
            return $this->formatTransferStock($t);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get detail of a specific transfer stock.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $transfer = TransferStock::with([
            'storeFrom',
            'storeTo',
            'sentBy',
            'receivedBy',
            'approvedBy',
            'productTransferStocks.product.unit',
        ])->find($id);

        if (!$transfer) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatTransferStock($transfer),
        ]);
    }

    /**
     * Create a new transfer stock.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'from_store_id' => 'required|integer|exists:stores,id',
            'to_store_id' => 'required|integer|exists:stores,id|different:from_store_id',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $transfer = TransferStock::create([
                'from_store_id' => $request->from_store_id,
                'to_store_id' => $request->to_store_id,
                'date' => $request->date,
                'sent_by_id' => $user->id,
                'status' => TransferStock::STATUS_BELUM_DIPERIKSA,
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $item) {
                ProductTransferStock::create([
                    'transfer_stock_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transfer stok berhasil dibuat.',
                'data' => ['id' => $transfer->id],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat transfer stok: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format a TransferStock model into the expected API response shape.
     */
    private function formatTransferStock(TransferStock $t): array
    {
        return [
            'id' => $t->id,
            'date' => $t->date,
            'status' => $t->status,
            'image_url' => $t->image_url,
            'notes' => $t->notes,
            'store_from' => $t->storeFrom ? ['nickname' => $t->storeFrom->nickname] : null,
            'store_to' => $t->storeTo ? ['nickname' => $t->storeTo->nickname] : null,
            'sent_by' => $t->sentBy ? ['name' => $t->sentBy->name] : null,
            'received_by' => $t->receivedBy ? ['name' => $t->receivedBy->name] : null,
            'product_transfer_stocks' => $t->productTransferStocks->map(function ($pts) {
                return [
                    'id' => $pts->id,
                    'product_id' => $pts->product_id,
                    'quantity' => $pts->quantity,
                    'product' => $pts->product ? [
                        'name' => $pts->product->name,
                        'unit' => $pts->product->unit ? ['unit' => $pts->product->unit->unit] : null,
                    ] : null,
                ];
            })->toArray(),
        ];
    }
}
