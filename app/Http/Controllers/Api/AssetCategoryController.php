<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD kategori aset. Index publik (untuk semua user terauth), sisanya
 * (store/update/destroy) dibatasi untuk admin.
 */
class AssetCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = AssetCategory::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('is_active', true);
        }

        $categories = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat menambah kategori aset.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'frequency_days' => 'required|integer|min:1|max:3650',
            'checklist_definition' => 'nullable|array',
            'checklist_definition.*.label' => 'required_with:checklist_definition|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $category = AssetCategory::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Kategori aset berhasil ditambahkan.',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat memperbarui kategori aset.',
            ], 403);
        }

        $category = AssetCategory::find($id);
        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori aset tidak ditemukan.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'frequency_days' => 'sometimes|required|integer|min:1|max:3650',
            'checklist_definition' => 'nullable|array',
            'checklist_definition.*.label' => 'required_with:checklist_definition|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $category->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Kategori aset berhasil diperbarui.',
            'data' => $category->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat menghapus kategori aset.',
            ], 403);
        }

        $category = AssetCategory::find($id);
        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori aset tidak ditemukan.',
            ], 404);
        }

        // Lindungi kategori yang masih dipakai oleh aset atau produk.
        if ($category->assets()->exists() || $category->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori tidak dapat dihapus karena masih dipakai oleh aset atau produk.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategori aset berhasil dihapus.',
        ]);
    }
}
