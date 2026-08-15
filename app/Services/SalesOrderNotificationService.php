<?php

namespace App\Services;

use App\Models\SalesOrderOnline;

/**
 * Mengirim notifikasi (row `notifications` + FCM push) terkait sales order
 * online ke aplikasi mobile Flutter.
 *
 * Saat admin membuat penjualan online (POST /sales-orders/online), semua user
 * ber-role storage-staff (kecuali pembuat) diberi tahu bahwa ada order yang
 * menunggu diproses.
 */
class SalesOrderNotificationService
{
    public function __construct(protected NotificationDispatcher $dispatcher)
    {
    }

    /**
     * Push "Penjualan Online Baru" ke semua storage-staff (kecuali pembuat).
     *
     * @return int Jumlah user yang berhasil dikirimi.
     */
    public function notifyOnlineSalesOrderCreated(SalesOrderOnline $order, int $creatorId): int
    {
        $recipientIds = $this->dispatcher->userIdsByRoles(['storage-staff'], $creatorId);

        if (empty($recipientIds)) {
            return 0;
        }

        $providerName = $order->onlineShopProvider?->name ?? 'Toko Online';
        $amount = $this->dispatcher->formatRupiah($order->total_price ?? 0);

        $body = "Order online {$order->receipt_no} dari {$providerName} sejumlah {$amount} menunggu diproses.";

        return $this->dispatcher->sendToUsers(
            $recipientIds,
            'sales_order_online_created',
            'Penjualan Online Baru',
            $body,
            [
                'sales_order_id' => (string) $order->id,
                'receipt_no' => $order->receipt_no,
            ],
        );
    }
}