<?php

namespace App\Services;

/**
 * วาดการ์ด OG ขนาด 1200×630 ของ "การ์ดนับถอยหลัง" — ภาพที่ขึ้นเวลาแชร์ลิงก์
 * /s/{token} ลง LINE / Facebook / X
 *
 * เป็นคู่แฝดฝั่งเซิร์ฟเวอร์ของ TripStoryCard ในแอป (Dart) ใช้ภาษาภาพเดียวกัน
 * — รูปปกทริปเต็มกรอบ ไล่เฉดเข้มทับ ตัวเลขวันเป็นพระเอก — แต่คนละอัตราส่วน
 * โดยตั้งใจ: แอปทำ 9:16 ให้สตอรี่ ส่วนตัวนี้ทำ 1.91:1 ให้ลิงก์พรีวิว
 *
 * ใช้ GD ล้วน ๆ และฟอนต์ Noto Sans Thai ที่ผูกมากับ repo — ห้ามพึ่งฟอนต์ของ
 * ระบบ เพราะเซิร์ฟเวอร์ production ไม่มีฟอนต์ไทยติดตั้งไว้
 * (แพตเทิร์นเดียวกับ ProfileOgImageService)
 */
class TripCountdownImageService
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    /** ระยะขอบซ้าย/ขวาของเนื้อหาทั้งการ์ด. */
    private const PAD = 72;

    /**
     * @param  array{trip_title: string, location: string, date_label: ?string, days_left: ?int, cover_url: ?string}  $card
     */
    public function render(array $card): string
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        $this->paintBackground($canvas, $card['cover_url'] ?? null);
        $this->paintText($canvas, $card);

        ob_start();
        imagepng($canvas, null, 6);

        // ไม่เรียก imagedestroy() — เลิกใช้ตั้งแต่ PHP 8.5 และไม่มีผลตั้งแต่ 8.0
        // GdImage ถูกเก็บกวาดเองเมื่อหมด scope
        return (string) ob_get_clean();
    }

    /**
     * ตัวเลขนับถอยหลังแยกเป็น "ตัวเด่น" กับ "หน่วย" แบบเดียวกับ StoryCountdown
     * ในแอป ถ้อยคำต้องตรงกันทั้งสองฝั่ง ไม่งั้นคนที่เห็นการ์ดในแอปกับคนที่เห็น
     * ลิงก์พรีวิวจะอ่านได้คนละเรื่อง
     *
     * @return array{0: string, 1: ?string, 2: string} [ตัวเด่น, หน่วย, บรรทัดกำกับ]
     */
    public static function countdownParts(?int $daysLeft): array
    {
        return match (true) {
            $daysLeft === null => ['เร็ว ๆ นี้', null, 'ทริปต่อไป'],
            $daysLeft < 0 => ['กำลังลุย', null, 'ตอนนี้อยู่ที่'],
            $daysLeft === 0 => ['วันนี้!', null, 'ออกเดินทางแล้ว'],
            $daysLeft === 1 => ['พรุ่งนี้!', null, 'อีกไม่กี่ชั่วโมง'],
            default => [(string) $daysLeft, 'วัน', 'อีก'],
        };
    }

    /**
     * พื้นหลัง: รูปปกทริปครอปเต็มกรอบแบบ cover แล้วไล่เฉดเข้มทับเพื่อให้ตัวอักษร
     * ขาวอ่านออกเสมอ ไม่ว่ารูปต้นทางจะสว่างแค่ไหน.
     */
    private function paintBackground($canvas, ?string $coverUrl): void
    {
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 4, 35, 28));

        $photo = $this->loadPhoto($coverUrl);

        // ไม่มีรูปก็จบตรงนี้ — พื้นเขียวเข้มล้วนคอนทราสต์ดีอยู่แล้ว
        if (! $photo) {
            return;
        }

        $this->drawCover($canvas, $photo);

        // เข้มจากซ้ายไปขวา เพราะเนื้อหาทั้งหมดเกาะขอบซ้าย ทำให้รูปฝั่งขวายังพอ
        // เห็นวิวอยู่ ต่างจากการ์ด 9:16 ในแอปที่ไล่จากบนลงล่าง
        for ($x = 0; $x < self::WIDTH; $x++) {
            $ratio = $x / self::WIDTH;
            $opacity = max(0.18, 0.88 - 0.9 * $ratio);
            $shade = imagecolorallocatealpha($canvas, 4, 26, 22, (int) round(127 * (1 - $opacity)));
            imageline($canvas, $x, 0, $x, self::HEIGHT, $shade);
        }
    }

    /**
     * โหลดรูปจาก URL แบบไม่ให้พังทั้งการ์ด — รูปที่หายไปหรือโหลดไม่ได้แค่ทำให้
     * การ์ดเป็นพื้นสีเรียบแทน ซึ่งยังใช้งานได้อยู่.
     */
    private function loadPhoto(?string $url)
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

            return $image ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** วาดรูปให้เต็มกรอบแบบ object-fit: cover (ครอปส่วนเกิน ไม่บีบสัดส่วน). */
    private function drawCover($canvas, $photo): void
    {
        $srcW = imagesx($photo);
        $srcH = imagesy($photo);

        if ($srcW < 1 || $srcH < 1) {
            return;
        }

        $scale = max(self::WIDTH / $srcW, self::HEIGHT / $srcH);
        $cropW = (int) round(self::WIDTH / $scale);
        $cropH = (int) round(self::HEIGHT / $scale);

        imagecopyresampled(
            $canvas, $photo,
            0, 0,
            (int) round(($srcW - $cropW) / 2), (int) round(($srcH - $cropH) / 2),
            self::WIDTH, self::HEIGHT,
            $cropW, $cropH,
        );
    }

    /**
     * จัดวางจากล่างขึ้นบน โดยวัดความสูงจริงของแต่ละบรรทัดด้วย imagettfbbox
     *
     * ห้ามใช้พิกัดตายตัว — ความสูงของตัวอักษรไทยขึ้นกับว่าบรรทัดนั้นมีสระบน
     * วรรณยุกต์ หรือสระล่างหรือเปล่า และตัวเลขนับถอยหลังก็สูงไม่เท่ากันระหว่าง
     * "12" กับ "พรุ่งนี้!" การเดาระยะเองทำให้บรรทัดบนไปทับตัวเลขในบางกรณี
     */
    private function paintText($canvas, array $card): void
    {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 196, 214, 208);
        $brand = imagecolorallocate($canvas, 110, 231, 183);

        $inner = self::WIDTH - self::PAD * 2;

        [$headline, $unit, $kicker] = self::countdownParts($card['days_left'] ?? null);

        // แถบแบรนด์บนสุด — ห้ามจัดระยะห่างตัวอักษรเอง เพราะการวาดทีละตัวจะทำให้
        // สระและวรรณยุกต์ไทยหลุดจากพยัญชนะที่มันเกาะอยู่
        $this->text($canvas, 'ลุยเลเขา', 28, self::PAD, 96, $brand, bold: true);

        // เนื้อหาเกาะขอบล่าง เหมือนการ์ด 9:16 ในแอป แล้วไต่ขึ้นทีละบรรทัด
        // $y คือเส้นฐานของบรรทัดที่เพิ่งวาด, $atY คือตัวมันเองไว้วัดความสูง
        $y = self::HEIGHT - 54;
        $this->text($canvas, 'luilaykhao.com', 26, self::PAD, $y, $muted);
        $atY = ['luilaykhao.com', 26];

        $meta = implode('  ·  ', array_filter([
            trim((string) ($card['location'] ?? '')),
            $card['date_label'] ?? null,
        ]));

        if ($meta !== '') {
            $metaSize = $this->fit($meta, 30, $inner);
            $y = $this->above($y, $atY, 20);
            $this->text($canvas, $meta, $metaSize, self::PAD, $y, $muted);
            $atY = [$meta, $metaSize];
        }

        $title = trim((string) ($card['trip_title'] ?? ''));

        if ($title !== '') {
            $titleSize = $this->fit($title, 52, $inner);
            $y = $this->above($y, $atY, 24);
            $this->text($canvas, $title, $titleSize, self::PAD, $y, $white, bold: true);
            $atY = [$title, $titleSize];
        }

        // ตัวเลขคือพระเอก — คำ (วันนี้/พรุ่งนี้) เล็กกว่าตัวเลขเพราะยาวกว่ามาก
        $headlineSize = $unit === null ? 96 : 148;
        $y = $this->above($y, $atY, 46);
        $this->text($canvas, $headline, $headlineSize, self::PAD, $y, $white, bold: true);

        if ($unit !== null) {
            $unitX = self::PAD + $this->textWidth($headline, $headlineSize) + 20;
            $this->text($canvas, $unit, 44, $unitX, $y, $white);
        }

        $y = $this->above($y, [$headline, $headlineSize], 24);
        $this->text($canvas, $kicker, 30, self::PAD, $y, $brand);
    }

    /**
     * เส้นฐานของบรรทัดถัดไปที่จะวางเหนือบรรทัดซึ่งอยู่ที่ $baseline
     *
     * ลบด้วยความสูงของ *บรรทัดที่วาดไปแล้ว* (ส่งมาเป็น [ข้อความ, ขนาด]) เพราะ
     * นั่นคือระยะที่ต้องหลบ ไม่ใช่ความสูงของบรรทัดใหม่
     *
     * @param  array{0: string, 1: int}  $drawn
     */
    private function above(int $baseline, array $drawn, int $gap): int
    {
        return $baseline - $this->textHeight($drawn[0], $drawn[1]) - $gap;
    }

    private function textHeight(string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, $this->fontPath(), $text);

        return (int) abs($box[7] - $box[1]);
    }

    /**
     * วาดข้อความหนึ่งบรรทัด. GD ไม่มีน้ำหนักฟอนต์ให้เลือก (ไฟล์เดียวคือ regular)
     * จึงทำตัวหนาด้วยการวาดซ้ำเยื้องเล็กน้อย ซึ่งดูดีที่ขนาดใหญ่ ๆ.
     */
    private function text($canvas, string $text, int $size, int $x, int $y, int $color, bool $bold = false): void
    {
        $offsets = $bold ? [[0, 0], [1, 0], [2, 0], [1, 1]] : [[0, 0]];

        foreach ($offsets as [$dx, $dy]) {
            imagettftext($canvas, $size, 0, $x + $dx, $y + $dy, $color, $this->fontPath(), $text);
        }
    }

    /**
     * ขนาดฟอนต์ที่ใหญ่ที่สุดซึ่งข้อความยังกว้างไม่เกิน $maxWidth. ชื่อทริปยาวไม่
     * เท่ากัน การย่อขนาดจึงปลอดภัยกว่าการตัดคำทิ้ง — ไม่มีข้อมูลหายและไม่มีอะไร
     * ล้นขอบการ์ด.
     */
    private function fit(string $text, int $size, int $maxWidth): int
    {
        while ($size > 12 && $this->textWidth($text, $size) > $maxWidth) {
            $size--;
        }

        return $size;
    }

    private function textWidth(string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, $this->fontPath(), $text);

        return (int) abs($box[2] - $box[0]);
    }

    private function fontPath(): string
    {
        return resource_path('fonts/NotoSansThai.ttf');
    }
}
