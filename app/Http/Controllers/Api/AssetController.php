<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Presence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD & dashboard untuk instance aset.
 *
 * Akses HYBRID — user dapat melihat/mengelola aset bila salah satu berlaku:
 *   1. Dia adalah admin (lihat semua).
 *   2. Dia adalah PIC aset (pic_user_id).
 *   3. Dia adalah pembuat aset (created_by_id).
 *   4. Dia ber-role storage-staff DAN sedang check-in di store aset tsb
 *      hari ini (mengikuti pola StorageStockController).
 *
 * Roles tidak eksklusif (satu user bisa staff + storage-staff), sehingga
 * rule #4 memastikan storage-staff tetap bisa akses aset store-nya walau
 * belum di-assign sebagai PIC eksplisit.
 */
class AssetController extends Controller
{
    /**
     * Daftar user yang potensial jadi PIC aset. Admin saja.
     * Berguna untuk dropdown PIC di form aset.
     */
    public function pics(Request $request)
    {
        $user = $request->user();

        // Admin bebas pilih siapa saja; non-admin tidak butuh daftar ini
        // (PIC di-set admin). Tetap kembalikan diri sendiri untuk fallback UI.
        $query = User::query();
        if (!$user->hasRole('admin')) {
            $query->where('id', $user->id);
        }

        $pics = $query->orderBy('name', 'asc')->get(['id', 'name', 'email']);

        return response()->json([
            'success' => true,
            'data' => $pics,
        ]);
    }

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
            'pic:id,name',
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
            'pic:id,name',
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
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:assets,code',
            'asset_category_id' => 'required|exists:asset_categories,id',
            'store_id' => 'required|exists:stores,id',
            'product_id' => 'nullable|exists:products,id',
            'pic_user_id' => 'nullable|integer',
            'condition' => 'nullable|integer|in:1,2,3,4',
            'status' => 'nullable|integer|in:1,2,3',
            'purchase_date' => 'nullable|date',
            'next_check_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'photo' => 'nullable|image|max:4096',
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

        // PIC default = pembuat aset bila tidak dispesifikan atau bila
        // non-admin mencoba menetapkan PIC lain (tidak boleh).
        $isAdmin = $request->user()->hasRole('admin');
        if (!$isAdmin || empty($data['pic_user_id'])) {
            $data['pic_user_id'] = $request->user()->id;
        }

        // Jika next_check_at kosong, turunkan dari frekuensi kategori mulai hari ini.
        if (empty($data['next_check_at'])) {
            $category = AssetCategory::find($data['asset_category_id']);
            $data['next_check_at'] = $category ? $category->computeNextCheckAt() : Carbon::now()->addDays(30);
        }

        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('assets/photos', 'public');
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
            'pic_user_id' => 'nullable|integer',
            'condition' => 'nullable|integer|in:1,2,3,4',
            'status' => 'nullable|integer|in:1,2,3',
            'purchase_date' => 'nullable|date',
            'next_check_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'photo' => 'nullable|image|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->safe()->except(['photo']);

        // Hanya admin yang boleh mengganti PIC ke user lain.
        if (!$request->user()->hasRole('admin')) {
            unset($data['pic_user_id']);
        }

        if ($request->hasFile('photo')) {
            if ($asset->photo) {
                Storage::disk('public')->delete($asset->photo);
            }
            $data['photo'] = $request->file('photo')->store('assets/photos', 'public');
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
            Storage::disk('public')->delete($asset->photo);
        }

        $asset->delete();

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil dihapus.',
        ]);
    }

    // ---- Hybrid scope helpers ------------------------------------------

    /**
     * Apply hybrid scope ke query Asset. Admin lihat semua; user lain lihat
     * aset di mana dia PIC/creator ATAU ber-role storage-staff di store
     * tempat dia check-in hari ini.
     */
    private function applyAssignmentScope(Request $request, $query): void
    {
        $user = $request->user();
        if ($user->hasRole('admin')) {
            return;
        }

        $storeId = $this->userTodayStoreId($user);

        $query->where(function ($q) use ($user, $storeId) {
            $q->where('pic_user_id', $user->id)
              ->orWhere('created_by_id', $user->id);

            // storage-staff yang sedang check-in di store -> lihat semua
            // aset di store tsb.
            if ($storeId !== null) {
                $q->orWhere('store_id', $storeId);
            }
        });
    }

    /**
     * Sama dengan applyAssignmentScope, untuk query builder generic (dipakai
     * di whereHas('asset', ...) pada AssetIssue/AssetCheck).
     */
    private function applyAssignmentScopeToQueryBuilder(Request $request, $aq): void
    {
        $user = $request->user();
        if ($user->hasRole('admin')) {
            return;
        }

        $storeId = $this->userTodayStoreId($user);

        $aq->where(function ($q) use ($user, $storeId) {
            $q->where('pic_user_id', $user->id)
              ->orWhere('created_by_id', $user->id);
            if ($storeId !== null) {
                $q->orWhere('store_id', $storeId);
            }
        });
    }

    /**
     * Cek 403 bila user tidak berhak akses aset (hybrid rules).
     */
    private function enforceAssignmentScope(Request $request, Asset $asset): void
    {
        $user = $request->user();
        $storeId = $this->userTodayStoreId($user);

        $allowed = $user->hasRole('admin')
            || $asset->pic_user_id === $user->id
            || $asset->created_by_id === $user->id
            || $storeId === $asset->store_id; // storage-staff di store tsb

        abort_if(!$allowed, 403, 'Anda tidak memiliki akses ke aset ini.');
    }

    /**
     * Store tempat user check-in hari ini (polanya sama dengan
     * StorageStockController). Null bila tidak sedang check-in.
     */
    private function userTodayStoreId($user): ?int
    {
        $presence = Presence::where('created_by_id', $user->id)
            ->whereDate('check_in', Carbon::now()->toDateString())
            ->first();
        return $presence?->store_id;
    }
}
