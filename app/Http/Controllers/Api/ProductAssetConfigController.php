<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Menandai produk sebagai aset (is_asset + asset_category_id) dan menyediakan
 * daftar produk-aset untuk dipakai oleh UI create-asset product-driven.
 *
 * Akses: hanya admin yang boleh mengubah flag aset pada produk. Daftar
 * kategori & produk-aset terbuka untuk semua user terauth.
 */
class ProductAssetConfigController extends Controller
{
    /**
     * Daftar kategori aset (untuk dropdown saat marking produk maupun di UI).
     */
    public function categories()
    {
        return response()->json([
            'success' => true,
            'data' => AssetCategory::active()->orderBy('name', 'asc')->get(),
        ]);
    }

    /**
     * Daftar produk yang ditandai sebagai aset. Dipakai oleh product-picker
     * di halaman create-asset Flutter.
     */
    public function index(Request $request)
    {
        $query = Product::with(['unit', 'assetCategory'])
            ->where('is_asset', true)
            ->orderBy('name', 'asc');

        if ($request->filled('asset_category_id')) {
            $query->where('asset_category_id', $request->asset_category_id);
        }

        $products = $query->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Tandai / update flag aset pada sebuah produk. Admin saja.
     *
     * Body: { is_asset: bool, asset_category_id?: int }
     * Bila is_asset=false, asset_category_id otomatis di-null-kan.
     */
    public function update(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat menandai produk sebagai aset.',
            ], 403);
        }

        $product = Product::find($id);
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'is_asset' => 'required|boolean',
            'asset_category_id' => 'nullable|exists:asset_categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Bila produk berhenti menjadi aset, kategori di-reset.
        if (!$data['is_asset']) {
            $data['asset_category_id'] = null;
        } elseif (empty($data['asset_category_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori aset wajib dipilih bila produk ditandai sebagai aset.',
            ], 422);
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'message' => $data['is_asset']
                ? 'Produk ditandai sebagai aset.'
                : 'Status aset pada produk dicabut.',
            'data' => $product->fresh(['unit', 'assetCategory']),
        ]);
    }
}
