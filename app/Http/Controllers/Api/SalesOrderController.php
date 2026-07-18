<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrderOnline;
use App\Models\DetailSalesOrder;
use App\Models\OnlineShopProvider;
use App\Models\DeliveryService;
use App\Models\Product;
use App\Contracts\ImageStorageContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SalesOrderController extends Controller
{
    public function search(Request $request)
    {
        $receiptNo = $request->query('receipt_no');
        $for = $request->query('for', '3');
        $paymentProofPrintColumns = $this->paymentProofPrintColumns();

        if ($receiptNo) {
            $order = DB::table('sales_orders')
                ->leftJoin('stores', 'sales_orders.store_id', '=', 'stores.id')
                ->leftJoin('online_shop_providers', 'sales_orders.online_shop_provider_id', '=', 'online_shop_providers.id')
                ->leftJoin('delivery_services', 'sales_orders.delivery_service_id', '=', 'delivery_services.id')
                ->leftJoin('transfer_to_accounts', 'sales_orders.transfer_to_account_id', '=', 'transfer_to_accounts.id')
                ->leftJoin('banks', 'transfer_to_accounts.bank_id', '=', 'banks.id')
                ->leftJoin('delivery_addresses', 'sales_orders.delivery_address_id', '=', 'delivery_addresses.id')
                ->leftJoin('subdistricts', 'delivery_addresses.subdistrict_id', '=', 'subdistricts.id')
                ->leftJoin('districts', 'delivery_addresses.district_id', '=', 'districts.id')
                ->leftJoin('cities', 'delivery_addresses.city_id', '=', 'cities.id')
                ->leftJoin('provinces', 'delivery_addresses.province_id', '=', 'provinces.id')
                ->leftJoin('users', 'sales_orders.ordered_by_id', '=', 'users.id')
                ->where('sales_orders.receipt_no', $receiptNo)
                ->where('sales_orders.for', $for)
                ->whereNull('sales_orders.deleted_at')
                ->select([
                    'sales_orders.id',
                    'sales_orders.receipt_no',
                    'sales_orders.delivery_status',
                    'sales_orders.received_by',
                    'sales_orders.image_delivery',
                    'sales_orders.image_payment',
                    'sales_orders.total_price',
                    ...$paymentProofPrintColumns,
                    'stores.nickname as store_name',
                    'online_shop_providers.name as provider_name',
                    'delivery_services.name as delivery_service_name',
                    'sales_orders.delivery_date',
                    'sales_orders.payment_method',
                    'sales_orders.payment_status',
                    'banks.name as bank_name',
                    'transfer_to_accounts.number as bank_account_number',
                    'transfer_to_accounts.name as bank_account_name',
                    'delivery_addresses.name as address_name',
                    'delivery_addresses.recipient_name as address_recipient_name',
                    'delivery_addresses.recipient_telp_no as address_recipient_telp_no',
                    'delivery_addresses.address as address_detail',
                    'subdistricts.name as address_subdistrict',
                    'districts.name as address_district',
                    'cities.name as address_city',
                    'provinces.name as address_province',
                    'users.name as ordered_by_name'
                ])
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order tidak ditemukan.'
                ], 404);
            }

            // Get details (products)
            $items = DB::table('detail_sales_orders')
                ->join('products', 'detail_sales_orders.product_id', '=', 'products.id')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->where('detail_sales_orders.sales_order_id', $order->id)
                ->select([
                    'products.name as product_name',
                    'detail_sales_orders.quantity',
                    'detail_sales_orders.unit_price',
                    'detail_sales_orders.subtotal_price',
                    'units.unit as product_unit'
                ])
                ->get();

            $order->items = $items;
            $order->image_delivery_url = $this->getStorageUrl($order->image_delivery);
            $order->image_payment_url = $this->getStorageUrl($order->image_payment);
            $this->appendPaymentProofPrintStatus($order);

            return response()->json([
                'success' => true,
                'data' => $order
            ]);
        }

        // List pagination when receipt_no is not provided
        $perPage = $request->query('per_page', 10);

        $orders = DB::table('sales_orders')
            ->leftJoin('stores', 'sales_orders.store_id', '=', 'stores.id')
            ->leftJoin('online_shop_providers', 'sales_orders.online_shop_provider_id', '=', 'online_shop_providers.id')
            ->leftJoin('delivery_services', 'sales_orders.delivery_service_id', '=', 'delivery_services.id')
            ->leftJoin('transfer_to_accounts', 'sales_orders.transfer_to_account_id', '=', 'transfer_to_accounts.id')
            ->leftJoin('banks', 'transfer_to_accounts.bank_id', '=', 'banks.id')
            ->leftJoin('delivery_addresses', 'sales_orders.delivery_address_id', '=', 'delivery_addresses.id')
            ->leftJoin('subdistricts', 'delivery_addresses.subdistrict_id', '=', 'subdistricts.id')
            ->leftJoin('districts', 'delivery_addresses.district_id', '=', 'districts.id')
            ->leftJoin('cities', 'delivery_addresses.city_id', '=', 'cities.id')
            ->leftJoin('provinces', 'delivery_addresses.province_id', '=', 'provinces.id')
            ->leftJoin('users', 'sales_orders.ordered_by_id', '=', 'users.id')
            ->where('sales_orders.for', $for)
            ->whereNull('sales_orders.deleted_at')
            ->when($request->filled('delivery_status'), function ($query) use ($request) {
                $query->where('sales_orders.delivery_status', $request->query('delivery_status'));
            })
            ->when($request->boolean('has_payment_proof'), function ($query) {
                $query->whereNotNull('sales_orders.image_payment')
                    ->where('sales_orders.image_payment', '!=', '');
            })
            ->when(
                $request->query('payment_proof_printed') === '0' && Schema::hasColumn('sales_orders', 'payment_proof_printed_at'),
                function ($query) {
                    $query->whereNull('sales_orders.payment_proof_printed_at');
                }
            )
            ->when(
                $request->query('payment_proof_printed') === '1' && Schema::hasColumn('sales_orders', 'payment_proof_printed_at'),
                function ($query) {
                    $query->whereNotNull('sales_orders.payment_proof_printed_at');
                }
            )
            ->select([
                'sales_orders.id',
                'sales_orders.receipt_no',
                'sales_orders.delivery_status',
                'sales_orders.received_by',
                'sales_orders.image_delivery',
                'sales_orders.image_payment',
                'sales_orders.total_price',
                ...$paymentProofPrintColumns,
                'stores.nickname as store_name',
                'online_shop_providers.name as provider_name',
                'delivery_services.name as delivery_service_name',
                'sales_orders.delivery_date',
                'sales_orders.payment_method',
                'sales_orders.payment_status',
                'banks.name as bank_name',
                'transfer_to_accounts.number as bank_account_number',
                'transfer_to_accounts.name as bank_account_name',
                'delivery_addresses.name as address_name',
                'delivery_addresses.recipient_name as address_recipient_name',
                'delivery_addresses.recipient_telp_no as address_recipient_telp_no',
                'delivery_addresses.address as address_detail',
                'subdistricts.name as address_subdistrict',
                'districts.name as address_district',
                'cities.name as address_city',
                'provinces.name as address_province',
                'users.name as ordered_by_name'
            ])
            ->orderBy('sales_orders.delivery_date', 'desc')
            ->orderBy('sales_orders.id', 'desc')
            ->paginate($perPage);

        // Map items for each order in the paginated response
        $itemsData = $orders->getCollection()->map(function ($order) {
            $items = DB::table('detail_sales_orders')
                ->join('products', 'detail_sales_orders.product_id', '=', 'products.id')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->where('detail_sales_orders.sales_order_id', $order->id)
                ->select([
                    'products.name as product_name',
                    'detail_sales_orders.quantity',
                    'detail_sales_orders.unit_price',
                    'detail_sales_orders.subtotal_price',
                    'units.unit as product_unit'
                ])
                ->get();

            $order->items = $items;
            $order->image_delivery_url = $this->getStorageUrl($order->image_delivery);
            $order->image_payment_url = $this->getStorageUrl($order->image_payment);
            $this->appendPaymentProofPrintStatus($order);
            return $order;
        });

        $orders->setCollection($itemsData);

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]
        ]);
    }

    public function markPaymentProofsPrinted(Request $request)
    {
        if (!Schema::hasColumn('sales_orders', 'payment_proof_printed_at')) {
            return response()->json([
                'success' => false,
                'message' => 'Kolom status print bukti pembayaran belum tersedia. Jalankan migration terlebih dahulu.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 400);
        }

        $orderIds = collect($request->input('order_ids'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $updated = DB::table('sales_orders')
            ->whereIn('id', $orderIds)
            ->where('for', 3)
            ->whereNull('deleted_at')
            ->whereNotNull('image_payment')
            ->where('image_payment', '!=', '')
            ->update([
                'payment_proof_printed_at' => now(),
                'payment_proof_print_count' => DB::raw('COALESCE(payment_proof_print_count, 0) + 1'),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Status print bukti pembayaran berhasil diperbarui.',
            'data' => [
                'updated_count' => $updated,
                'printed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function updateDelivery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receipt_no' => 'required|string',
            'image_delivery' => 'required_if:delivery_status,3|string', // required only if status is 3
            'received_by' => 'nullable|string|max:255',
            'delivery_status' => 'nullable|in:3,6',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 400);
        }

        $receiptNo = $request->input('receipt_no');

        $order = DB::table('sales_orders')
            ->where('receipt_no', $receiptNo)
            ->where('for', 3)
            ->whereNull('deleted_at')
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order tidak ditemukan.'
            ], 404);
        }

        if (in_array((int)$order->delivery_status, [2, 6])) {
            $statusName = $order->delivery_status == 2 ? 'valid' : 'dikembalikan';
            return response()->json([
                'success' => false,
                'message' => "Order sudah berstatus {$statusName} dan tidak dapat diubah."
            ], 400);
        }

        // Handle image upload
        $imagePath = null;
        if ($request->filled('image_delivery')) {
            if ($order->image_delivery && $order->image_delivery !== $request->input('image_delivery')) {
                app(\App\Contracts\ImageStorageContract::class)->delete($order->image_delivery);
            }
            $imagePath = $request->input('image_delivery');
        }

        $deliveryStatus = (int) $request->input('delivery_status', 3);
        $notes = $request->input('notes');

        $updateData = [
            'delivery_status' => $deliveryStatus,
            'image_delivery' => $imagePath,
            'updated_at' => now(),
        ];

        if ($deliveryStatus === 6) {
            $updateData['notes'] = $notes;
            $updateData['received_by'] = null;
        } else {
            $updateData['received_by'] = $request->input('received_by');
        }

        // Update database
        DB::table('sales_orders')
            ->where('id', $order->id)
            ->update($updateData);

        $statusMsg = $deliveryStatus === 6 ? 'dikembalikan' : 'sudah dikirim';

        return response()->json([
            'success' => true,
            'message' => "Status pengiriman berhasil diperbarui menjadi {$statusMsg}.",
            'data' => [
                'receipt_no' => $receiptNo,
                'delivery_status' => $deliveryStatus,
                'image_delivery_url' => $this->getStorageUrl($imagePath)
            ]
        ]);
    }

    public function markReadyToShip(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'for' => 'nullable|in:1,3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 400);
        }

        $orderId = (int) $request->input('id');
        $for = $request->input('for', '3');

        $order = DB::table('sales_orders')
            ->where('id', $orderId)
            ->where('for', $for)
            ->whereNull('deleted_at')
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order tidak ditemukan.',
            ], 404);
        }

        if ((int) $order->delivery_status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya order berstatus belum dikirim yang dapat diubah menjadi siap dikirim.',
            ], 400);
        }

        DB::table('sales_orders')
            ->where('id', $order->id)
            ->update([
                'delivery_status' => 4,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Order berhasil ditandai siap dikirim.',
            'data' => [
                'id' => $order->id,
                'receipt_no' => $order->receipt_no,
                'delivery_status' => 4,
            ],
        ]);
    }

    /**
     * Daftar online shop provider untuk dropdown form.
     */
    public function onlineShopProviders(Request $request)
    {
        $providers = OnlineShopProvider::orderBy('name', 'asc')->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $providers,
        ]);
    }

    /**
     * Daftar delivery service (yang aktif) untuk dropdown form.
     */
    public function deliveryServices(Request $request)
    {
        $services = DeliveryService::where('status', '1')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * Daftar produk yang bisa dijual online (whereNotIn online_category_id [4]).
     */
    public function onlineProducts(Request $request)
    {
        $products = Product::with('unit')
            ->whereNotIn('online_category_id', [4])
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'unit_id']);

        return response()->json([
            'success' => true,
            'data' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => $p->unit?->unit ?? '',
            ]),
        ]);
    }

    /**
     * Buat sales order online (for = 3).
     */
    public function storeOnline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'delivery_date' => 'required|date',
            'online_shop_provider_id' => 'required|exists:online_shop_providers,id',
            'delivery_service_id' => 'required|exists:delivery_services,id',
            'receipt_no' => 'required|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|integer|min:0',
            'image_payment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validasi receipt_no unik
        $exists = SalesOrderOnline::where('receipt_no', $request->receipt_no)->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor resi sudah digunakan pada order lain.',
            ], 422);
        }

        // Hitung subtotal & total
        $items = [];
        $totalPrice = 0;
        foreach ($request->items as $item) {
            $subtotal = (int) $item['quantity'] * (int) $item['unit_price'];
            $items[] = [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal_price' => $subtotal,
            ];
            $totalPrice += $subtotal;
        }

        DB::beginTransaction();
        try {
            // Upload image_payment (jika ada) via ImageStorageContract
            $imagePaymentPath = null;
            if ($request->filled('image_payment')) {
                $imagePaymentPath = $request->input('image_payment');
            }

            $order = SalesOrderOnline::create([
                'for' => '3',
                'store_id' => $request->store_id,
                'delivery_date' => $request->delivery_date,
                'online_shop_provider_id' => $request->online_shop_provider_id,
                'delivery_service_id' => $request->delivery_service_id,
                'receipt_no' => $request->receipt_no,
                'image_payment' => $imagePaymentPath,
                'payment_status' => '2',
                'delivery_status' => '1',
                'shipping_cost' => 0,
                'ordered_by_id' => $request->user()->id,
                'total_price' => $totalPrice,
            ]);

            foreach ($items as $item) {
                DetailSalesOrder::create(array_merge(
                    ['sales_order_id' => $order->id],
                    $item
                ));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sales order online berhasil dibuat.',
                'data' => $order->load('detailSalesOrders'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat sales order online: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function getStorageUrl($path)
    {
        return \App\Support\ImageUrlResolver::resolve($path);
    }

    private function paymentProofPrintColumns(): array
    {
        return [
            Schema::hasColumn('sales_orders', 'payment_proof_printed_at')
                ? 'sales_orders.payment_proof_printed_at'
                : DB::raw('NULL as payment_proof_printed_at'),
            Schema::hasColumn('sales_orders', 'payment_proof_print_count')
                ? 'sales_orders.payment_proof_print_count'
                : DB::raw('0 as payment_proof_print_count'),
        ];
    }

    private function appendPaymentProofPrintStatus(object $order): void
    {
        $order->payment_proof_print_count = (int) ($order->payment_proof_print_count ?? 0);
        $order->payment_proof_print_status = $order->payment_proof_printed_at ? 'printed' : 'not_printed';
        $order->payment_proof_print_status_label = $order->payment_proof_printed_at
            ? 'Sudah pernah diprint'
            : 'Belum pernah diprint';
    }
}
