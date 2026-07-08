<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Province;
use App\Models\City;
use App\Models\District;
use App\Models\Subdistrict;
use App\Models\PostalCode;
use App\Models\Bank;
use App\Services\QrisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    /**
     * Display a listing of suppliers.
     */
    public function index(Request $request)
    {
        $query = Supplier::with(['province', 'city', 'district', 'subdistrict', 'postalCode', 'bank', 'user']);

        // Search by name, bank account name, or account no
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('bank_account_name', 'like', "%{$search}%")
                  ->orWhere('bank_account_no', 'like', "%{$search}%");
            });
        }

        // Filter by bank
        if ($request->filled('bank_id')) {
            $query->where('bank_id', $request->bank_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $suppliers = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $suppliers
        ]);
    }

    /**
     * Display the specified supplier.
     */
    public function show($id)
    {
        $supplier = Supplier::with(['province', 'city', 'district', 'subdistrict', 'postalCode', 'bank', 'user'])->find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $supplier
        ]);
    }

    /**
     * Store a newly created supplier in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'no_telp' => 'nullable|string|max:20',
            'address' => 'required|string',
            'province_id' => 'required|exists:provinces,id',
            'city_id' => 'required|exists:cities,id',
            'district_id' => 'nullable|exists:districts,id',
            'subdistrict_id' => 'nullable|exists:subdistricts,id',
            'postal_code_id' => 'nullable|exists:postal_codes,id',
            'bank_id' => 'nullable|exists:banks,id',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_no' => 'nullable|string|max:50',
            'image' => 'nullable|image|max:2048',
            'qris' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->except(['image', 'status', 'user_id']);
        
        // Capital case formatting for name
        $data['name'] = ucwords(strtolower($request->name));
        $data['status'] = 1; // Default: belum diperiksa
        $data['user_id'] = $request->user()->id;

        // Image upload handling
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('images/Supplier', 'public');
        }

        $supplier = Supplier::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Supplier berhasil ditambahkan.',
            'data' => $supplier->load(['province', 'city', 'district', 'subdistrict', 'postalCode', 'bank', 'user'])
        ], 201);
    }

    /**
     * Update the specified supplier in storage.
     */
    public function update(Request $request, $id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier tidak ditemukan.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'no_telp' => 'nullable|string|max:20',
            'address' => 'required|string',
            'province_id' => 'required|exists:provinces,id',
            'city_id' => 'required|exists:cities,id',
            'district_id' => 'nullable|exists:districts,id',
            'subdistrict_id' => 'nullable|exists:subdistricts,id',
            'postal_code_id' => 'nullable|exists:postal_codes,id',
            'bank_id' => 'nullable|exists:banks,id',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_no' => 'nullable|string|max:50',
            'image' => 'nullable|image|max:2048',
            'status' => 'nullable|integer|in:1,2,3',
            'qris' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->except(['image', 'user_id']);

        // Capital case formatting for name
        $data['name'] = ucwords(strtolower($request->name));

        // Manage status updates: only allowed for admin
        if ($request->has('status')) {
            if (!$request->user()->hasRole('admin')) {
                unset($data['status']); // Staff cannot change status
            }
        }

        // Image upload handling
        if ($request->hasFile('image')) {
            // Delete old image if it exists
            if ($supplier->image) {
                Storage::disk('public')->delete($supplier->image);
            }
            $data['image'] = $request->file('image')->store('images/Supplier', 'public');
        }

        $supplier->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Supplier berhasil diperbarui.',
            'data' => $supplier->load(['province', 'city', 'district', 'subdistrict', 'postalCode', 'bank', 'user'])
        ]);
    }

    /**
     * Remove the specified supplier from storage.
     */
    public function destroy($id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier tidak ditemukan.'
            ], 404);
        }

        // Delete associated image
        if ($supplier->image) {
            Storage::disk('public')->delete($supplier->image);
        }

        $supplier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Supplier berhasil dihapus.'
        ]);
    }

    /**
     * Lookups for addresses and banks
     */
    public function provinces()
    {
        return response()->json([
            'success' => true,
            'data' => Province::orderBy('name')->get(['id', 'name'])
        ]);
    }

    public function cities(Request $request)
    {
        $request->validate(['province_id' => 'required|exists:provinces,id']);
        return response()->json([
            'success' => true,
            'data' => City::where('province_id', $request->province_id)->orderBy('name')->get(['id', 'name'])
        ]);
    }

    public function districts(Request $request)
    {
        $request->validate(['city_id' => 'required|exists:cities,id']);
        return response()->json([
            'success' => true,
            'data' => District::where('city_id', $request->city_id)->orderBy('name')->get(['id', 'name'])
        ]);
    }

    public function subdistricts(Request $request)
    {
        $request->validate(['district_id' => 'required|exists:districts,id']);
        return response()->json([
            'success' => true,
            'data' => Subdistrict::where('district_id', $request->district_id)->orderBy('name')->get(['id', 'name'])
        ]);
    }

    public function postalCodes(Request $request)
    {
        $request->validate(['subdistrict_id' => 'required|exists:subdistricts,id']);
        return response()->json([
            'success' => true,
            'data' => PostalCode::where('subdistrict_id', $request->subdistrict_id)->orderBy('postal_code')->get(['id', 'postal_code'])
        ]);
    }

    public function banks()
    {
        return response()->json([
            'success' => true,
            'data' => Bank::orderBy('name')->get(['id', 'name'])
        ]);
    }

    /**
     * Validate and parse a QRIS payload.
     */
    public function validateQris(Request $request, $id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier tidak ditemukan.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'qris' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $qrisService = app(QrisService::class);
        $parsed = $qrisService->parsePayload($request->qris);

        if (!$parsed['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'QRIS payload tidak valid.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'merchant_name' => $parsed['merchant_name'],
                'merchant_city' => $parsed['merchant_city'],
                'merchant_nmid' => $qrisService->getMerchantNmid($parsed),
                'point_of_initiation_label' => $parsed['point_of_initiation_label'],
                'currency' => $parsed['currency'],
            ],
        ]);
    }
}
