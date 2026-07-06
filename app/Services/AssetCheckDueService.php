<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Presence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Memproses aset yang jatuh tempo pemeriksaan dan mengirim pengingat FCM ke
 * pengguna yang ber-peran terkait di store aset tersebut.
 *
 * Penerima notifikasi (urutan):
 *   1. User ber-role storage-staff/admin yang check-in di store aset tsb
 *      hari ini.
 *   2. User ber-role tsb yang pernah check-in di store tsb (30 hari terakhir).
 *   3. Semua admin (fallback terakhir).
 */
class AssetCheckDueService
{
    public function __construct(protected FcmService $fcm)
    {
    }

    /**
     * Proses semua aset yang jatuh tempo. Mengembalikan ringkasan statistik.
     *
     * @return array{processed:int, notified_users:int, sent:int, no_recipient:int}
     */
    public function processDueChecks(?Carbon $at = null): array
    {
        $at = $at ?? Carbon::now();
        $stats = ['processed' => 0, 'notified_users' => 0, 'sent' => 0, 'no_recipient' => 0];

        $due = Asset::active()
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', $at->copy()->endOfDay())
            ->limit(500)
            ->get();

        foreach ($due as $asset) {
            $stats['processed']++;
            $userIds = $this->recipientsForAsset($asset, $at);

            if (empty($userIds)) {
                $stats['no_recipient']++;
                Log::info('AssetCheckDue: tidak ada penerima notifikasi.', [
                    'asset_id' => $asset->id,
                    'store_id' => $asset->store_id,
                ]);
                continue;
            }

            foreach ($userIds as $userId) {
                $stats['notified_users']++;
                $sent = $this->fcm->sendToUser($userId, [
                    'type' => 'asset_check_due',
                    'asset_id' => (string) $asset->id,
                    'asset_name' => $asset->name,
                    'store_id' => (string) $asset->store_id,
                    'due_at' => $asset->next_check_at?->toIso8601String(),
                    'title' => 'Pemeriksaan Aset Jatuh Tempo',
                    'body' => "Aset \"{$asset->name}\" perlu diperiksa hari ini.",
                ]);
                $stats['sent'] += $sent;
            }
        }

        Log::info('AssetCheckDue: selesai.', $stats);
        return $stats;
    }

    /**
     * Penerima notifikasi untuk satu aset (berbasis role+store). Mengembalikan
     * array user_id yang punya minimal satu device token terdaftar.
     */
    protected function recipientsForAsset(Asset $asset, Carbon $at): array
    {
        $roles = ['storage-staff', 'admin'];
        $storeId = (int) $asset->store_id;

        // (1) User ber-role storage-staff/admin yang check-in di store hari ini.
        $userIds = Presence::where('store_id', $storeId)
            ->whereDate('check_in', $at->toDateString())
            ->pluck('created_by_id')
            ->unique()
            ->all();
        $userIds = $this->filterByRoles($userIds, $roles);
        if (!empty($userIds)) return $this->withDevice($userIds);

        // (2) User ber-role tsb yang pernah check-in di store (30 hari terakhir).
        $userIds = Presence::where('store_id', $storeId)
            ->where('check_in', '>=', $at->copy()->subDays(30)->toDateString())
            ->pluck('created_by_id')
            ->unique()
            ->all();
        $userIds = $this->filterByRoles($userIds, $roles);
        if (!empty($userIds)) return $this->withDevice($userIds);

        // (3) Fallback terakhir: semua admin.
        $adminIds = User::role('admin')->pluck('id')->all();
        return $this->withDevice($adminIds);
    }

    /**
     * Saring daftar userId agar hanya yang ber-min-satu role dari $roles.
     */
    protected function filterByRoles(array $userIds, array $roles): array
    {
        if (empty($userIds)) return [];
        return User::whereIn('id', $userIds)
            ->role($roles)
            ->pluck('id')
            ->all();
    }

    /**
     * Saring daftar userId agar hanya yang punya minimal satu device token.
     */
    protected function withDevice(array $userIds): array
    {
        if (empty($userIds)) return [];
        return array_values(array_filter(
            $userIds,
            fn ($id) => $this->fcm->hasDevice((int) $id),
        ));
    }
}
