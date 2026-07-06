<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Memproses aset yang jatuh tempo pemeriksaan dan mengirim pengingat FCM ke
 * PIC (penanggung jawab) aset yang bersangkutan.
 *
 * Model akses berbasis PENUGASAN: notifikasi hanya dikirim ke:
 *   1. PIC aset (pic_user_id) — bila ada device terdaftar.
 *   2. Pembuat aset (created_by_id) — fallback bila PIC tidak punya device.
 *   3. Admin (semua) — fallback terakhir bila keduanya tidak punya device,
 *      agar tidak ada aset jatuh tempo tanpa pengingat.
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
            $userIds = $this->recipientsForAsset($asset);

            if (empty($userIds)) {
                $stats['no_recipient']++;
                Log::info('AssetCheckDue: tidak ada penerima (PIC/creator tanpa device).', [
                    'asset_id' => $asset->id,
                    'pic_user_id' => $asset->pic_user_id,
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
     * Penerima notifikasi untuk satu aset (berbasis penugasan). Mengembalikan
     * array user_id yang punya minimal satu device token terdaftar.
     *
     * Urutan prioritas: PIC → creator → admin (fallback).
     */
    protected function recipientsForAsset(Asset $asset): array
    {
        $candidates = array_filter([
            $asset->pic_user_id,
            $asset->created_by_id,
        ], fn ($id) => $id !== null && $id > 0);

        // Saring yang punya device token terdaftar.
        $withDevice = [];
        foreach ($candidates as $id) {
            if ($this->fcm->hasDevice((int) $id)) {
                $withDevice[] = (int) $id;
            }
        }
        if (!empty($withDevice)) {
            return $withDevice;
        }

        // Fallback: semua admin yang punya device.
        $adminIds = User::role('admin')->pluck('id')->all();
        return array_values(array_filter(
            $adminIds,
            fn ($id) => $this->fcm->hasDevice((int) $id),
        ));
    }
}
