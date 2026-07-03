<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Throwable;

/**
 * Membungkus pengiriman Firebase Cloud Messaging (FCM) dari server ke device
 * pegawai, terutama untuk permintaan lokasi on-demand.
 *
 * Untuk membangunkan app di latar belakang tanpa menampilkan notifikasi yang
 * mencolok, kita mengirim **data-only message** dengan prioritas tinggi
 * (Android high priority). Device kemudian menjalankan background handler yang
 * mengambil GPS dan mengunggah lokasi balik ke server.
 */
class FcmService
{
    /**
     * Mengirim data-only message berprioritas tinggi ke satu device token.
     *
     * @param  string  $token  FCM registration token pegawai.
     * @param  array<string,string>  $data  Payload data yang diterima background handler.
     * @return bool True bila terkirim sukses, false bila gagal.
     */
    public function sendDataToToken(string $token, array $data): bool
    {
        try {
            $message = CloudMessage::new()
                ->withToken($token)
                ->withHighestPossiblePriority()
                ->withData($this->stringify($data));

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
     * Mengirim data-only message ke semua device milik seorang user. Berguna bila
     * pegawai punya lebih dari satu device.
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
