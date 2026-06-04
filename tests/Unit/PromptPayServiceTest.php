<?php

namespace Tests\Unit;

use App\Services\PromptPayService;
use Tests\TestCase;

class PromptPayServiceTest extends TestCase
{
    private function crc16(string $value): string
    {
        $crc = 0xFFFF;
        foreach (str_split($value) as $char) {
            $crc ^= ord($char) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return str_pad(strtoupper(dechex($crc & 0xFFFF)), 4, '0', STR_PAD_LEFT);
    }

    public function test_payload_has_emvco_structure_and_amount(): void
    {
        $payload = (new PromptPayService)->buildPayload('004999239362071', 1500.50);

        $this->assertStringStartsWith('000201', $payload);   // payload format indicator
        $this->assertStringContainsString('5802TH', $payload); // country code
        $this->assertStringContainsString('5303764', $payload); // currency THB
        $this->assertStringContainsString('54071500.50', $payload); // amount tag 54, len 07
    }

    public function test_payload_crc_is_valid(): void
    {
        $payload = (new PromptPayService)->buildPayload('004999239362071', 1000);

        $body = substr($payload, 0, -4);
        $crc = substr($payload, -4);

        $this->assertStringEndsWith('6304', $body); // CRC tag id + length precedes checksum
        $this->assertSame($this->crc16($body), $crc);
    }

    public function test_mobile_number_uses_0066_prefix(): void
    {
        $payload = (new PromptPayService)->buildPayload('0812345678', 500);

        // เบอร์ 10 หลักขึ้นต้น 0 → normalize เป็น 0066 + 9 หลัก ภายใต้ tag 01
        $this->assertStringContainsString('01130066812345678', $payload);
    }
}
