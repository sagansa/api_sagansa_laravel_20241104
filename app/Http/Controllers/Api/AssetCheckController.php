<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCheck;
use App\Models\AssetCheckItem;
use App\Models\AssetIssue;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Pemeriksaan aset.
 *
 * Akses berbasis PENUGASAN: hanya PIC/creator aset yang bisa submit check.
 * Admin lihat semua check.
 */
class AssetCheckController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = AssetCheck::with([
            'asset:id,name,code,store_id',
            'asset.category:id,name',
            'checkedBy:id,name',
            'items',
        ]);

        // Assignment scope: non-admin hanya lihat check dari aset miliknya.
        if (!$user->hasRole('admin')) {
            $query->whereHas('asset', function ($aq) use ($user) {
                $aq->where(function ($q) use ($user) {
                    $q->where('pic_user_id', $user->id)
                      ->orWhere('created_by_id', $user->id);
                });
            });
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->asset_id);
        }
        if ($request->filled('from')) {
            $query->where('check_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('check_date', '<=', $request->to);
        }
        if ($request->filled('store_id')) {
            $query->whereHas('asset', fn($q) => $q->where('store_id', $request->store_id));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        $checks = $query->orderBy('check_date', 'desc')->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $checks,
        ]);
    }

    public function show(Request $request, $id)
    {
        $check = AssetCheck::with([
            'asset.category',
            'asset.store',
            'checkedBy:id,name',
            'items',
            'issues',
        ])->find($id);

        if (!$check) {
            return response()->json([
                'success' => false,
                'message' => 'Data pemeriksaan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $check,
        ]);
    }

    /**
     * Submit pemeriksaan aset. Menerima:
     *   - asset_id, check_date, condition_before, condition_after, severity, notes
     *   - latitude, longitude (geotag wajib)
     *   - photos[] (file gambar, multipart)
     *   - checklist[] (array of {label, value: 0|1, note?})
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'asset_id' => 'required|exists:assets,id',
            'check_date' => 'required|date',
            'condition_before' => 'required|integer|in:1,2,3,4',
            'condition_after' => 'required|integer|in:1,2,3,4',
            'severity' => 'required|integer|in:1,2,3,4',
            'notes' => 'nullable|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'photos' => 'nullable|array',
            'photos.*' => 'image|max:4096',
            'checklist' => 'nullable|array',
            'checklist.*.label' => 'required_with:checklist|string|max:255',
            'checklist.*.value' => 'required_with:checklist|integer|in:0,1',
            'checklist.*.note' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $asset = Asset::with('category')->findOrFail($request->asset_id);

        // Assignment gate: hanya PIC, creator, atau admin yang boleh check.
        $user = $request->user();
        if (!$user->hasRole('admin')
            && $asset->pic_user_id !== $user->id
            && $asset->created_by_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak ditugaskan untuk memeriksa aset ini.',
            ], 403);
        }

        // Cegah double-submit di hari yang sama untuk aset yang sama.
        $alreadyToday = AssetCheck::where('asset_id', $asset->id)
            ->whereDate('check_date', Carbon::parse($request->check_date)->toDateString())
            ->exists();
        if ($alreadyToday) {
            return response()->json([
                'success' => false,
                'message' => 'Aset sudah diperiksa pada tanggal tersebut.',
            ], 422);
        }

        // Upload foto-foto.
        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                $photoPaths[] = $file->store('assets/checks', 'public');
            }
        }

        $checkDate = Carbon::parse($request->check_date);

        try {
            $createdIssue = DB::transaction(function () use ($request, $asset, $photoPaths, $checkDate) {
                $check = AssetCheck::create([
                    'asset_id' => $asset->id,
                    'checked_by_id' => $request->user()->id,
                    'check_date' => $checkDate->toDateString(),
                    'condition_before' => $request->condition_before,
                    'condition_after' => $request->condition_after,
                    'severity' => $request->severity,
                    'status' => AssetCheck::STATUS_SUBMITTED,
                    'notes' => $request->notes,
                    'photos' => $photoPaths ?: null,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                ]);

                // Snapshot item checklist.
                if (is_array($request->checklist)) {
                    foreach ($request->checklist as $item) {
                        AssetCheckItem::create([
                            'asset_check_id' => $check->id,
                            'label' => $item['label'],
                            'value' => $item['value'],
                            'note' => $item['note'] ?? null,
                        ]);
                    }
                }

                // Update asset: last_check_at, condition, reschedule next check.
                $asset->last_check_at = $checkDate;
                $asset->condition = $request->condition_after;
                $asset->rescheduleNextCheck($checkDate);
                $asset->save();

                // Auto-create issue bila severity >= ringan.
                $issue = null;
                if ((int) $request->severity >= AssetCheck::SEVERITY_RINGAN) {
                    $issue = AssetIssue::create([
                        'asset_id' => $asset->id,
                        'asset_check_id' => $check->id,
                        'severity' => $request->severity,
                        'description' => $request->notes
                            ? "Temuan saat check ({$checkDate->toDateString()}): " . $request->notes
                            : "Temuan saat check pada {$checkDate->toDateString()}.",
                        'status' => AssetIssue::STATUS_OPEN,
                        'reported_by_id' => $request->user()->id,
                    ]);
                }

                return $issue;
            });

            $check = AssetCheck::with(['asset.category', 'checkedBy:id,name', 'items'])
                ->find(DB::getPdo()->lastInsertId());

            // Reload issue (transaksi sudah commit).
            $issue = $createdIssue ? AssetIssue::find($createdIssue->id) : null;

            return response()->json([
                'success' => true,
                'message' => 'Pemeriksaan berhasil disimpan.'
                    . ($createdIssue ? ' Issue otomatis dibuat.' : ''),
                'data' => [
                    'check' => $check,
                    'issue' => $issue,
                ],
            ], 201);
        } catch (\Throwable $e) {
            // Bersihkan foto yang sudah di-upload bila transaksi gagal.
            foreach ($photoPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }
    }

    /**
     * Cek apakah aset tertentu sudah diperiksa hari ini (mirip pola
     * StorageStockController::todayStatus).
     */
    public function checkTodayStatus(Request $request, int $assetId)
    {
        $today = Carbon::now()->toDateString();
        $exists = AssetCheck::where('asset_id', $assetId)
            ->whereDate('check_date', $today)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'has_checked' => $exists,
                'date' => $today,
            ],
        ]);
    }
}
