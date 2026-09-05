<?php

namespace Tests\Unit;

use App\Services\FcmService;
use Kreait\Firebase\Messaging\CloudMessage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Menguji bentuk pesan yang dibangun FcmService::buildMessage():
 * - payload dengan `title` harus membawa notification payload + Android
 *   channel_id (visible) sehingga OS menampilkan banner saat app bg/killed;
 * - payload tanpa `title` (location_request) harus tetap data-only (silent).
 *
 * buildMessage() tidak menyentuh Firebase facade, jadi bisa diuji murni.
 */
class FcmServiceBuildMessageTest extends TestCase
{
    private function build(array $data): array
    {
        $method = new ReflectionMethod(FcmService::class, 'buildMessage');
        $method->setAccessible(true);

        /** @var CloudMessage $message */
        $message = $method->invoke(new FcmService, 'test-token', $data);

        return $message->jsonSerialize();
    }

    public function test_payload_dengan_title_membawa_notification_dan_channel_android(): void
    {
        $payload = $this->build([
            'type' => 'invoice_transfer_created',
            'invoice_id' => '123',
            'title' => 'Invoice Transfer Baru',
            'body' => 'Ada invoice transfer menunggu verifikasi.',
        ]);

        $this->assertSame('Invoice Transfer Baru', $payload['notification']['title']);
        $this->assertSame('Ada invoice transfer menunggu verifikasi.', $payload['notification']['body']);
        $this->assertSame(
            FcmService::ANDROID_CHANNEL_ID,
            $payload['android']['notification']['channel_id'],
        );
        $this->assertSame(
            'high',
            strtolower((string) ($payload['android']['priority'] ?? '')),
        );
        // data payload tetap utuh untuk routing deep-link di mobile.
        $this->assertSame('invoice_transfer_created', $payload['data']['type']);
        $this->assertSame('123', $payload['data']['invoice_id']);
    }

    public function test_payload_tanpa_title_tetap_data_only_silent(): void
    {
        $payload = $this->build([
            'type' => 'location_request',
            'request_id' => 'abc-123',
            'requested_at' => '2026-08-27T00:00:00Z',
        ]);

        $this->assertArrayNotHasKey('notification', $payload);
        // Priority high dari withHighestPossiblePriority() memang menempel di
        // pesan silent (delivery immediate) — yang tidak boleh ada hanyalah
        // notification/channel agar tidak tampil sebagai banner.
        $this->assertArrayNotHasKey('notification', $payload['android'] ?? []);
        $this->assertSame('location_request', $payload['data']['type']);
        $this->assertSame('abc-123', $payload['data']['request_id']);
    }
}
