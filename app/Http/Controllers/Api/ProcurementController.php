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
use App\Models\DailySalary;
use App\Models\FuelService;
use App\Services\QrisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $perPage = $request->integer('per_page', 20);
        $paginated = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->paginate($perPage);
        $requests = $paginated->items();

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
            ],
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
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

        // Staff/supervisor only see invoices they created
        if (!$request->user()->hasRole('admin')) {
            $query->where('created_by_id', $request->user()->id);
        }

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
    public function showInvoice($id, Request $request)
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

        // Admin: append harga beli terakhir per item (lintas supplier) untuk
        // evaluasi. Staff tidak butuh info ini.
        $user = $request->user();
        $isAdmin = $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
        if ($isAdmin) {
            $invoice->detailInvoices->each(function ($detail) {
                $detail->append('last_purchase_price');
            });
        }

        return response()->json([
            'success' => true,
            'data' => $invoice
        ]);
    }

    /**
     * Mark an Invoice Purchase as received (order_status: 1 -> 2).
     * Allowed for staff, admin, super_admin. Not reversible from API.
     */
    public function receiveInvoice($id, Request $request)
    {
        $invoice = InvoicePurchase::find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.'
            ], 404);
        }

        $user = $request->user();
        $canReceive = $user->hasRole('staff')
            || $user->hasRole('admin')
            || $user->hasRole('super_admin');

        if (!$canReceive) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menerima invoice ini.'
            ], 403);
        }

        if ($invoice->order_status !== '1') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice sudah diterima atau berstatus lain.'
            ], 400);
        }

        $invoice->order_status = '2';
        $invoice->save();

        return response()->json([
            'success' => true,
            'message' => 'Invoice ditandai sudah diterima.',
            'data' => $invoice->load([
                'store', 'supplier', 'createdBy',
                'detailInvoices.detailRequest.product.unit',
                'detailInvoices.detailRequest.paymentType',
            ]),
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
            'request_ids' => 'nullable|array',
            'request_ids.*' => 'integer|exists:request_purchases,id',
            'payment_type_id' => 'nullable|integer|in:1,2',
        ]);

        $requestPurchase = RequestPurchase::find($id);

        if (!$requestPurchase) {
            return response()->json([
                'success' => false,
                'message' => 'Request Purchase tidak ditemukan.'
            ], 404);
        }

        // Kumpulkan request-id yang diizinkan untuk item lintas-request.
        // Default: hanya request dari URL ($id). Bila frontend mengirim
        // 'request_ids', item boleh berasal dari request mana pun di dalamnya
        // (selama berasal dari store yang sama & status disetujui).
        $allowedRequestIds = collect($request->input('request_ids', []));
        if ($allowedRequestIds->isEmpty()) {
            $allowedRequestIds = collect([$id]);
        }

        $detailRequestIds = collect($request->items)->pluck('detail_request_id');

        // Verifikasi item: disetujui (status 4) DAN milik salah satu request
        // yang diizinkan DAN store-nya sama dengan request utama.
        $validItems = DetailRequest::with('requestPurchase')
            ->whereIn('id', $detailRequestIds)
            ->where('status', '4')
            ->whereIn('request_purchase_id', $allowedRequestIds)
            ->get()
            ->filter(function ($dr) use ($requestPurchase) {
                return $dr->requestPurchase
                    && $dr->requestPurchase->store_id == $requestPurchase->store_id;
            });

        if ($validItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Item yang dipilih tidak valid, belum disetujui, atau bukan dari toko yang sama.'
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
                'payment_type_id' => $request->input('payment_type_id', 2),
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

    public function updateInvoice($id, Request $request)
    {
        $user = $request->user();
        $invoice = InvoicePurchase::with('detailInvoices')->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.'
            ], 404);
        }

        // Loose comparison (!=) bukan strict (!==): nilai payment_status bisa
        // string '1' atau int 1 tergantung write path. Lihat InvoicePurchase::$casts.
        if ($invoice->payment_status != '1') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya invoice draft yang dapat diedit.'
            ], 400);
        }

        $isAdmin = $user->hasRole('admin') || $user->hasRole('super_admin');
        if (!$isAdmin && $invoice->created_by_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengedit invoice ini.'
            ], 403);
        }

        $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'payment_type_id' => 'nullable|integer',
            'taxes' => 'nullable|numeric|min:0',
            'discounts' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.detail_invoice_id' => 'required_with:items|exists:detail_invoices,id',
            'items.*.price' => 'required_with:items|numeric|min:0',
            'items.*.quantity' => 'required_with:items|numeric|min:1',
        ]);

        return DB::transaction(function () use ($invoice, $request) {
            if ($request->filled('supplier_id')) {
                $invoice->supplier_id = $request->supplier_id;
            }
            if ($request->filled('payment_type_id')) {
                $invoice->payment_type_id = $request->payment_type_id;
            }
            if ($request->has('taxes')) {
                $invoice->taxes = (int) ($request->taxes ?? 0);
            }
            if ($request->has('discounts')) {
                $invoice->discounts = (int) ($request->discounts ?? 0);
            }
            if ($request->has('notes')) {
                $invoice->notes = $request->notes;
            }

            $totalPrice = 0;
            if ($request->has('items')) {
                foreach ($request->items as $item) {
                    $detail = $invoice->detailInvoices
                        ->firstWhere('id', $item['detail_invoice_id']);
                    if (!$detail) continue;
                    $subtotal = (int) $item['price'] * (int) $item['quantity'];
                    $detail->update([
                        'quantity_product' => $item['quantity'],
                        'subtotal_invoice' => $subtotal,
                    ]);
                    $totalPrice += $subtotal;
                }
            } else {
                $totalPrice = $invoice->detailInvoices->sum('subtotal_invoice');
            }

            $invoice->total_price = $totalPrice + ($invoice->taxes ?? 0) - ($invoice->discounts ?? 0);
            $invoice->save();

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil diperbarui.',
                'data' => $invoice->load([
                    'store', 'supplier', 'createdBy',
                    'detailInvoices.detailRequest.product.unit',
                    'detailInvoices.detailRequest.paymentType',
                ]),
            ]);
        });
    }

    /**
     * Get list of payment receipts (for invoice purchases).
     */
    public function paymentReceipts(Request $request)
    {
        // Filter by payment_for (1=FuelService, 2=DailySalary, 3=InvoicePurchase).
        // Default to InvoicePurchase (3) hanya bila param TIDAK dikirim, agar
        // tetap backward-compatible dengan caller lama yang mengharapkan list
        // invoice receipts. Caller baru (mis. tab Pembayaran fuel service) kirim
        // payment_for=1 untuk mendapatkan receipt fuel/service.
        $query = PaymentReceipt::with([
            'invoicePurchases.store', 'invoicePurchases.supplier', 'supplier',
            'fuelServices.vehicle', 'fuelServices.createdBy',
        ]);

        if ($request->filled('payment_for')) {
            $query->where('payment_for', $request->input('payment_for'));
        } else {
            $query->where('payment_for', '3'); // backward-compat default
        }

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
            'fuelServices.vehicle',
            'fuelServices.createdBy',
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
        if ($request->user()->hasRole('staff')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk generate QRIS.'
            ], 403);
        }

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
        $user = $request->user();
        if ($user->hasRole('staff') || $user->hasRole('storage-staff')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat bukti pembayaran (payment receipt).'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_for' => 'nullable|in:2,3',
            'invoice_ids' => 'nullable|array|min:1',
            'invoice_ids.*' => 'exists:invoice_purchases,id',
            'daily_salary_ids' => 'nullable|array|min:1',
            'daily_salary_ids.*' => 'exists:daily_salaries,id',
            'transfer_amount' => 'required|numeric|min:1',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $paymentFor = $request->input('payment_for', '3');

        if ($paymentFor === '2') {
            return $this->storeDailySalaryPaymentReceipt($request);
        }

        $invoiceIds = $request->invoice_ids;

        // Verify all invoices are unpaid and Transfer payment type
        $invoices = InvoicePurchase::whereIn('id', $invoiceIds)->get();
        foreach ($invoices as $inv) {
            // Loose comparison (!=): payment_status bisa string '1' atau int 1.
            // Lihat InvoicePurchase::$casts yang menormalisasi ke string.
            if ($inv->payment_status != '1') {
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
            if ($request->filled('image')) {
                $imagePath = $request->input('image');
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

    /**
     * Create a payment receipt untuk daily salary (transfer payment).
     *
     * payment_for = '2' (DailySalary). Daily salary harus status '3' (siap
     * dibayar) dan metode Transfer, lalu di-update menjadi '2' (dibayar)
     * setelah receipt dibuat (mirip invoice yang berubah payment_status).
     */
    private function storeDailySalaryPaymentReceipt(Request $request)
    {
        $dailySalaryIds = $request->daily_salary_ids;

        $salaries = DailySalary::whereIn('id', $dailySalaryIds)->get();

        foreach ($salaries as $salary) {
            if ($salary->status != '3') {
                return response()->json([
                    'success' => false,
                    'message' => "Daily salary #{$salary->id} belum siap dibayar (status: {$salary->status})."
                ], 400);
            }
            if ($salary->payment_type_id != 1) {
                return response()->json([
                    'success' => false,
                    'message' => "Daily salary #{$salary->id} bukan metode Transfer."
                ], 400);
            }
        }

        return DB::transaction(function () use ($request, $dailySalaryIds, $salaries) {
            $totalAmount = $request->total_amount ?? $salaries->sum('amount');

            $imagePath = null;
            if ($request->filled('image')) {
                $imagePath = $request->input('image');
            }

            $receipt = PaymentReceipt::create([
                'payment_for' => '2',
                'total_amount' => (int) $totalAmount,
                'transfer_amount' => (int) $request->transfer_amount,
                'user_id' => $request->user()->id,
                'notes' => $request->notes,
                'image' => $imagePath,
            ]);

            // Attach daily salaries to receipt
            $receipt->dailySalaries()->attach($dailySalaryIds);

            // Update daily salary status menjadi dibayar (2)
            foreach ($salaries as $salary) {
                $salary->update(['status' => '2']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment receipt gaji berhasil dibuat.',
                'data' => $receipt->load('dailySalaries')
            ], 201);
        });
    }

    /**
     * Create a new payment receipt for fuel services (transfer payment).
     *
     * Mirror dengan storePaymentReceipt (invoice) tapi untuk fuel_services.
     * payment_for = '1' (FuelService).
     */
    public function storeFuelServicePaymentReceipt(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('staff') || $user->hasRole('storage-staff')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat bukti pembayaran (payment receipt).'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'fuel_service_ids' => 'required|array|min:1',
            'fuel_service_ids.*' => 'exists:fuel_services,id',
            'transfer_amount' => 'required|numeric|min:1',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $fuelServiceIds = $request->fuel_service_ids;

        // Verify all fuel services are Transfer + pending (status=1).
        $fuelServices = FuelService::whereIn('id', $fuelServiceIds)->get();
        foreach ($fuelServices as $fs) {
            if ($fs->status != '1') {
                return response()->json([
                    'success' => false,
                    'message' => "Bensin/Servis #{$fs->id} sudah dibayar atau tidak valid."
                ], 400);
            }
            if ($fs->payment_type_id != 1) {
                return response()->json([
                    'success' => false,
                    'message' => "Bensin/Servis #{$fs->id} bukan metode Transfer."
                ], 400);
            }
        }

        return DB::transaction(function () use ($request, $fuelServiceIds, $fuelServices) {
            $totalAmount = $request->total_amount ?? $fuelServices->sum('amount');

            $imagePath = null;
            if ($request->filled('image')) {
                $imagePath = $request->input('image');
            }

            $receipt = PaymentReceipt::create([
                'payment_for' => '1', // FuelService
                'total_amount' => (int) $totalAmount,
                'transfer_amount' => (int) $request->transfer_amount,
                'supplier_id' => null, // Fuel service tidak punya supplier
                'user_id' => $request->user()->id,
                'notes' => $request->notes,
                'image' => $imagePath,
            ]);

            // Attach fuel services to receipt
            $receipt->fuelServices()->attach($fuelServiceIds);

            // Update fuel service status to paid (status='2').
            // DIAGNOSTIC: log before/after status per id + affected-rows count
            // untuk menyelidiki laporan "sebagian item tetap Pending setelah
            // dibayar". Jika affected_rows < jumlah id, berarti update diam-diam
            // melewatkan beberapa baris (mass-assignment / scope / cache).
            $before = FuelService::whereIn('id', $fuelServiceIds)
                ->pluck('status', 'id')
                ->all();

            foreach ($fuelServices as $fs) {
                $fs->update(['status' => '2']);
            }

            $after = FuelService::whereIn('id', $fuelServiceIds)
                ->pluck('status', 'id')
                ->all();

            Log::info('FuelService payment receipt created', [
                'receipt_id' => $receipt->id,
                'requested_ids' => $fuelServiceIds,
                'requested_count' => count($fuelServiceIds),
                'queried_count' => $fuelServices->count(),
                'status_before' => $before,
                'status_after' => $after,
                'still_pending_after' => collect($after)
                    ->filter(fn ($s) => $s != '2')
                    ->keys()
                    ->values()
                    ->all(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment receipt berhasil dibuat.',
                'data' => $receipt->load('fuelServices')
            ], 201);
        });
    }

    public function detailRequests(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'payment_type_id' => 'nullable|integer',
        ]);

        $query = DetailRequest::with([
            'product.unit', 'requestPurchase.store', 'paymentType'
        ])
        ->where('store_id', $request->store_id)
        ->where('status', '4'); // Approved items only

        if ($request->filled('payment_type_id')) {
            $query->where('payment_type_id', $request->payment_type_id);
        }

        $items = $query->orderBy('id', 'desc')->get();

        // Admin: append harga beli terakhir per item untuk evaluasi harga.
        $user = $request->user();
        $isAdmin = $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
        if ($isAdmin) {
            $items->each(function ($item) {
                $item->append('last_purchase_price');
            });
        }

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function storeInvoice(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'store_id' => 'required|exists:stores,id',
            'payment_type_id' => 'required|integer',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.detail_request_id' => 'required|exists:detail_requests,id',
            'items.*.quantity_product' => 'required|numeric|min:1',
            'items.*.subtotal_invoice' => 'required|numeric|min:0',
            'taxes' => 'nullable|numeric|min:0',
            'discounts' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $user) {
            $totalPrice = 0;
            foreach ($request->items as $item) {
                $totalPrice += (int) $item['subtotal_invoice'];
            }
            $taxes = (int) ($request->taxes ?? 0);
            $discounts = (int) ($request->discounts ?? 0);
            $totalPrice = $totalPrice + $taxes - $discounts;

            $invoice = InvoicePurchase::create([
                'store_id' => $request->store_id,
                'supplier_id' => $request->supplier_id,
                'payment_type_id' => $request->payment_type_id,
                'date' => $request->date,
                'total_price' => $totalPrice,
                'taxes' => $taxes,
                'discounts' => $discounts,
                'notes' => $request->notes,
                'created_by_id' => $user->id,
                'payment_status' => '1',
                'order_status' => '1',
            ]);

            foreach ($request->items as $item) {
                DetailInvoice::create([
                    'invoice_purchase_id' => $invoice->id,
                    'detail_request_id' => $item['detail_request_id'],
                    'quantity_product' => $item['quantity_product'],
                    'subtotal_invoice' => $item['subtotal_invoice'],
                    'status' => '3',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil dibuat.',
                'data' => $invoice->load([
                    'store', 'supplier', 'createdBy',
                    'detailInvoices.detailRequest.product.unit',
                ]),
            ], 201);
        });
    }
}
