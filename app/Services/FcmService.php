<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Throwable;

/**
 * Membungkus pengiriman Firebase Cloud Messaging (FCM) dari server ke device
 * pegawai.
 *
 * Dukung dua mode pengiriman berdasarkan jenis data:
 * - **Visible notification** (data punya key `title`): mengirim notification
 *   payload + data payload sehingga OS menampilkan banner saat app di background
 *   atau terminated. Android menggunakan AndroidConfig dengan priority high
 *   dan channel_id agar tampil heads-up.
 * - **Silent data-only** (data tanpa `title`, mis. `location_request`): hanya
 *   data payload untuk membangunkan background handler tanpa notifikasi visual.
 */
class FcmService
{
    const ANDROID_CHANNEL_ID = 'sagansa_notifications';

    /**
     * Kirim FCM ke satu device token. Bila `$data['title']` ada, OS menampilkan
     * banner (notification + data). Bila tidak ada, data-only (silent).
     *
     * @param  string  $token  FCM registration token pegawai.
     * @param  array<string,string>  $data  Payload data yang diterima background handler.
     * @return bool True bila terkirim sukses, false bila gagal.
     */
    public function sendDataToToken(string $token, array $data): bool
    {
        try {
            $message = $this->buildMessage($token, $data);

            $this->messaging()->send($message);

            return true;
        } catch (MessagingException $e) {
            // Token tidak valid / tidak terdaftar lagi → hapus agar tidak dipakai ulang.
            if ($this->isInvalidTokenError($e)) {
                DeviceToken::where('token', $token)->delete();
                Log::info('FCM token tidak valid, dihapus dari DB.', ['token_prefix' => substr($token, 0, 12)]);
            } else {
                Log::warning('FCM MessagingException saat kirim lokasi on-demand.', [
                    'message' => $e->getMessage(),
                    'token_prefix' => substr($token, 0, 12),
                ]);
            }

            return false;
        } catch (Throwable $e) {
            Log::warning('FCM gagal dikirim (umum).', [
                'message' => $e->getMessage(),
                'token_prefix' => substr($token, 0, 12),
            ]);

            return false;
        }
    }

    /**
     * Kirim message (visible/silent otomatis) ke semua device milik seorang
     * user. Berguna bila pegawai punya lebih dari satu device.
     *
     * @return int Jumlah device yang berhasil dikirimi.
     */
    public function sendToUser(int $userId, array $data): int
    {
        $tokens = DeviceToken::where('user_id', $userId)->pluck('token')->all();

        if (empty($tokens)) {
            return 0;
        }

        $sent = 0;
        foreach ($tokens as $token) {
            if ($this->sendDataToToken($token, $data)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Cek apakah user memiliki minimal satu device token terdaftar.
     */
    public function hasDevice(int $userId): bool
    {
        return DeviceToken::where('user_id', $userId)->exists();
    }

    /**
     * Bangun CloudMessage: notification+data bila title ada, data-only bila tidak.
     */
    protected function buildMessage(string $token, array $data): CloudMessage
    {
        $message = CloudMessage::new()
            ->withToken($token)
            ->withHighestPossiblePriority()
            ->withData($this->stringify($data));

        $title = $data['title'] ?? null;

        if (! empty($title)) {
            // Visible notification: OS menampilkan banner saat app bg/killed.
            $message = $message->withNotification([
                'title' => $data['title'],
                'body' => $data['body'] ?? '',
            ]);

            // AndroidConfig: priority high + channel_id agar tampil heads-up.
            // Wajib instance AndroidConfig — withAndroidConfig() menolak array.
            $message = $message->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => self::ANDROID_CHANNEL_ID,
                ],
            ]));
        }

        // Silent mode (location_request): data-only tanpa notification payload.

        return $message;
    }

    protected function messaging(): Messaging
    {
        return Firebase::messaging();
    }

    /**
     * FCM data payload hanya menerima nilai string.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    protected function stringify(array $data): array
    {
        return array_map(fn ($value) => (string) $value, $data);
    }

    /**
     * Mendeteksi error yang menandakan token tidak lagi valid.
     */
    protected function isInvalidTokenError(MessagingException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'requested-entity was not found')
            || str_contains($message, 'registration-token')
            || str_contains($message, 'UNREGISTERED')
            || str_contains($message, 'invalid-registration');
    }
}
