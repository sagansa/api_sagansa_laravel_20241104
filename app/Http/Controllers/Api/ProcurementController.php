<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RequestPurchase;
use App\Models\DetailRequest;
use App\Models\InvoicePurchase;
use App\Models\DetailInvoice;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProcurementController extends Controller
{
    /**
     * Get list of products for creating request purchase.
     */
    public function products(Request $request)
    {
        $products = Product::with('unit')->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get list of request purchases.
     */
    public function index(Request $request)
    {
        $query = RequestPurchase::with(['store', 'user', 'detailRequests.product.unit']);

        // Staff only see their own requests
        if (!$request->user()->hasRole('admin')) {
            $query->where('user_id', $request->user()->id);
        }

        $requests = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Get detail of a specific request purchase.
     */
    public function show($id, Request $request)
    {
        $requestPurchase = RequestPurchase::with([
            'store', 
            'user', 
            'detailRequests.product.unit', 
            'detailRequests.paymentType'
        ])->find($id);

        if (!$requestPurchase) {
            return response()->json([
                'success' => false,
                'message' => 'Request Purchase tidak ditemukan.'
            ], 404);
        }

        // Staff checking guard
        if (!$request->user()->hasRole('admin') && $requestPurchase->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke data ini.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $requestPurchase
        ]);
    }

    /**
     * Store new request purchase.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_plan' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            $requestPurchase = RequestPurchase::create([
                'store_id' => $request->store_id,
                'date' => now()->toDateString(),
                'user_id' => $request->user()->id,
            ]);

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);
                $paymentTypeId = $product->payment_type_id ?? 2; // Default to Cash (2)

                DetailRequest::create([
                    'request_purchase_id' => $requestPurchase->id,
                    'product_id' => $item['product_id'],
                    'quantity_plan' => $item['quantity_plan'],
                    'store_id' => $request->store_id,
                    'payment_type_id' => $paymentTypeId,
                    // If transfer (1), status is process (1), else approved (4)
                    'status' => ($paymentTypeId == 1) ? '1' : '4',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Request Purchase berhasil dibuat.',
                'data' => $requestPurchase->load('detailRequests')
            ], 201);
        });
    }

    /**
     * Approve a specific detail request item (Admin only).
     */
    public function approveItem($itemId, Request $request)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat menyetujui request item.'
            ], 403);
        }

        $item = DetailRequest::find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item request tidak ditemukan.'
            ], 404);
        }

        if ($item->status != '1') {
            return response()->json([
                'success' => false,
                'message' => 'Item ini sudah tidak dalam status process.'
            ], 400);
        }

        $item->update(['status' => '4']); // Approved

        return response()->json([
            'success' => true,
            'message' => 'Item request disetujui.',
            'data' => $item
        ]);
    }

    /**
     * Reject a specific detail request item (Admin only).
     */
    public function rejectItem($itemId, Request $request)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat menolak request item.'
            ], 403);
        }

        $item = DetailRequest::find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item request tidak ditemukan.'
            ], 404);
        }

        if ($item->status != '1') {
            return response()->json([
                'success' => false,
                'message' => 'Item ini sudah tidak dalam status process.'
            ], 400);
        }

        $item->update(['status' => '3']); // Reject

        return response()->json([
            'success' => true,
            'message' => 'Item request ditolak.',
            'data' => $item
        ]);
    }

    /**
     * Auto-create Invoice from approved items.
     */
    public function createInvoice($id, Request $request)
    {
        $requestPurchase = RequestPurchase::find($id);

        if (!$requestPurchase) {
            return response()->json([
                'success' => false,
                'message' => 'Request Purchase tidak ditemukan.'
            ], 404);
        }

        $approvedItems = $requestPurchase->detailRequests()->where('status', '4')->get();

        if ($approvedItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada item disetujui (Approved) untuk dibuatkan invoice.'
            ], 400);
        }

        // BUSINESS RULE: Check for empty invoice created by this user
        $hasEmptyInvoice = InvoicePurchase::where('created_by_id', $request->user()->id)
            ->whereDoesntHave('detailInvoices')
            ->exists();

        if ($hasEmptyInvoice) {
            return response()->json([
                'success' => false,
                'message' => 'Anda masih memiliki invoice kosong (tanpa detail item). Silakan lengkapi atau hapus invoice kosong tersebut terlebih dahulu.'
            ], 422);
        }

        return DB::transaction(function () use ($requestPurchase, $approvedItems, $request) {
            $firstItem = $approvedItems->first();

            $invoice = InvoicePurchase::create([
                'store_id' => $requestPurchase->store_id,
                'date' => now()->toDateString(),
                'payment_status' => '1',
                'order_status' => '1',
                'created_by_id' => $request->user()->id,
                'payment_type_id' => $firstItem->payment_type_id ?? 2,
                'total_price' => 0,
            ]);

            foreach ($approvedItems as $item) {
                DetailInvoice::create([
                    'invoice_purchase_id' => $invoice->id,
                    'detail_request_id' => $item->id,
                    'quantity_product' => $item->quantity_plan,
                    'subtotal_invoice' => 0,
                    'status' => '3',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil dibuat secara otomatis.',
                'invoice_id' => $invoice->id
            ]);
        });
    }
}
