<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SalesOrderController extends Controller
{
    public function search(Request $request)
    {
        $receiptNo = $request->query('receipt_no');

        if ($receiptNo) {
            $order = DB::table('sales_orders')
                ->leftJoin('stores', 'sales_orders.store_id', '=', 'stores.id')
                ->leftJoin('online_shop_providers', 'sales_orders.online_shop_provider_id', '=', 'online_shop_providers.id')
                ->leftJoin('delivery_services', 'sales_orders.delivery_service_id', '=', 'delivery_services.id')
                ->where('sales_orders.receipt_no', $receiptNo)
                ->where('sales_orders.for', 3)
                ->whereNull('sales_orders.deleted_at')
                ->select([
                    'sales_orders.id',
                    'sales_orders.receipt_no',
                    'sales_orders.delivery_status',
                    'sales_orders.received_by',
                    'sales_orders.image_delivery',
                    'sales_orders.image_payment',
                    'stores.nickname as store_name',
                    'online_shop_providers.name as provider_name',
                    'delivery_services.name as delivery_service_name',
                    'sales_orders.delivery_date'
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
                    'units.unit as product_unit'
                ])
                ->get();

            $order->items = $items;
            $order->image_delivery_url = $this->getStorageUrl($order->image_delivery);
            $order->image_payment_url = $this->getStorageUrl($order->image_payment);

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
            ->where('sales_orders.for', 3)
            ->whereNull('sales_orders.deleted_at')
            ->select([
                'sales_orders.id',
                'sales_orders.receipt_no',
                'sales_orders.delivery_status',
                'sales_orders.received_by',
                'sales_orders.image_delivery',
                'sales_orders.image_payment',
                'stores.nickname as store_name',
                'online_shop_providers.name as provider_name',
                'delivery_services.name as delivery_service_name',
                'sales_orders.delivery_date'
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
                    'units.unit as product_unit'
                ])
                ->get();

            $order->items = $items;
            $order->image_delivery_url = $this->getStorageUrl($order->image_delivery);
            $order->image_payment_url = $this->getStorageUrl($order->image_payment);
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

    public function updateDelivery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receipt_no' => 'required|string',
            'image_delivery' => 'required|image|max:5120', // max 5MB
            'received_by' => 'nullable|string|max:255',
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

        if ($order->delivery_status == 2) {
            return response()->json([
                'success' => false,
                'message' => 'Order sudah berstatus valid dan tidak dapat diubah.'
            ], 400);
        }

        // Handle image upload
        $imagePath = null;
        if ($request->hasFile('image_delivery')) {
            $file = $request->file('image_delivery');
            // Store in images/Online/Delivery
            $imagePath = $file->store('images/Online/Delivery', 'public');
        }

        // Update database
        DB::table('sales_orders')
            ->where('id', $order->id)
            ->update([
                'delivery_status' => 3, // Sudah dikirim
                'image_delivery' => $imagePath,
                'received_by' => $request->input('received_by'),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Status pengiriman berhasil diperbarui.',
            'data' => [
                'receipt_no' => $receiptNo,
                'delivery_status' => 3,
                'image_delivery_url' => $this->getStorageUrl($imagePath)
            ]
        ]);
    }

    private function getStorageUrl($path)
    {
        if (!$path) {
            return null;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
