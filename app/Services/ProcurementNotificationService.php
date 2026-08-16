<?php

namespace App\Services;

use App\Models\InvoicePurchase;
use App\Models\PaymentReceipt;

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
 * Logika generik (resolve resipien, persist row `notifications`, kirim FCM)
 * didelegasikan ke [NotificationDispatcher].
 */
class ProcurementNotificationService
{
    public function __construct(protected NotificationDispatcher $dispatcher)
    {
    }

    /**
     * Push "Invoice Transfer Baru" ke admin/super_admin (kecuali pembuat).
     *
     * @return int Jumlah user yang berhasil dikirimi.
     */
    public function notifyInvoiceTransferCreated(InvoicePurchase $invoice, int $creatorId): int
    {
        $recipientIds = $this->dispatcher->userIdsByRoles(['admin', 'super_admin'], $creatorId);

        if (empty($recipientIds)) {
            return 0;
        }

        $supplierName = $invoice->supplier?->name;
        $amount = $this->dispatcher->formatRupiah($invoice->total_price ?? 0);

        $body = "Invoice transfer #{$invoice->id} sejumlah {$amount}";
        $body .= $supplierName ? " dari {$supplierName} menunggu pembayaran." : ' menunggu pembayaran.';

        return $this->dispatcher->sendToUsers(
            $recipientIds,
            'invoice_transfer_created',
            'Invoice Transfer Baru',
            $body,
            [
                'invoice_id' => (string) $invoice->id,
                'total_price' => (string) ($invoice->total_price ?? 0),
            ],
        );
    }

    /**
     * Push "Pembayaran Telah Dilakukan" ke created_by tiap record ter-attach
     * (kecuali pembayar). payment_for: 1=FuelService, 2=DailySalary, 3=Invoice.
     *
     * @param  int|string|null  $payerId  User yang melakukan pembayaran
     *   (dikecualikan dari penerima). Jika null, default ke $receipt->user_id
     *   (backward-compatible dengan caller lama).
     * @return int Jumlah user yang berhasil dikirimi.
     */
    public function notifyPaymentReceiptPaid(PaymentReceipt $receipt, int|string|null $payerId = null): int
    {
        $creatorIds = $this->paidRecordCreators($receipt);

        // Hapus pembayar sendiri & nilai non-numeric (created_by bisa uuid).
        $payerId ??= $receipt->user_id;
        $recipientIds = array_values(array_filter(
            array_unique($creatorIds),
            fn ($id) => is_numeric($id) && (int) $id !== (int) $payerId,
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

        $amount = $this->dispatcher->formatRupiah($receipt->transfer_amount ?? $receipt->total_amount ?? 0);

        $body = "Pembayaran {$label} via transfer sejumlah {$amount} telah dibayar.";

        return $this->dispatcher->sendToUsers(
            $recipientIds,
            'payment_receipt_paid',
            'Pembayaran Telah Dilakukan',
            $body,
            [
                'receipt_id' => (string) $receipt->id,
                'payment_for' => (string) $receipt->payment_for,
                'transfer_amount' => (string) ($receipt->transfer_amount ?? 0),
            ],
        );
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
}