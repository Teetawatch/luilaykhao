<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCodeService
{
    /** คืน SVG ของ QR (pure PHP, ไม่ต้องพึ่ง imagick/gd) */
    public function svg(string $text, int $size = 300): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($text);
    }

    /** data: URI ของ SVG — ฝังใน <img> ได้ทั้งใน dompdf และหน้าเว็บ */
    public function svgDataUri(string $text, int $size = 300): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($text, $size));
    }
}
