<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * สร้าง EMVCo PromptPay payload และ QR code
 * ตรรกะตรงกับ _buildPromptPayPayload ในแอป Flutter เพื่อให้ QR ตรงกันทั้งสองฝั่ง
 */
class PromptPayService
{
    /**
     * สร้าง PromptPay payload string สำหรับยอดที่ต้องการชำระ
     */
    public function buildPayload(string $identifier, float $amount): string
    {
        $cleanId = preg_replace('/\D/', '', $identifier);
        $normalized = $cleanId;
        $typeTag = '03';

        if (strlen($cleanId) === 10 && str_starts_with($cleanId, '0')) {
            $normalized = '0066'.substr($cleanId, 1);
            $typeTag = '01';
        } elseif (strlen($cleanId) === 13) {
            $typeTag = '02';
        }

        $merchantAccountInfo = $this->tag('00', 'A000000677010111').$this->tag($typeTag, $normalized);

        $payload = $this->tag('00', '01')
            .$this->tag('01', '12')
            .$this->tag('29', $merchantAccountInfo)
            .$this->tag('53', '764')
            .$this->tag('54', number_format($amount, 2, '.', ''))
            .$this->tag('58', 'TH')
            .$this->tag('62', $this->tag('07', config('payment.merchant_name', 'LUILAYKHAO')))
            .'6304';

        return $payload.$this->crc16($payload);
    }

    /**
     * คืน QR code เป็น SVG string (pure PHP, ไม่ต้องใช้ GD)
     */
    public function qrSvg(string $payload, int $size = 280): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 0),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($payload);
    }

    /**
     * คืน QR code เป็น data URI ฝังตรงในหน้า HTML ได้
     */
    public function qrDataUri(string $payload, int $size = 280): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->qrSvg($payload, $size));
    }

    private function tag(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    private function crc16(string $value): string
    {
        $crc = 0xFFFF;
        foreach (str_split($value) as $char) {
            $crc ^= ord($char) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021)
                    : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return str_pad(strtoupper(dechex($crc & 0xFFFF)), 4, '0', STR_PAD_LEFT);
    }
}
