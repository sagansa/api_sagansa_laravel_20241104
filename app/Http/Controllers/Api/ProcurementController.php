<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RequestPurchase;
use App\Models\DetailRequest;
use App\Models\InvoicePurchase;
use App\Models\DetailInvoice;
use App\Models\Product;
use App\Models\Asset;
use App\Models\PaymentReceipt;
use App\Services\QrisService;
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
        $products = Product::with('unit')->where('payment_type_id', '!=', '3')->get();

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

        // Hitung statistik invoice untuk user/admin
        $userId = $request->user()->id;
        $isAdmin = $request->user()->hasRole('admin');
        
        $invoiceQuery = InvoicePurchase::query();
        if (!$isAdmin) {
            $invoiceQuery->where('created_by_id', $userId);
        }

        $invoicesCount = [
            'draft' => (clone $invoiceQuery)->where('order_status', 1)->count(),
            'done' => (clone $invoiceQuery)->where('order_status', 2)->count(),
            'unpaid' => (clone $invoiceQuery)->where('payment_status', '!=', 3)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $requests,
            'meta' => [
                'invoice_counts' => $invoicesCount
            ]
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
            'items.*.payment_type_id' => 'nullable|in:1,2',
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
                'status' => 1, // Process
            ]);

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);
                $productDefault = $product->payment_type_id ?? 1;
                $plannedPayment = $item['payment_type_id'] ?? $productDefault;

                DetailRequest::create([
                    'request_purchase_id' => $requestPurchase->id,
                    'product_id' => $item['product_id'],
                    'quantity_plan' => $item['quantity_plan'],
                    'store_id' => $request->store_id,
                    'payment_type_id' => $plannedPayment,
                    // Product default Transfer (1) tetapi berencana membayar Tunai (2) -> butuh approval (1)
                    // Selain itu -> langsung approved (4)
                    'status' => ($productDefault == 1 && $plannedPayment == 2) ? '1' : '4',
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
     * Mark a specific detail request item as Cancel / Not Used (Admin only).
     */
    public function cancelItem($itemId, Request $request)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat membatalkan request item.'
            ], 403);
        }

        $item = DetailRequest::find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item request tidak ditemukan.'
            ], 404);
        }

        if (in_array($item->status, ['2', '3', '5', '6'])) {
            return response()->json([
                'success' => false,
                'message' => 'Item ini tidak dapat dibatalkan (sudah selesai/ditolak/tidak aktif).'
            ], 400);
        }

        $item->update(['status' => '6']); // Not Used

        return response()->json([
            'success' => true,
            'message' => 'Item request berhasil ditandai sebagai tidak digunakan.',
            'data' => $item
        ]);
    }

    /**
     * Get list of invoice purchases.
     */
    public function invoices(Request $request)
    {
        $query = InvoicePurchase::with([
            'store', 'supplier', 'detailInvoices', 'createdBy'
        ]);

        if ($request->has('order_status')) {
            $query->where('order_status', $request->order_status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $perPage = $request->query('per_page', 10);
        $invoices = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $invoices->items(),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * Get detail of a specific invoice purchase.
     */
    public function showInvoice($id)
    {
        $invoice = InvoicePurchase::with([
            'store', 'supplier', 'createdBy',
            'detailInvoices.detailRequest.product.unit',
            'detailInvoices.detailRequest.paymentType',
        ])->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $invoice
        ]);
    }

    /**
     * Auto-create Invoice from approved items.
     */
    public function createInvoice($id, Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'items' => 'required|array|min:1',
            'items.*.detail_request_id' => 'required|exists:detail_requests,id',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|numeric|min:1',
        ]);

        $requestPurchase = RequestPurchase::find($id);

        if (!$requestPurchase) {
            return response()->json([
                'success' => false,
                'message' => 'Request Purchase tidak ditemukan.'
            ], 404);
        }

        $detailRequestIds = collect($request->items)->pluck('detail_request_id');

        // Verify all items belong to this request and are approved
        $validItems = DetailRequest::whereIn('id', $detailRequestIds)
            ->where('request_purchase_id', $id)
            ->where('status', '4')
            ->get();

        if ($validItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Item yang dipilih tidak valid atau belum disetujui.'
            ], 400);
        }

        // Check for duplicate items from different requests (already scoped by request id)

        return DB::transaction(function () use ($requestPurchase, $validItems, $request) {
            $totalPrice = 0;
            $itemData = [];
            foreach ($request->items as $item) {
                $detailRequest = $validItems->firstWhere('id', $item['detail_request_id']);
                if (!$detailRequest) continue;

                $subtotal = (int) $item['price'] * (int) $item['quantity'];
                $totalPrice += $subtotal;
                $itemData[] = [
                    'detail_request' => $detailRequest,
                    'price' => (int) $item['price'],
                    'quantity' => (int) $item['quantity'],
                    'subtotal' => $subtotal,
                ];
            }

            $invoice = InvoicePurchase::create([
                'store_id' => $requestPurchase->store_id,
                'date' => now()->toDateString(),
                'payment_status' => '1',
                'order_status' => '1',
                'created_by_id' => $request->user()->id,
                'payment_type_id' => 2,
                'total_price' => $totalPrice,
                'supplier_id' => $request->supplier_id,
                'taxes' => 0,
                'discounts' => 0,
            ]);

            $assetsCreated = 0;

            foreach ($itemData as $data) {
                $detailRequest = $data['detail_request'];

                $detailInvoice = DetailInvoice::create([
                    'invoice_purchase_id' => $invoice->id,
                    'detail_request_id' => $detailRequest->id,
                    'quantity_product' => $data['quantity'],
                    'subtotal_invoice' => $data['subtotal'],
                    'status' => '3',
                ]);

                // Auto-create assets if product is flagged as asset
                $product = Product::with('assetCategory')->find($detailRequest->product_id);
                if ($product && $product->is_asset && $product->asset_category_id) {
                    $qty = max(1, $data['quantity']);
                    for ($i = 0; $i < $qty; $i++) {
                        Asset::create([
                            'code' => Asset::generateCode(),
                            'name' => $product->name,
                            'product_id' => $product->id,
                            'asset_category_id' => $product->asset_category_id,
                            'store_id' => $requestPurchase->store_id,
                            'condition' => Asset::CONDITION_BAIK,
                            'status' => Asset::STATUS_AKTIF,
                            'purchase_date' => now()->toDateString(),
                            'next_check_at' => $product->assetCategory
                                ? $product->assetCategory->computeNextCheckAt()
                                : now()->addDays(30),
                            'source_detail_invoice_id' => $detailInvoice->id,
                            'created_by_id' => $request->user()->id,
                        ]);
                        $assetsCreated++;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil dibuat.'
                    . ($assetsCreated > 0 ? " {$assetsCreated} aset baru otomatis tercatat." : ''),
                'invoice_id' => $invoice->id,
                'assets_created' => $assetsCreated,
            ]);
        });
    }

    /**
     * Get list of payment receipts (for invoice purchases).
     */
    public function paymentReceipts(Request $request)
    {
        $query = PaymentReceipt::with([
            'invoicePurchases.store', 'invoicePurchases.supplier', 'supplier'
        ])->where('payment_for', '3'); // Only Invoice Purchase receipts

        if ($request->has('invoice_id')) {
            $query->whereHas('invoicePurchases', fn ($q) => $q->where('invoice_purchase_id', $request->invoice_id));
        }

        $perPage = $request->query('per_page', 10);
        $receipts = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $receipts->items(),
            'meta' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ],
        ]);
    }

    /**
     * Get detail of a specific payment receipt.
     */
    public function showPaymentReceipt($id, Request $request)
    {
        $receipt = PaymentReceipt::with([
            'invoicePurchases.store',
            'invoicePurchases.supplier',
            'invoicePurchases.detailInvoices.detailRequest.product.unit',
            'supplier',
        ])->find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Payment receipt tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $receipt
        ]);
    }

    /**
     * Get QRIS payload for a payment receipt.
     */
    public function paymentReceiptQris($id, Request $request)
    {
        $receipt = PaymentReceipt::with('supplier')->find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Payment receipt tidak ditemukan.'
            ], 404);
        }

        if (!$receipt->supplier || !$receipt->supplier->qris) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier tidak memiliki data QRIS.'
            ], 400);
        }

        try {
            $qrisService = app(QrisService::class);
            $dynamicPayload = $qrisService->generateDynamicPayload(
                $receipt->supplier->qris,
                $receipt->transfer_amount ?? $receipt->total_amount ?? 0
            );

            $parsed = $qrisService->parsePayload($receipt->supplier->qris);

            return response()->json([
                'success' => true,
                'data' => [
                    'payload' => $dynamicPayload,
                    'merchant_name' => $parsed['merchant_name'] ?? null,
                    'merchant_nmid' => $qrisService->getMerchantNmid($parsed),
                    'amount' => $receipt->transfer_amount ?? $receipt->total_amount,
                    'raw_supplier_qris' => $receipt->supplier->qris,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate QRIS: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new payment receipt for invoice purchases.
     */
    public function storePaymentReceipt(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'exists:invoice_purchases,id',
            'transfer_amount' => 'required|numeric|min:1',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,heic,heif|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $invoiceIds = $request->invoice_ids;

        // Verify all invoices are unpaid and Transfer payment type
        $invoices = InvoicePurchase::whereIn('id', $invoiceIds)->get();
        foreach ($invoices as $inv) {
            if ($inv->payment_status !== '1') {
                return response()->json([
                    'success' => false,
                    'message' => "Invoice #{$inv->id} sudah dibayar atau tidak valid."
                ], 400);
            }
            if ($inv->payment_type_id != 1) {
                return response()->json([
                    'success' => false,
                    'message' => "Invoice #{$inv->id} bukan metode Transfer."
                ], 400);
            }
        }

        return DB::transaction(function () use ($request, $invoiceIds, $invoices) {
            $totalAmount = $request->total_amount ?? $invoices->sum('total_price');
            $firstInvoice = $invoices->first();

            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = app(\App\Contracts\ImageStorageContract::class)->upload($request->file('image'), 'images/PaymentReceipt');
            }

            $receipt = PaymentReceipt::create([
                'payment_for' => '3',
                'total_amount' => (int) $totalAmount,
                'transfer_amount' => (int) $request->transfer_amount,
                'supplier_id' => $firstInvoice->supplier_id,
                'user_id' => $request->user()->id,
                'notes' => $request->notes,
                'image' => $imagePath,
            ]);

            // Attach invoices to receipt
            $receipt->invoicePurchases()->attach($invoiceIds);

            // Update invoice payment status to paid
            foreach ($invoices as $inv) {
                $inv->update(['payment_status' => '2']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment receipt berhasil dibuat.',
                'data' => $receipt->load('invoicePurchases')
            ], 201);
        });
    }
}
