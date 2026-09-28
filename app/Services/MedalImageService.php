<?php

namespace App\Services;

use App\Support\ThaiGdText;

/**
 * วาดภาพ OG 1200×630 ของเหรียญพิชิต — ภาพที่ขึ้นเวลาแชร์ลิงก์ /m/{token}
 * ลง LINE / Facebook / X
 *
 * ซ้ายเป็นเหรียญ ขวาเป็นข้อความ พื้นหลังเป็นสีเหรียญแบบเข้ม (สีเรียบ ไม่ไล่เฉด)
 * เหรียญแม่แบบวาดด้วย GD ล้วน (ริบบิ้น + ขอบทอง + ดวงสี + ภูเขา) ส่วนทริปที่มี
 * ภาพเหรียญออกแบบเอง ใช้ภาพนั้นทั้งดวง
 *
 * ใช้ฟอนต์ Noto Sans Thai ที่ผูกมากับ repo และจัดบรรทัดด้วยความสูงที่วัดจริง
 * จาก imagettfbbox เสมอ (ดู reference_gd_thai_card_layout) — ห้ามเดาพิกัด y
 */
class MedalImageService
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    private const MEDAL_CX = 330;

    private const MEDAL_CY = 345;

    private const MEDAL_R = 200;

    private const TEXT_X = 620;

    private const TEXT_RIGHT = 1130;

    /** ทองของขอบเหรียญ — ตรงกับ kMedalGold ในแอป */
    private const GOLD = [217, 164, 65];

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
            $this->drawTemplate($canvas, [$r, $g, $b]);
        }

        $this->drawText($canvas, $card);

        ob_start();
        imagepng($canvas, null, 6);

        return (string) ob_get_clean();
    }

    // ── เหรียญ ──────────────────────────────────────────────────────────────

    private function drawTemplate($canvas, array $color): void
    {
        $cx = self::MEDAL_CX;
        $cy = self::MEDAL_CY;
        $r = self::MEDAL_R;

        $ribbon = imagecolorallocate($canvas, ...$this->shade($color, 0.75));
        $stripe = imagecolorallocate($canvas, 255, 255, 255);
        $gold = imagecolorallocate($canvas, ...self::GOLD);
        $disc = imagecolorallocate($canvas, ...$color);
        $ring = imagecolorallocatealpha($canvas, 255, 255, 255, 80);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        // ริบบิ้นสองเส้นไขว้เป็นรูปตัว V จากขอบบนของภาพลงมาหาเหรียญ ปลายล่าง
        // จมอยู่ใต้ขอบทองจึงไม่ต้องตัดให้พอดี
        $join = $cy - $r + 30;
        foreach ([-1, 1] as $side) {
            imagefilledpolygon($canvas, [
                $cx + $side * 200, 0,
                $cx + $side * 110, 0,
                $cx - $side * 15, $join,
                $cx + $side * 75, $join,
            ], $ribbon);
        }
        foreach ([-1, 1] as $side) {
            imagefilledpolygon($canvas, [
                $cx + $side * 163, 0,
                $cx + $side * 147, 0,
                $cx + $side * 22, $join,
                $cx + $side * 38, $join,
            ], $stripe);
        }

        imagefilledellipse($canvas, $cx, $cy, $r * 2, $r * 2, $gold);
        imagefilledellipse($canvas, $cx, $cy, ($r - 24) * 2, ($r - 24) * 2, $disc);
        imagesetthickness($canvas, 4);
        imageellipse($canvas, $cx, $cy, ($r - 40) * 2, ($r - 40) * 2, $ring);
        imagesetthickness($canvas, 1);

        // ภูเขาสองลูก — ตัวแทนไอคอนของเหรียญแม่แบบ (GD ไม่มีฟอนต์ไอคอน)
        $base = $cy + 55;
        imagefilledpolygon($canvas, [
            $cx - 105, $base,
            $cx - 20, $cy - 75,
            $cx + 40, $base,
        ], $white);
        imagefilledpolygon($canvas, [
            $cx - 10, $base,
            $cx + 50, $cy - 25,
            $cx + 110, $base,
        ], imagecolorallocatealpha($canvas, 255, 255, 255, 40));

        $this->centeredText($canvas, 'FINISHER', 24, $cx, $base + 58, $white, bold: true);
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
