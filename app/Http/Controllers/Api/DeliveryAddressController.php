<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD DeliveryAddress (calon konsumen) milik user yang login.
 *
 * Pemetaan role:
 *  - `sales`: CRUD miliknya sendiri (`user_id = Auth::id()`), `for` = 2
 *    (sesuai pola `DeliveryAddress::boot()` di Filament per role).
 *  - Admin tidak memakai endpoint ini — kelola konsumen lewat Filament.
 *
 * Semua query di-scope `user_id = Auth::id()` sehingga sales tidak bisa
 * melihat/mengubah alamat milik user lain.
 */
class DeliveryAddressController extends Controller
{
    private const FOR = '2';

    /**
     * Relasi wilayah di-eager-load supaya response self-contained (nama
     * provinsi/kota/kecamatan/kelurahan/kode pos untuk list & form edit).
     */
    private const WITH = [
        'province:id,name',
        'city:id,name',
        'district:id,name',
        'subdistrict:id,name',
        'postalCode:id,postal_code',
    ];

    /**
     * GET /delivery-addresses
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = DeliveryAddress::with(self::WITH)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $addresses]);
    }

    /**
     * GET /delivery-addresses/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $address = $this->findOwned($request->user()->id, $id);
        if (!$address) {
            return $this->notFound();
        }
        return response()->json(['success' => true, 'data' => $address]);
    }

    /**
     * POST /delivery-addresses
     *
     * Validasi meniru DeliveryAddressForm (Filament). `for` = 2 untuk sales.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_telp_no' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string'],
            'province_id' => ['required', 'exists:provinces,id'],
            'city_id' => ['required', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'subdistrict_id' => ['nullable', 'exists:subdistricts,id'],
            'postal_code_id' => ['nullable', 'exists:postal_codes,id'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $address = DeliveryAddress::create([
            'for' => self::FOR,
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'recipient_name' => $request->recipient_name,
            'recipient_telp_no' => $request->recipient_telp_no,
            'address' => $request->address,
            'province_id' => $request->province_id,
            'city_id' => $request->city_id,
            'district_id' => $request->input('district_id'),
            'subdistrict_id' => $request->input('subdistrict_id'),
            'postal_code_id' => $request->input('postal_code_id'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
        ]);

        $address->load(self::WITH);

        return response()->json([
            'success' => true,
            'message' => 'Konsumen berhasil ditambahkan.',
            'data' => $address,
        ], 201);
    }

    /**
     * PUT /delivery-addresses/{id}
     *
     * Field yang tidak dikirim diabaikan (partial update). Field nullable
     * yang dikirim null → di-clear (mis. koordinat).
     */
    public function update(Request $request, $id): JsonResponse
    {
        $address = $this->findOwned($request->user()->id, $id);
        if (!$address) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'recipient_name' => ['sometimes', 'required', 'string', 'max:255'],
            'recipient_telp_no' => ['sometimes', 'required', 'string', 'max:20'],
            'address' => ['sometimes', 'required', 'string'],
            'province_id' => ['sometimes', 'required', 'exists:provinces,id'],
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'subdistrict_id' => ['nullable', 'exists:subdistricts,id'],
            'postal_code_id' => ['nullable', 'exists:postal_codes,id'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $data = array_filter([
            'name' => $request->input('name'),
            'recipient_name' => $request->input('recipient_name'),
            'recipient_telp_no' => $request->input('recipient_telp_no'),
            'address' => $request->input('address'),
            'province_id' => $request->input('province_id'),
            'city_id' => $request->input('city_id'),
            'district_id' => $request->input('district_id'),
            'subdistrict_id' => $request->input('subdistrict_id'),
            'postal_code_id' => $request->input('postal_code_id'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
        ], fn ($v) => $v !== null);

        $address->update($data);
        $address->load(self::WITH);

        return response()->json([
            'success' => true,
            'message' => 'Konsumen berhasil diperbarui.',
            'data' => $address,
        ]);
    }

    /**
     * DELETE /delivery-addresses/{id}
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $address = $this->findOwned($request->user()->id, $id);
        if (!$address) {
            return $this->notFound();
        }

        $address->delete();

        return response()->json([
            'success' => true,
            'message' => 'Konsumen berhasil dihapus.',
        ]);
    }

    // ---------- helpers ----------

    /**
     * Cari alamat milik user tertentu (soft-delete otomatis di-exclude).
     */
    private function findOwned(int $userId, $id): ?DeliveryAddress
    {
        return DeliveryAddress::where('user_id', $userId)->find($id);
    }

    private function validationFailed($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validasi gagal.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Alamat tidak ditemukan.',
        ], 404);
    }
}