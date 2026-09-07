<?php

namespace Tests\Unit;

use App\Services\QrisService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QrisServiceTest extends TestCase
{
    private QrisService $service;

    /**
     * Fixture payload QRIS statis yang valid (CRC = 15C4, dihitung dengan
     * CRC-16/CCITT-FALSE). Tag 01 = 11 (statis).
     */
    private const STATIC_PAYLOAD = '00020101021126690014ID.CO.QRIS.WWW011893600915000000000202181500000000000000000303UMI5204541153033605802ID5912TOKO SAGANSA6007JAKARTA6105102106209070503A01630415C4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QrisService::class);
    }

    #[Test]
    public function validate_payload_accepts_valid_static_qris(): void
    {
        $this->assertTrue($this->service->validatePayload(self::STATIC_PAYLOAD));

        $parsed = $this->service->parsePayload(self::STATIC_PAYLOAD);
        $this->assertTrue($parsed['valid']);
        $this->assertSame('TOKO SAGANSA', $parsed['merchant_name']);
    }

    #[Test]
    public function generate_dynamic_payload_injects_amount_and_fixes_structure(): void
    {
        $dynamic = $this->service->generateDynamicPayload(self::STATIC_PAYLOAD, 150000);

        // Tag 01 dipaksa dinamis (12).
        $this->assertStringContainsString('010212', $dynamic);
        $this->assertStringNotContainsString('010211', $dynamic);

        // Tag 54 berisi nominal tanpa trailing nol desimal.
        $this->assertStringContainsString('5406150000', $dynamic);

        // Diakhiri 6304 + 4 hex CRC.
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $dynamic);

        // Hasil generate lolos validasi CRC.
        $this->assertTrue($this->service->validatePayload($dynamic));
    }

    #[Test]
    public function generate_dynamic_payload_rejects_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generateDynamicPayload(self::STATIC_PAYLOAD, 0);
    }

    #[Test]
    public function invalid_payloads_are_rejected(): void
    {
        $this->assertFalse($this->service->validatePayload('sampah-bukan-qris'));

        // CRC dirusak (4 char terakhir diganti).
        $tampered = substr(self::STATIC_PAYLOAD, 0, -4) . 'FFFF';
        $this->assertFalse($this->service->validatePayload($tampered));

        $this->expectException(InvalidArgumentException::class);
        $this->service->generateDynamicPayload('sampah-bukan-qris', 10000);
    }
}
