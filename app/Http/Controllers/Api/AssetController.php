<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Presence;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD & dashboard untuk instance aset.
 *
 * Akses: SEMUA user terauth (admin/staff/storage-staff) bisa melihat semua
 * aset di seluruh store. Filtering store bersifat opsional (lewat query
 * string ?store_id=). Presence hanya dipakai sebagai default awal untuk
 * kemudahan pemeriksaan aset (lihat method defaultStoreId).
 *
 * Alasan: pegawai bisa bekerja di multiple store, jadi list tidak boleh
 * terkunci ke store presence.
 */
class AssetController extends Controller
{
    /**
     * Listing dengan filter. Response membawa relasi kategori, store, dan
     * counter open-issue untuk badge UI.
     */
    public function index(Request $request)
    {
        $query = Asset::with([
            'category:id,name,frequency_days',
            'store:id,nickname,name',
            'product:id,name,sku',
            'createdBy:id,name',
        ])->withCount(['issues as open_issues_count' => function ($q) {
            $q->where('status', \App\Models\AssetIssue::STATUS_OPEN);
        }]);

        $this->applyAssignmentScope($request, $query);

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        if ($request->filled('asset_category_id')) {
            $query->where('asset_category_id', $request->asset_category_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('condition')) {
            $query->where('condition', $request->condition);
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter "jatuh tempo".
        if ($request->filled('due')) {
            switch ($request->due) {
                case 'today':
                    $query->whereNotNull('next_check_at')
                        ->where('next_check_at', '<=', Carbon::now()->endOfDay())
                        ->where('next_check_at', '>=', Carbon::now()->startOfDay());
                    break;
                case 'overdue':
                    $query->whereNotNull('next_check_at')
                        ->where('next_check_at', '<', Carbon::now()->startOfDay());
                    break;
                case 'week':
                    $query->whereNotNull('next_check_at')
                        ->whereBetween('next_check_at', [Carbon::now()->startOfDay(), Carbon::now()->addDays(7)->endOfDay()]);
                    break;
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $assets = $query->orderBy('next_check_at', 'asc')->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $assets,
        ]);
    }

    /**
     * Ringkasan untuk dashboard: dueToday, overdue, dueThisWeek, openIssues,
     * completionRate (persentase check tepat waktu bulan berjalan).
     */
    public function dashboardSummary(Request $request)
    {
        $baseQuery = Asset::query();
        $this->applyAssignmentScope($request, $baseQuery);

        $dueToday = (clone $baseQuery)->active()
            ->whereNotNull('next_check_at')
            ->whereBetween('next_check_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->count();

        $overdue = (clone $baseQuery)->active()
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<', Carbon::now()->startOfDay())
            ->count();

        $dueThisWeek = (clone $baseQuery)->active()
            ->whereNotNull('next_check_at')
            ->whereBetween('next_check_at', [Carbon::now()->startOfDay(), Carbon::now()->addDays(7)->endOfDay()])
            ->count();

        // Issue yang asetnya ter-scope ke user login (PIC/creator).
        $openIssues = \App\Models\AssetIssue::query()
            ->where('status', \App\Models\AssetIssue::STATUS_OPEN)
            ->whereHas('asset', function ($aq) use ($request) {
                $this->applyAssignmentScopeToQueryBuilder($request, $aq);
            })
            ->count();

        // Completion rate bulan berjalan.
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $dueThisMonth = (clone $baseQuery)->active()
            ->whereNotNull('next_check_at')
            ->whereBetween('next_check_at', [$monthStart, $monthEnd])
            ->count();

        $checkedThisMonth = \App\Models\AssetCheck::query()
            ->whereBetween('check_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereHas('asset', function ($aq) use ($request) {
                $this->applyAssignmentScopeToQueryBuilder($request, $aq);
            })
            ->distinct('asset_id')
            ->count('asset_id');

        $completionRate = $dueThisMonth > 0
            ? round(min(100, ($checkedThisMonth / $dueThisMonth) * 100), 1)
            : 100.0;

        return response()->json([
            'success' => true,
            'data' => [
                'due_today' => $dueToday,
                'overdue' => $overdue,
                'due_this_week' => $dueThisWeek,
                'open_issues' => $openIssues,
                'completion_rate' => $completionRate,
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $asset = Asset::with([
            'category',
            'store',
            'product',
            'createdBy:id,name',
            'checks' => fn($q) => $q->latest('check_date')->limit(20),
            'checks.checkedBy:id,name',
            'checks.items',
            'openIssues',
        ])->find($id);

        if (!$asset) {
            return response()->json([
                'success' => false,
                'message' => 'Aset tidak ditemukan.',
            ], 404);
        }

        $this->enforceAssignmentScope($request, $asset);

        return response()->json([
            'success' => true,
            'data' => $asset,
        ]);
    }

    /**
     * Catat aset manual (bukan dari pembelian). Foto opsional via multipart.
     */
    /**
     * Buat instance aset dari produk yang sudah ditandai sebagai aset
     * (is_asset=true). Nama, kode, dan kategori otomatis diambil dari produk
     * agar konsisten antar toko. qty unit → qty instance aset yang dibuat.
     *
     * Body: { product_id: int, store_id: int, qty: int }
     */
    public function createFromProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'store_id' => 'required|exists:stores,id',
            'qty' => 'required|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $product = Product::with('assetCategory')->find($request->product_id);

        if (!$product || !$product->is_asset || !$product->asset_category_id) {
            return response()->json([
                'success' => false,
                'message' => 'Produk ini tidak ditandai sebagai aset. Tandai dahulu via admin.',
            ], 422);
        }

        $qty = (int) $request->qty;
        $created = DB::transaction(function () use ($product, $request, $qty) {
            $nextCheckAt = $product->assetCategory
                ? $product->assetCategory->computeNextCheckAt()
                : Carbon::now()->addDays(30);

            $ids = [];
            for ($i = 0; $i < $qty; $i++) {
                $asset = Asset::create([
                    'code' => Asset::generateCode(),
                    'name' => $product->name,
                    'product_id' => $product->id,
                    'asset_category_id' => $product->asset_category_id,
                    'store_id' => $request->store_id,
                    'condition' => Asset::CONDITION_BAIK,
                    'status' => Asset::STATUS_AKTIF,
                    'purchase_date' => now()->toDateString(),
                    'next_check_at' => $nextCheckAt,
                    'created_by_id' => $request->user()->id,
                ]);
                $ids[] = $asset->id;
            }
            return $ids;
        });

        $assets = Asset::whereIn('id', $created)
            ->with(['category', 'store', 'product'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => "{$qty} aset berhasil dibuat dari produk \"{$product->name}\".",
            'data' => $assets,
        ], 201);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:assets,code',
            'asset_category_id' => 'required|exists:asset_categories,id',
            'store_id' => 'required|exists:stores,id',
            'product_id' => 'nullable|exists:products,id',
            'condition' => 'nullable|integer|in:1,2,3,4',
            'status' => 'nullable|integer|in:1,2,3',
            'purchase_date' => 'nullable|date',
            'next_check_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'photo' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->safe()->except(['photo']);
        $data['code'] = $data['code'] ?? Asset::generateCode();
        $data['created_by_id'] = $request->user()->id;
        $data['condition'] = $data['condition'] ?? Asset::CONDITION_BAIK;
        $data['status'] = $data['status'] ?? Asset::STATUS_AKTIF;

        // Jika next_check_at kosong, turunkan dari frekuensi kategori mulai hari ini.
        if (empty($data['next_check_at'])) {
            $category = AssetCategory::find($data['asset_category_id']);
            $data['next_check_at'] = $category ? $category->computeNextCheckAt() : Carbon::now()->addDays(30);
        }

        if ($request->filled('photo')) {
            $data['photo'] = $request->input('photo');
        }

        $asset = Asset::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil dicatat.',
            'data' => $asset->load(['category', 'store', 'product']),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $asset = Asset::find($id);
        if (!$asset) {
            return response()->json([
                'success' => false,
                'message' => 'Aset tidak ditemukan.',
            ], 404);
        }

        $this->enforceAssignmentScope($request, $asset);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|nullable|string|max:50|unique:assets,code,' . $id,
            'asset_category_id' => 'sometimes|required|exists:asset_categories,id',
            'store_id' => 'sometimes|required|exists:stores,id',
            'product_id' => 'nullable|exists:products,id',
            'condition' => 'nullable|integer|in:1,2,3,4',
            'status' => 'nullable|integer|in:1,2,3',
            'purchase_date' => 'nullable|date',
            'next_check_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'photo' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->safe()->except(['photo']);

        if ($request->filled('photo')) {
            if ($asset->photo && $asset->photo !== $request->input('photo')) {
                app(\App\Contracts\ImageStorageContract::class)->delete($asset->photo);
            }
            $data['photo'] = $request->input('photo');
        }

        $asset->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil diperbarui.',
            'data' => $asset->fresh(['category', 'store', 'product']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat menghapus aset.',
            ], 403);
        }

        $asset = Asset::find($id);
        if (!$asset) {
            return response()->json([
                'success' => false,
                'message' => 'Aset tidak ditemukan.',
            ], 404);
        }

        if ($asset->photo) {
            app(\App\Contracts\ImageStorageContract::class)->delete($asset->photo);
        }

        $asset->delete();

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil dihapus.',
        ]);
    }

    // ---- Hybrid scope helpers ------------------------------------------

    /**
     * Apply scope ke query Asset. SEMUA user terauth lihat semua aset di
     * seluruh store. Filter store opsional (sudah ditangani di index() lewat
     * request store_id). Method ini disimpan sebagai no-op agar signature
     * stabil dan mudah ditambah batasan di masa depan bila perlu.
     */
    private function applyAssignmentScope(Request $request, $query): void
    {
        // No-op: semua user terauth melihat semua aset. Filter store
        // dilakukan secara eksplisit oleh index() / dashboardSummary().
    }

    private function applyAssignmentScopeToQueryBuilder(Request $request, $aq): void
    {
        // No-op (lihat applyAssignmentScope).
    }

    /**
     * Cek 403 bila user tidak terauth. Saat ini semua user terauth berhak
     * mengakses aset apapun (read-only terhadap aset store lain).
     */
    private function enforceAssignmentScope(Request $request, Asset $asset): void
    {
        // No-op.
    }

    /**
     * Store tempat user check-in hari ini. Dipakai sebagai default filter
     * awal di Flutter (untuk kemudahan), bukan sebagai pembatas akses.
     */
    private function userTodayStoreId($user): ?int
    {
        $presence = Presence::where('created_by_id', $user->id)
            ->whereDate('check_in', Carbon::now()->toDateString())
            ->first();
        return $presence?->store_id;
    }

    /**
     * Endpoint bantuan: kembalikan store_id dari presence hari ini untuk
     * user login. Dipakai Flutter sebagai default filter awal.
     */
    public function currentStore(Request $request)
    {
        $storeId = $this->userTodayStoreId($request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $storeId,
            ],
        ]);
    }
}
