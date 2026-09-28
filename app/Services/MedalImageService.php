<?php

namespace App\Services;

use App\Support\MedalDesign;
use App\Support\MedalGeometry as G;
use App\Support\ThaiGdText;

/**
 * วาดภาพ OG 1200×630 ของเหรียญพิชิต — ภาพที่ขึ้นเวลาแชร์ลิงก์ /m/{token}
 * ลง LINE / Facebook / X
 *
 * ซ้ายเป็นเหรียญ ขวาเป็นข้อความ พื้นหลังเป็นสีเหรียญแบบเข้ม (สีเรียบ ไม่ไล่เฉด)
 * เหรียญแม่แบบวาดด้วย GD ล้วนตามรูปทรงใน MedalGeometry (ชุดเดียวกับเว็บและแอป)
 * ไอคอนใช้ฟอนต์ MedalIcons.otf ส่วนทริปที่มีภาพเหรียญออกแบบเองใช้ภาพนั้นทั้งดวง
 *
 * ใช้ฟอนต์ Noto Sans Thai ที่ผูกมากับ repo และจัดบรรทัดด้วยความสูงที่วัดจริง
 * จาก imagettfbbox เสมอ (ดู reference_gd_thai_card_layout) — ห้ามเดาพิกัด y
 */
class MedalImageService
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    private const MEDAL_CX = 330;

    private const TEXT_X = 620;

    private const TEXT_RIGHT = 1130;

    /**
     * @param  array<string, mixed>  $card  ผลจาก MedalService::publicCard()
     */
    public function render(array $card): string
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, true);

        [$r, $g, $b] = $this->rgb($card['design']['color'] ?? '#15803D');
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$this->shade([$r, $g, $b], 0.28)));

        $art = $this->loadImage($card['design']['image_url'] ?? null);

        if ($art) {
            $this->drawCustom($canvas, $art);
        } else {
            $this->drawTemplate($canvas, [$r, $g, $b], $card);
        }

        $this->drawText($canvas, $card);

        ob_start();
        imagepng($canvas, null, 6);

        return (string) ob_get_clean();
    }

    // ── เหรียญ ──────────────────────────────────────────────────────────────

    /**
     * เหรียญแม่แบบตามรูปทรงใน MedalGeometry (ผืน 100×118 หน่วย) ขยาย 4 เท่า
     * = เหรียญกว้าง 400 px วางกลางแนวตั้งของครึ่งซ้าย
     */
    private function drawTemplate($canvas, array $color, array $card): void
    {
        $k = 4.0;
        $ox = self::MEDAL_CX - G::CX * $k;
        $oy = (self::HEIGHT - G::HEIGHT * $k) / 2;
        $x = fn (float $u) => (int) round($ox + $u * $k);
        $y = fn (float $u) => (int) round($oy + $u * $k);
        $poly = function (array $xy) use ($x, $y): array {
            $out = [];
            foreach (array_chunk($xy, 2) as [$px, $py]) {
                $out[] = $x($px);
                $out[] = $y($py);
            }

            return $out;
        };
        $circle = fn (float $cx, float $cy, float $r, int $c) => imagefilledellipse(
            $canvas, $x($cx), $y($cy), (int) round($r * 2 * $k), (int) round($r * 2 * $k), $c,
        );

        // ริบบิ้นในภาพ OG ต่อขึ้นไปจนชนขอบบนของภาพ ไม่ให้ปลายลอยกลางอากาศ —
        // ยืดตามแนวเดิมของแต่ละเส้น (จุดบน p0/p1 คู่กับจุดล่าง p3/p2)
        $reach = function (array $xy) use ($oy, $k): array {
            $t = ($oy / $k) / G::RIBBON_JOIN;
            [$x0, $y0, $x1, $y1, $x2, $y2, $x3, $y3] = $xy;

            return [
                $x0 + ($x0 - $x3) * $t, $y0 + ($y0 - $y3) * $t,
                $x1 + ($x1 - $x2) * $t, $y1 + ($y1 - $y2) * $t,
                $x2, $y2, $x3, $y3,
            ];
        };
        $ribbon = G::ribbon();
        $band = imagecolorallocate($canvas, ...$this->shade($color, 0.75));
        $white = imagecolorallocate($canvas, 255, 255, 255);
        foreach ($ribbon['bands'] as $xy) {
            imagefilledpolygon($canvas, $poly($reach($xy)), $band);
        }
        foreach ($ribbon['stripes'] as $xy) {
            imagefilledpolygon($canvas, $poly($reach($xy)), $white);
        }

        $gold = imagecolorallocate($canvas, ...$this->rgb(G::GOLD));
        $circle(G::CX, G::CY, G::ROSETTE_R, $gold);
        foreach (G::scallops() as $p) {
            $circle($p['x'], $p['y'], G::SCALLOP_R, $gold);
        }
        $circle(G::CX, G::CY, G::BAND_R, imagecolorallocate($canvas, ...$this->rgb(G::GOLD_DEEP)));
        $circle(G::CX, G::CY, G::RIM_R, $gold);
        $disc = imagecolorallocate($canvas, ...$color);
        $circle(G::CX, G::CY, G::DISC_R, $disc);

        $year = ! empty($card['earned_on']) ? ((int) substr((string) $card['earned_on'], 0, 4)) + 543 : null;
        $this->ringText(
            $canvas,
            G::ringText($year),
            $x(G::CX),
            $y(G::CY),
            G::RING_TEXT_R * $k,
            (int) round(G::RING_TEXT_SIZE * $k * 0.75),
            imagecolorallocate($canvas, ...$this->rgb(G::RING_TEXT)),
        );

        $leaf = imagecolorallocate($canvas, ...$this->rgb(G::LAUREL));
        foreach (G::laurel() as $l) {
            imagefilledpolygon($canvas, $this->ellipse(
                $ox + $l['cx'] * $k, $oy + $l['cy'] * $k, $l['rx'] * $k, $l['ry'] * $k, deg2rad($l['deg']),
            ), $leaf);
        }

        // ไอคอนตัวเดียวกับในแอป — ฟอนต์ MedalIcons เรียงตาม MedalDesign::ICONS
        $iconIndex = array_search($card['design']['icon'] ?? '', array_keys(MedalDesign::ICONS), true);
        $glyph = mb_chr(0xE000 + ($iconIndex === false ? 1 : $iconIndex));
        $iconPt = (int) round(G::ICON_SIZE * $k * 0.75);
        $box = imagettfbbox($iconPt, 0, $this->iconFontPath(), $glyph);
        imagettftext(
            $canvas, $iconPt, 0,
            (int) round($x(G::CX) - ($box[2] + $box[0]) / 2),
            (int) round($y(G::ICON_Y) - ($box[7] + $box[1]) / 2),
            $white, $this->iconFontPath(), $glyph,
        );

        $this->discName($canvas, (string) ($card['design']['name'] ?? ''), $x, $y, $k, $white);

        imagefilledpolygon($canvas, $poly(G::banner()), imagecolorallocate($canvas, ...$this->rgb(G::BANNER)));
        $ink = imagecolorallocate($canvas, ...$this->shade($color, 0.6));
        $this->centeredText($canvas, 'FINISHER', (int) round(G::BANNER_TEXT_SIZE * $k * 0.75), $x(G::CX),
            $y(G::BANNER_TEXT_Y) + (int) round($this->capHeight((int) round(G::BANNER_TEXT_SIZE * $k * 0.75)) / 2), $ink, bold: true);
    }

    /**
     * ชื่อบนดวง — บรรทัดเดียวถ้าพอ ไม่งั้นแยกสองบรรทัดที่ช่องว่างใกล้กลางที่สุด
     * (GD ตัดคำไทยเองไม่ได้ จึงแยกเฉพาะที่ช่องว่างที่คนพิมพ์ไว้)
     */
    private function discName($canvas, string $name, \Closure $x, \Closure $y, float $k, int $color): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $box = G::NAME_BOX;
        $maxWidth = (int) round($box['w'] * $k);
        $cx = $x($box['x'] + $box['w'] / 2);
        $midY = $y($box['y'] + $box['h'] / 2);
        $maxPt = (int) round(G::NAME_SIZE * $k * 0.75);

        $single = $this->fit($name, $maxPt, $maxWidth);
        $lines = [$name];
        $size = $single;

        if ($single < $maxPt - 2 && str_contains($name, ' ')) {
            $words = explode(' ', $name);
            $best = null;
            for ($i = 1; $i < count($words); $i++) {
                $pair = [implode(' ', array_slice($words, 0, $i)), implode(' ', array_slice($words, $i))];
                $width = max($this->textWidth($pair[0], $maxPt), $this->textWidth($pair[1], $maxPt));
                if ($best === null || $width < $best[0]) {
                    $best = [$width, $pair];
                }
            }
            $lines = $best[1];
            $size = min($this->fit($lines[0], $maxPt, $maxWidth), $this->fit($lines[1], $maxPt, $maxWidth));
        }

        $lineHeight = (int) round($size * 1.333 * 1.3);
        $cap = $this->capHeight($size);
        $first = $midY - (int) round((count($lines) - 1) * $lineHeight / 2) + (int) round($cap / 2);

        foreach ($lines as $i => $line) {
            $this->centeredText($canvas, $line, $size, $cx, $first + $i * $lineHeight, $color, bold: true);
        }
    }

    /**
     * ตัวอักษรวิ่งรอบขอบบน วางทีละตัวให้เอียงตามวง (อังกฤษ/ตัวเลขเท่านั้น)
     */
    private function ringText($canvas, string $text, int $cx, int $cy, float $radius, int $pt, int $color): void
    {
        $chars = mb_str_split($text);
        $gap = $pt * 0.18;
        $widths = array_map(fn (string $c) => $c === ' ' ? $pt * 0.32 : $this->textWidth($c, $pt), $chars);
        $total = array_sum($widths) + $gap * (count($chars) - 1);
        $cap = $this->capHeight($pt);
        $angle = -M_PI / 2 - ($total / $radius) / 2;

        foreach ($chars as $i => $char) {
            $w = $widths[$i];
            $a = $angle + ($w / 2) / $radius;

            if ($char !== ' ') {
                // เส้นฐานอยู่ด้านในวงครึ่งความสูงตัวอักษร ตัวอักษรจึงคร่อมเส้นรัศมีพอดี
                $tx = -sin($a);
                $ty = cos($a);
                $nx = cos($a);
                $ny = sin($a);
                $px = $cx + $radius * $nx - $nx * ($cap / 2) - $tx * ($w / 2);
                $py = $cy + $radius * $ny - $ny * ($cap / 2) - $ty * ($w / 2);
                $deg = rad2deg(atan2(-$ty, $tx));
                imagettftext($canvas, $pt, $deg, (int) round($px), (int) round($py), $color, $this->fontPath(), $char);
            }

            $angle += ($w + $gap) / $radius;
        }
    }

    /** วงรีหมุนเป็นรูปหลายเหลี่ยม — GD วาดวงรีเอียงไม่ได้ @return array<int, int> */
    private function ellipse(float $cx, float $cy, float $rx, float $ry, float $rotation): array
    {
        $points = [];
        for ($i = 0; $i < 16; $i++) {
            $t = 2 * M_PI * $i / 16;
            $ex = $rx * cos($t);
            $ey = $ry * sin($t);
            $points[] = (int) round($cx + $ex * cos($rotation) - $ey * sin($rotation));
            $points[] = (int) round($cy + $ex * sin($rotation) + $ey * cos($rotation));
        }

        return $points;
    }

    private function capHeight(int $pt): int
    {
        $box = imagettfbbox($pt, 0, $this->fontPath(), 'H');

        return (int) abs($box[7] - $box[1]);
    }

    private function iconFontPath(): string
    {
        return resource_path('fonts/MedalIcons.otf');
    }

    private function drawCustom($canvas, $art): void
    {
        $srcW = imagesx($art);
        $srcH = imagesy($art);

        if ($srcW < 1 || $srcH < 1) {
            return;
        }

        $box = 480;
        $scale = min($box / $srcW, $box / $srcH);
        $w = (int) round($srcW * $scale);
        $h = (int) round($srcH * $scale);

        imagecopyresampled(
            $canvas, $art,
            (int) round(self::MEDAL_CX - $w / 2), (int) round(self::HEIGHT / 2 - $h / 2),
            0, 0,
            $w, $h,
            $srcW, $srcH,
        );
    }

    // ── ข้อความ ─────────────────────────────────────────────────────────────

    private function drawText($canvas, array $card): void
    {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocatealpha($canvas, 255, 255, 255, 38);
        $gold = imagecolorallocate($canvas, 247, 205, 120);
        $width = self::TEXT_RIGHT - self::TEXT_X;

        $name = (string) ($card['design']['name'] ?? '');
        $lines = array_values(array_filter([
            [(string) ($card['finisher_label'] ?? ''), 34, $gold, true, 18],
            [$name, $this->fit($name, 60, $width), $white, true, 26],
            [(string) ($card['holder_name'] ?? ''), $this->fit((string) ($card['holder_name'] ?? ''), 36, $width), $white, false, 14],
            [(string) ($card['date_label'] ?? ''), $this->fit((string) ($card['date_label'] ?? ''), 28, $width), $muted, false, 0],
        ], fn (array $line) => trim($line[0]) !== '' && $line[0] !== '-'));

        // วัดทั้งก้อนก่อน แล้วค่อยวางให้อยู่กลางแนวตั้ง
        $total = 0;
        foreach ($lines as [$text, $size, , , $gap]) {
            $total += $this->textHeight($text, $size) + $gap;
        }

        $top = (int) round((self::HEIGHT - $total) / 2) - 10;

        foreach ($lines as [$text, $size, $color, $bold, $gap]) {
            $top = $this->line($canvas, $text, $size, self::TEXT_X, $top, $color, $bold) + $gap;
        }

        $brand = imagecolorallocatealpha($canvas, 255, 255, 255, 50);
        $this->text($canvas, 'ลุยเลเขา · luilaykhao.com', 22, self::TEXT_X, self::HEIGHT - 44, $brand);
    }

    /** วาดหนึ่งบรรทัดโดยให้ "ขอบบน" ของตัวอักษรจริงอยู่ที่ $top คืนขอบล่างจริง */
    private function line($canvas, string $text, int $size, int $x, int $top, int $color, bool $bold): int
    {
        $box = imagettfbbox($size, 0, $this->fontPath(), $text);
        $baseline = $top - $box[7];
        $this->text($canvas, $text, $size, $x, $baseline, $color, $bold);

        return $baseline + $box[1];
    }

    private function centeredText($canvas, string $text, int $size, int $cx, int $baseline, int $color, bool $bold = false): void
    {
        $x = (int) round($cx - $this->textWidth($text, $size) / 2);
        $this->text($canvas, $text, $size, $x, $baseline, $color, $bold);
    }

    private function text($canvas, string $text, int $size, int $x, int $y, int $color, bool $bold = false): void
    {
        $offsets = $bold ? [[0, 0], [1, 0], [2, 0], [1, 1]] : [[0, 0]];

        foreach ($offsets as [$dx, $dy]) {
            // ไม่ใช่ imagettftext ตรง ๆ — วรรณยุกต์ใน "ที่"/"ป่า" จะหายไป ดู ThaiGdText
            ThaiGdText::draw($canvas, $size, $x + $dx, $y + $dy, $color, $this->fontPath(), $text);
        }
    }

    private function fit(string $text, int $size, int $maxWidth): int
    {
        while ($size > 14 && $this->textWidth($text, $size) > $maxWidth) {
            $size--;
        }

        return $size;
    }

    private function textWidth(string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, $this->fontPath(), $text);

        return (int) abs($box[2] - $box[0]);
    }

    private function textHeight(string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, $this->fontPath(), $text);

        return (int) abs($box[1] - $box[7]);
    }

    private function fontPath(): string
    {
        return resource_path('fonts/NotoSansThai.ttf');
    }

    // ── สี/รูป ──────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (! preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            $hex = '15803D';
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** คูณความสว่าง (0 = ดำ, 1 = สีเดิม) @return array{0: int, 1: int, 2: int} */
    private function shade(array $rgb, float $factor): array
    {
        return array_map(fn (int $c) => (int) max(0, min(255, round($c * $factor))), $rgb);
    }

    /** โหลดรูปแบบไม่ให้พังทั้งภาพ — โหลดไม่ได้ก็ถอยไปวาดเหรียญแม่แบบ */
    private function loadImage(?string $url)
    {
        if (! $url) {
            return null;
        }

        try {
            $bytes = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 5],
            ]));

            if ($bytes === false) {
                return null;
            }

            $image = @imagecreatefromstring($bytes);

            if ($image) {
                imagesavealpha($image, true);
            }

            return $image ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
