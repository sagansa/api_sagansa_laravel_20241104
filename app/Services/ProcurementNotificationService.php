<?php

namespace App\Services;

use App\Models\InvoicePurchase;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim notifikasi FCM push terkait procurement ke aplikasi mobile Flutter.
 *
 * Dua jenis notifikasi:
 *   1. Invoice Transfer dibuat (payment_type_id == 1) → ke semua user
 *      ber-role admin/super_admin (kecuali pembuat invoice).
 *   2. Payment Receipt dibuat → ke user created_by_id dari tiap record yang
 *      dibayar (invoice transfer / daily salary / fuel & service), kecuali
 *      pembayar sendiri.
 *
 * Semua pengiriman dibungkus try/catch + Log::warning sehingga kegagalan FCM
 * tidak boleh menggagalkan request utama (pembayaran/invoice).
 */
class ProcurementNotificationService
{
    public function __construct(protected FcmService $fcm)
    {
    }

    /**
     * Push "Invoice Transfer Baru" ke admin/super_admin (kecuali pembuat).
     *
     * @return int Jumlah user yang berhasil dikirimi.
     */
    public function notifyInvoiceTransferCreated(InvoicePurchase $invoice, int $creatorId): int
    {
        // Kandidat: semua admin/super_admin yang punya device token terdaftar.
        $candidateIds = User::role(['admin', 'super_admin'])
            ->pluck('id')
            ->all();

        $recipientIds = array_values(array_filter(
            $candidateIds,
            fn ($id) => (int) $id !== $creatorId && $this->fcm->hasDevice((int) $id),
        ));

        if (empty($recipientIds)) {
            return 0;
        }

        $supplierName = $invoice->supplier?->name;
        $amount = $this->formatRupiah($invoice->total_price ?? 0);

        $body = "Invoice transfer #{$invoice->id} sejumlah {$amount}";
        $body .= $supplierName ? " dari {$supplierName} menunggu pembayaran." : ' menunggu pembayaran.';

        $payload = [
            'type' => 'invoice_transfer_created',
            'invoice_id' => (string) $invoice->id,
            'total_price' => (string) ($invoice->total_price ?? 0),
            'title' => 'Invoice Transfer Baru',
            'body' => $body,
        ];

        return $this->sendToRecipients($recipientIds, $payload);
    }

    /**
     * Push "Pembayaran Telah Dilakukan" ke created_by tiap record ter-attach
     * (kecuali pembayar). payment_for: 1=FuelService, 2=DailySalary, 3=Invoice.
     *
     * @return int Jumlah user yang berhasil dikirimi.
     */
    public function notifyPaymentReceiptPaid(PaymentReceipt $receipt): int
    {
        $creatorIds = $this->paidRecordCreators($receipt);

        // Hapus pembayar sendiri & nilai non-numeric (created_by bisa uuid).
        $payerId = $receipt->user_id;
        $recipientIds = array_values(array_filter(
            array_unique($creatorIds),
            fn ($id) => is_numeric($id) && (int) $id !== (int) $payerId && $this->fcm->hasDevice((int) $id),
        ));

        if (empty($recipientIds)) {
            return 0;
        }

        $label = match ((string) $receipt->payment_for) {
            '1' => 'Bensin & Servis',
            '2' => 'Gaji Harian',
            '3' => 'Invoice Transfer',
            default => 'Procurement',
        };

        $amount = $this->formatRupiah($receipt->transfer_amount ?? $receipt->total_amount ?? 0);

        $payload = [
            'type' => 'payment_receipt_paid',
            'payment_for' => (string) $receipt->payment_for,
            'receipt_id' => (string) $receipt->id,
            'transfer_amount' => (string) ($receipt->transfer_amount ?? 0),
            'title' => 'Pembayaran Telah Dilakukan',
            'body' => "Pembayaran {$label} via transfer sejumlah {$amount} telah dibayar.",
        ];

        return $this->sendToRecipients($recipientIds, $payload);
    }

    /**
     * Distinct created_by_id dari relasi ter-attach berdasarkan payment_for.
     *
     * @return array<int|string>
     */
    protected function paidRecordCreators(PaymentReceipt $receipt): array
    {
        $for = (string) $receipt->payment_for;

        if ($for === '3') {
            return $receipt->invoicePurchases()->pluck('created_by_id')->all();
        }
        if ($for === '2') {
            return $receipt->dailySalaries()->pluck('created_by_id')->all();
        }
        if ($for === '1') {
            return $receipt->fuelServices()->pluck('created_by_id')->all();
        }

        return [];
    }

    /**
     * Kirim payload ke daftar user, tahan kegagalan per-user.
     *
     * @return int Jumlah user yang berhasil dikirimi.
     */
    protected function sendToRecipients(array $recipientIds, array $payload): int
    {
        $sent = 0;
        foreach ($recipientIds as $userId) {
            try {
                $sent += $this->fcm->sendToUser((int) $userId, $payload);
            } catch (\Throwable $e) {
                Log::warning('ProcurementNotification: gagal kirim FCM.', [
                    'type' => $payload['type'] ?? null,
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Format angka menjadi "Rp 1.500.000".
     */
    protected function formatRupiah(int|float $amount): string
    {
        return 'Rp ' . number_format((int) $amount, 0, ',', '.');
    }
}
