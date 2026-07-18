<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use Illuminate\Http\Request;

/**
 * API baca master resep produksi. Mobile pakai untuk:
 *  - GET /recipes                 → list resep aktif (untuk pilih saat create production)
 *  - GET /recipes/{id}            → detail resep + ingredient
 *  - GET /recipes/by-product/{id} → resep aktif untuk produk output tertentu
 *
 * Hanya baca — penulisan resep via apps/admin (Filament). Mobile fokus ke
 * operasional produksi (list/create/approve production), bukan master data.
 */
class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = Recipe::with([
            'product:id,name,sku',
            'outputUnit:id,name',
            'ingredients.product:id,name,sku',
            'ingredients.unit:id,name',
        ]);

        // Default: hanya resep aktif (parameter ?include_inactive=1 utk semua).
        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        $perPage = $request->integer('per_page', 50);
        $recipes = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $recipes->items(),
            'pagination' => [
                'current_page' => $recipes->currentPage(),
                'last_page'    => $recipes->lastPage(),
                'per_page'     => $recipes->perPage(),
                'total'        => $recipes->total(),
            ],
        ]);
    }

    public function show($id)
    {
        $recipe = Recipe::with([
            'product:id,name,sku',
            'outputUnit:id,name',
            'ingredients.product:id,name,sku',
            'ingredients.unit:id,name',
        ])->find($id);

        if (!$recipe) {
            return response()->json([
                'success' => false,
                'message' => 'Resep tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $recipe,
        ]);
    }

    /**
     * Resep aktif untuk sebuah produk output. Mobile pakai saat user pilih
     * produk yang akan diproduksi → auto-load ingredient default.
     */
    public function byProduct($productId)
    {
        $recipe = Recipe::with([
            'product:id,name,sku',
            'outputUnit:id,name',
            'ingredients.product:id,name,sku',
            'ingredients.unit:id,name',
        ])
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (!$recipe) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada resep aktif untuk produk ini.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $recipe,
        ]);
    }
}
