<?php

namespace App\Services;

/**
 * วาดการ์ด OG ขนาด 1200×630 ของโปรไฟล์นักเดินทาง — ภาพที่ขึ้นเวลาแชร์ลิงก์
 * /u/{handle} ลง LINE / Facebook / X
 *
 * ใช้ GD ล้วน ๆ (ไม่มี dependency เพิ่ม) และฟอนต์ Noto Sans Thai ที่ผูกมากับ repo
 * — ห้ามพึ่งฟอนต์ของระบบ เพราะเซิร์ฟเวอร์ production ไม่มีฟอนต์ไทยติดตั้งไว้
 */
class ProfileOgImageService
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    /** ระยะขอบซ้าย/ขวาของเนื้อหาทั้งการ์ด. */
    private const PAD = 72;

    /**
     * สร้าง PNG จากข้อมูลโปรไฟล์ที่ PublicProfileService คืนมา.
     */
    public function render(array $profile): string
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        $this->paintBackground($canvas, $profile['photos'][0]['url'] ?? null);
        $this->paintText($canvas, $profile);

        ob_start();
        imagepng($canvas, null, 6);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }

    /**
     * พื้นหลัง: รูปจริงจากทริปของเจ้าตัวถ้ามี (ครอปเต็มกรอบแบบ cover) แล้วไล่เฉด
     * เข้มทับเพื่อให้ตัวอักษรอ่านออกเสมอ ไม่ว่ารูปต้นทางจะสว่างแค่ไหน.
     */
    private function paintBackground($canvas, ?string $photoUrl): void
    {
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 11, 31, 28));

        $photo = $this->loadPhoto($photoUrl);

        // ไม่มีรูปก็จบตรงนี้ — พื้นเขียวเข้มล้วนคอนทราสต์ดีอยู่แล้ว ไม่ต้องเฉดทับ
        if (! $photo) {
            return;
        }

        $this->drawCover($canvas, $photo);
        imagedestroy($photo);

        // ไล่เฉดเข้มทับรูป — ต้องทึบพอที่ตัวอักษรขาวจะอ่านออกแม้รูปต้นทางจะเป็น
        // ท้องฟ้าสว่างจ้า จึงเริ่มที่ 55% ด้านบนแล้วไล่ไปเกือบทึบด้านล่าง
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $ratio = $y / self::HEIGHT;
            $opacity = 0.55 + 0.35 * $ratio;
            $shade = imagecolorallocatealpha($canvas, 6, 24, 21, (int) round(127 * (1 - $opacity)));
            imageline($canvas, 0, $y, self::WIDTH, $y, $shade);
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

    private function paintText($canvas, array $profile): void
    {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 189, 205, 200);
        $gold = imagecolorallocate($canvas, 217, 164, 65);

        $stats = $profile['stats'];
        $inner = self::WIDTH - self::PAD * 2;

        // แถบแบรนด์บนสุด — ห้ามจัดระยะห่างตัวอักษรเอง เพราะการวาดทีละตัวจะทำให้
        // สระและวรรณยุกต์ไทยหลุดจากพยัญชนะที่มันเกาะอยู่
        $this->text($canvas, 'ลุยเลเขา · สมุดสะสมการเดินทาง', 26, self::PAD, 92, $gold);

        // ชื่อ — ตัวใหญ่สุดของการ์ด ย่อลงเองถ้าชื่อยาวจนล้นขอบ
        $this->text($canvas, $profile['name'], $this->fit($profile['name'], 66, $inner), self::PAD, 186, $white, bold: true);

        if ($profile['bio']) {
            $this->text($canvas, $profile['bio'], $this->fit($profile['bio'], 28, $inner), self::PAD, 236, $muted);
        }

        // แถวตัวเลข — สามช่องเท่า ๆ กัน
        $columns = [
            [$this->number($stats['trips_count']), 'ทริปที่เดินจบ'],
            [$this->number($stats['total_distance_km']).' กม.', 'ระยะทางสะสม'],
            [$this->number($stats['total_elevation_gain_m']).' ม.', 'ความสูงสะสม'],
        ];

        $columnWidth = (int) ($inner / count($columns));

        foreach ($columns as $index => [$value, $label]) {
            $x = self::PAD + $index * $columnWidth;
            // เว้นช่องไฟระหว่างคอลัมน์ไว้ 44px กันตัวเลขยาว ๆ ชนคอลัมน์ถัดไป
            $this->text($canvas, $value, $this->fit($value, 54, $columnWidth - 44), $x, 424, $white, bold: true);
            $this->text($canvas, $label, 24, $x, 466, $muted);
        }

        // บรรทัดล่าง: ตราที่ปลดล็อกได้ + ลิงก์โปรไฟล์
        $badges = collect($profile['badges'])->take(3)->pluck('title')->implode(' · ');

        if ($badges !== '') {
            $this->text($canvas, $badges, $this->fit($badges, 28, $inner), self::PAD, 546, $gold);
        }

        $this->text($canvas, 'luilaykhao.com/u/'.$profile['handle'], 24, self::PAD, 592, $muted);
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
     * ขนาดฟอนต์ที่ใหญ่ที่สุดซึ่งข้อความยังกว้างไม่เกิน $maxWidth. ชื่อคนและตัวเลข
     * สะสมยาวไม่เท่ากัน การย่อขนาดจึงปลอดภัยกว่าการตัดคำทิ้ง — ไม่มีข้อมูลหาย
     * และไม่มีอะไรล้นขอบการ์ด.
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

    /** ตัวเลขแบบมีคอมมา และตัดทศนิยม .0 ที่ไม่ได้ให้ข้อมูลอะไรทิ้ง. */
    private function number(float|int $value): string
    {
        return number_format($value, fmod((float) $value, 1) == 0.0 ? 0 : 1);
    }
}
