<?php

namespace App\Services;

class QrisService
{
    /**
     * Parse a QRIS payload string into structured data.
     */
    public function parsePayload(string $payload): array
    {
        $tags = $this->parseTags($payload);
        if (empty($tags)) {
            return [
                'valid' => false,
                'message' => 'QRIS payload tidak valid',
            ];
        }

        $nestedTags = array_map(function ($tag) {
            if (($tag['id'] >= '26' && $tag['id'] <= '51') || $tag['id'] === '62') {
                $children = $this->parseTags($tag['value']);
                $parsedLength = array_reduce($children, fn ($sum, $c) => $sum + 4 + $c['length'], 0);
                if ($parsedLength === strlen($tag['value'])) {
                    $tag['children'] = $children;
                }
            }
            return $tag;
        }, $tags);

        $merchantAccountInfo = array_values(array_filter(array_map(function ($tag) {
            if ($tag['id'] >= '26' && $tag['id'] <= '51' && isset($tag['children'])) {
                return [
                    'id' => $tag['id'],
                    'gui' => $this->tagValue($tag['children'], '00'),
                    'merchant_pan' => $this->tagValue($tag['children'], '01'),
                    'merchant_id' => $this->tagValue($tag['children'], '02') ?? $this->tagValue($tag['children'], '03'),
                    'criteria' => $this->tagValue($tag['children'], '03'),
                    'raw' => $tag['value'],
                ];
            }
            return null;
        }, $nestedTags)));

        $additionalTag = $this->findTag($nestedTags, '62');
        $additionalData = null;
        if ($additionalTag && isset($additionalTag['children'])) {
            $additionalData = [];
            $labelMap = [
                '01' => 'bill_number',
                '02' => 'mobile_number',
                '03' => 'store_label',
                '04' => 'loyalty_number',
                '05' => 'reference_label',
                '06' => 'customer_label',
                '07' => 'terminal_label',
                '08' => 'purpose',
            ];
            foreach ($additionalTag['children'] as $child) {
                if (isset($labelMap[$child['id']])) {
                    $additionalData[$labelMap[$child['id']]] = $child['value'];
                }
            }
        }

        $pointOfInitiation = $this->tagValue($nestedTags, '01');
        $amount = $this->tagValue($nestedTags, '54');

        return [
            'valid' => true,
            'raw_payload' => $payload,
            'point_of_initiation_method' => $pointOfInitiation,
            'point_of_initiation_label' => $pointOfInitiation === '11' ? 'Statis' : ($pointOfInitiation === '12' ? 'Dinamis' : 'Tidak diketahui'),
            'merchant_name' => $this->tagValue($nestedTags, '59'),
            'merchant_city' => $this->tagValue($nestedTags, '60'),
            'postal_code' => $this->tagValue($nestedTags, '61'),
            'country_code' => $this->tagValue($nestedTags, '58'),
            'currency' => $this->tagValue($nestedTags, '53') === '360' ? 'IDR' : $this->tagValue($nestedTags, '53'),
            'amount' => $amount !== null ? (float) $amount : null,
            'merchant_category_code' => $this->tagValue($nestedTags, '52'),
            'crc' => $this->tagValue($nestedTags, '63'),
            'merchant_account_info' => $merchantAccountInfo,
            'additional_data' => $additionalData,
            'tags' => $nestedTags,
        ];
    }

    /**
     * Generate dynamic QRIS payload by injecting amount into static QRIS.
     */
    public function generateDynamicPayload(string $staticPayload, int $amount): string
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('QRIS amount must be greater than zero.');
        }

        $tags = $this->parseTags($staticPayload);
        if (empty($tags)) {
            throw new \InvalidArgumentException('QRIS payload tidak valid');
        }

        $amountFormatted = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        $amountTag = '54' . str_pad((string) strlen($amountFormatted), 2, '0', STR_PAD_LEFT) . $amountFormatted;

        $result = '';
        $amountWritten = false;

        foreach ($tags as $tag) {
            if ($tag['id'] === '63') {
                continue;
            }

            if ($tag['id'] === '01') {
                $result .= '01' . '02' . '12';
                continue;
            }

            if ($tag['id'] === '54') {
                $result .= $amountTag;
                $amountWritten = true;
                continue;
            }

            if (!$amountWritten && strcmp($tag['id'], '54') > 0) {
                $result .= $amountTag;
                $amountWritten = true;
            }

            $result .= $tag['id'] . str_pad((string) $tag['length'], 2, '0', STR_PAD_LEFT) . $tag['value'];
        }

        if (!$amountWritten) {
            $result .= $amountTag;
        }

        $payloadForCrc = $result . '6304';
        $crc = $this->calculateCrc16($payloadForCrc);

        return $payloadForCrc . strtoupper(str_pad($crc, 4, '0', STR_PAD_LEFT));
    }

    /**
     * Calculate CRC-16/CCITT-FALSE for QRIS (poly 0x1021, MSB-first).
     */
    public function calculateCrc16(string $payload): string
    {
        $crc = 0xFFFF;
        $len = strlen($payload);

        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($payload[$i]) << 8;

            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc & 0xFFFF), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Validate QRIS payload CRC.
     */
    public function validatePayload(string $payload): bool
    {
        $tags = $this->parseTags($payload);
        if (empty($tags)) {
            return false;
        }

        $crcTag = $this->findTag($tags, '63');
        if (!$crcTag) {
            return false;
        }

        $expectedCrc = $crcTag['value'];
        $payloadWithoutCrc = substr($payload, 0, -4);

        $calculatedCrc = $this->calculateCrc16($payloadWithoutCrc);

        return strtoupper($expectedCrc) === strtoupper($calculatedCrc);
    }

    /**
     * Extract merchant NMID/MPAN from first merchant account info.
     */
    public function getMerchantNmid(array $parsedData): ?string
    {
        $info = $parsedData['merchant_account_info'][0] ?? null;
        if (!$info) {
            return null;
        }
        return $info['merchant_pan'] ?? $info['merchant_id'] ?? null;
    }

    private function parseTags(string $payload): array
    {
        $tags = [];
        $offset = 0;

        while ($offset < strlen($payload)) {
            $id = substr($payload, $offset, 2);
            $lengthText = substr($payload, $offset + 2, 2);

            if (!preg_match('/^\d{2}$/', $id) || !preg_match('/^\d{2}$/', $lengthText)) {
                break;
            }

            $length = (int) $lengthText;
            $valueStart = $offset + 4;
            $valueEnd = $valueStart + $length;

            if ($valueEnd > strlen($payload)) {
                break;
            }

            $tags[] = [
                'id' => $id,
                'length' => $length,
                'value' => substr($payload, $valueStart, $length),
            ];

            $offset = $valueEnd;
        }

        return $tags;
    }

    private function tagValue(array $tags, string $id): ?string
    {
        foreach ($tags as $tag) {
            if ($tag['id'] === $id) {
                return $tag['value'];
            }
        }
        return null;
    }

    private function findTag(array $tags, string $id): ?array
    {
        foreach ($tags as $tag) {
            if ($tag['id'] === $id) {
                return $tag;
            }
        }
        return null;
    }
}
