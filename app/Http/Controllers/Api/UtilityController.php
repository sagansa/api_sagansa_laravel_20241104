<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Unit;
use App\Models\Utility;
use App\Models\UtilityProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UtilityController extends Controller
{
    /**
     * Daftar utility. Default hanya status aktif (dipakai dropdown
     * pemakaian/tagihan). ?all=1 mengembalikan semua status (list manajemen
     * admin).
     */
    public function index(Request $request)
    {
        $query = Utility::query()
            ->with('store:id,nickname')
            ->with('utilityProvider:id,name')
            ->with('unit:id,unit');

        if ($request->boolean('all')) {
            // List manajemen admin: sertakan juga yang nonaktif (status 2).
        } else {
            $query->where('status', '1');
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $utilities = $query
            ->orderBy('number', 'asc')
            ->select('id', 'number', 'name', 'store_id', 'utility_provider_id', 'unit_id', 'category', 'status', 'pre_post')
            ->get();

        $data = $utilities->map(function ($utility) {
            return [
                'id' => $utility->id,
                'number' => $utility->number,
                'name' => $utility->name,
                'status' => (int) $utility->status,
                'pre_post' => (int) $utility->pre_post,
                'category' => (int) $utility->category,
                'store_id' => $utility->store_id,
                'store_nickname' => $utility->store?->nickname,
                'utility_provider_id' => $utility->utility_provider_id,
                'utility_provider_name' => $utility->utilityProvider?->name,
                'unit_id' => $utility->unit_id,
                'unit' => $utility->unit?->unit,
                'utility_name' => $utility->utility_name,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Lookup untuk form utility (admin): toko, satuan, provider.
     */
    public function lookups(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'stores' => Store::select('id', 'nickname')->orderBy('nickname')->get(),
                'units' => Unit::select('id', 'unit')->orderBy('unit')->get(),
                'utility_providers' => UtilityProvider::select('id', 'name')->orderBy('name')->get(),
            ],
        ]);
    }

    /**
     * Buat utility baru (admin/super_admin).
     */
    public function store(Request $request)
    {
        if (!$this->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $data = $request->validate([
            'number' => ['required', 'string', 'max:255', 'unique:utilities,number'],
            'name' => ['nullable', 'string', 'max:255'],
            'store_id' => ['required', 'exists:stores,id'],
            'unit_id' => ['required', 'exists:units,id'],
            'utility_provider_id' => ['required', 'exists:utility_providers,id'],
            'pre_post' => ['required', 'integer', 'in:1,2'],
            'category' => ['required', 'integer', 'in:1,2,3'],
            'status' => ['nullable', 'integer', 'in:1,2'],
        ]);

        $utility = Utility::create($data + ['status' => $data['status'] ?? 1]);

        return response()->json([
            'success' => true,
            'message' => 'Utility berhasil dibuat.',
            'data' => $utility,
        ], 201);
    }

    /**
     * Ubah utility (admin/super_admin).
     */
    public function update(Request $request, $id)
    {
        if (!$this->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $utility = Utility::findOrFail($id);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:255', Rule::unique('utilities', 'number')->ignore($utility->id)],
            'name' => ['nullable', 'string', 'max:255'],
            'store_id' => ['required', 'exists:stores,id'],
            'unit_id' => ['required', 'exists:units,id'],
            'utility_provider_id' => ['required', 'exists:utility_providers,id'],
            'pre_post' => ['required', 'integer', 'in:1,2'],
            'category' => ['required', 'integer', 'in:1,2,3'],
            'status' => ['nullable', 'integer', 'in:1,2'],
        ]);

        $utility->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Utility berhasil diperbarui.',
            'data' => $utility->fresh(),
        ]);
    }

    /**
     * Ubah status utility: 1 = aktif, 2 = nonaktif (admin/super_admin).
     */
    public function updateStatus(Request $request, $id)
    {
        if (!$this->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'integer', 'in:1,2'],
        ]);

        $utility = Utility::findOrFail($id);
        $utility->update(['status' => $data['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Status utility berhasil diperbarui.',
            'data' => $utility->fresh(),
        ]);
    }

    private function isAdmin(): bool
    {
        $user = Auth::user();
        return $user && $user->hasAnyRole(['admin', 'super_admin']);
    }
}
