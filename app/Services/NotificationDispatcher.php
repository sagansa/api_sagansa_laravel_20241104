<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Inti bersama pengiriman notifikasi ke mobile Flutter (row tabel
 * `notifications` + FCM push), dipakai oleh ProcurementNotificationService,
 * SalesOrderNotificationService, dan service lain ke depannya.
 *
 * Pola:
 *   1. Resolve resipien via [userIdsByRoles] (role + pengecualian pembuat).
 *   2. [sendToUsers] menulis satu row `notifications` per resipien lalu
 *      mengirim FCM hanya ke yang punya device terdaftar.
 *
 * Semua pengiriman dibungkus try/catch + Log::warning sehingga kegagalan
 * tidak boleh menggagalkan request utama.
 */
class NotificationDispatcher
{
    public function __construct(protected FcmService $fcm)
    {
    }

    /**
     * ID user yang memegang minimal satu dari $roles, kecuali $exceptUserId.
     *
     * @param  array<int,string>  $roles
     * @return array<int>
     */
    public function userIdsByRoles(array $roles, ?int $exceptUserId = null): array
    {
        $candidateIds = User::role($roles)->pluck('id')->all();

        return array_values(array_filter(
            $candidateIds,
            fn ($id) => $exceptUserId === null || (int) $id !== $exceptUserId,
        ));
    }

    /**
     * Persist satu row `notifications` per resipien + kirim FCM hanya ke yang
     * punya device terdaftar. Per-resipien diisolasi try/catch + Log::warning.
     *
     * @param  array<int>  $recipientIds
     * @param  array<string,mixed>  $data  Field tambahan; ikut ke payload FCM
     *                                     (top-level) maupun kolom data row.
     * @return int Jumlah user yang berhasil dikirimi FCM.
     */
    public function sendToUsers(array $recipientIds, string $type, string $title, string $body, array $data = []): int
    {
        $this->persistForRecipients($recipientIds, $type, $title, $body, $data);

        // Kirim FCM hanya ke resipien yang punya device terdaftar. Isolasi
        // per-resipien: kegagalan hasDevice (mis. tabel device_tokens belum
        // ada) tidak boleh menggagalkan pengiriman ke user lain.
        $fcmRecipients = [];
        foreach ($recipientIds as $id) {
            try {
                if ($this->fcm->hasDevice((int) $id)) {
                    $fcmRecipients[] = (int) $id;
                }
            } catch (\Throwable $e) {
                Log::warning('NotificationDispatcher: gagal cek device.', [
                    'type' => $type,
                    'user_id' => $id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->sendToRecipients($fcmRecipients, [
            'type' => $type,
            'title' => $title,
            'body' => $body,
            ...$data,
        ]);
    }

    /**
     * Format angka menjadi "Rp 1.500.000".
     */
    public function formatRupiah(int|float $amount): string
    {
        return 'Rp ' . number_format((int) $amount, 0, ',', '.');
    }

    /**
     * Kirim payload ke daftar user, tahan kegagalan per-user.
     *
     * @param  array<int>  $recipientIds
     * @return int Jumlah user yang berhasil dikirimi.
     */
    protected function sendToRecipients(array $recipientIds, array $payload): int
    {
        $sent = 0;
        foreach ($recipientIds as $userId) {
            try {
                $sent += $this->fcm->sendToUser((int) $userId, $payload);
            } catch (\Throwable $e) {
                Log::warning('NotificationDispatcher: gagal kirim FCM.', [
                    'type' => $payload['type'] ?? null,
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Tulis satu row notifikasi ke tabel `notifications` per resipien.
     * Dibungkus try/catch terpisah — kegagalan insert tidak boleh
     * mengganggu pengiriman FCM maupun request utama.
     *
     * @param  array<int>  $recipientIds
     * @param  array<string,mixed>  $data
     */
    protected function persistForRecipients(array $recipientIds, string $type, string $title, string $body, array $data): void
    {
        foreach ($recipientIds as $userId) {
            try {
                Notification::create([
                    'user_id' => (int) $userId,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data ?: null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('NotificationDispatcher: gagal tulis row notifikasi.', [
                    'type' => $type,
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}