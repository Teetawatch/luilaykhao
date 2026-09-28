<?php

namespace App\Support;

/**
 * วาดข้อความไทยด้วย GD ให้วรรณยุกต์ไม่หาย
 *
 * GD บนเซิร์ฟเวอร์ไม่มี HarfBuzz (libraqm) จึงไม่จัดตำแหน่งเครื่องหมายบนตาม
 * ตาราง GPOS ของฟอนต์ ผลคือวรรณยุกต์ถูกวางที่ตำแหน่งตั้งต้นเสมอ แล้ว "หาย"
 * เข้าไปในตัวอื่นสองกรณี:
 *  - ตามหลังสระบน (ที่ ปี่ ชื่อ) — วรรณยุกต์ซ้อนทับสระพอดี มองไม่เห็น
 *  - อยู่บนพยัญชนะหางสูง (ป่ ฝ้ ฟ้ ฬ) — จมเข้าไปในหาง
 * ฟอนต์ Noto Sans Thai ไม่มี glyph ชุด PUA แบบเก่าให้สลับใช้แทนด้วย
 *
 * วิธีแก้: วาดทั้งบรรทัดโดยตัดเครื่องหมายที่ชนออกก่อน แล้ววาดเครื่องหมายเหล่านั้น
 * ทีละตัวเองที่ตำแหน่งปากกาจริง (วัดจาก imagettfbbox) ยกขึ้นเหนือสระ หรือขยับ
 * ซ้ายหลบหางพยัญชนะ
 */
class ThaiGdText
{
    /** พยัญชนะที่มีหางสูงเกินเส้นบน */
    private const TALL = ['ป', 'ฝ', 'ฟ', 'ฬ'];

    /** สระบน */
    private const UPPER_VOWELS = ["\u{0E31}", "\u{0E34}", "\u{0E35}", "\u{0E36}", "\u{0E37}", "\u{0E47}", "\u{0E4D}"];

    /** วรรณยุกต์ + ทัณฑฆาต */
    private const TONES = ["\u{0E48}", "\u{0E49}", "\u{0E4A}", "\u{0E4B}", "\u{0E4C}"];

    /** ตัวอ้างอิงที่ใช้วัดตำแหน่งปากกา — ไม่มีส่วนเกินซ้าย/ขวาแปลก ๆ */
    private const PROBE = 'ก';

    /**
     * วาดหนึ่งบรรทัด ($y คือเส้นฐาน เหมือน imagettftext)
     */
    public static function draw($canvas, float $size, int $x, int $y, int $color, string $font, string $text): void
    {
        [$plain, $marks] = self::split($text);

        imagettftext($canvas, $size, 0, $x, $y, $color, $font, $plain);

        foreach ($marks as [$prefix, $mark, $lift, $shift]) {
            $pen = $x + self::advance($size, $font, $prefix);

            imagettftext(
                $canvas,
                $size,
                0,
                (int) round($pen - $shift * $size),
                (int) round($y - $lift * $size),
                $color,
                $font,
                $mark,
            );
        }
    }

    /**
     * แยกเครื่องหมายที่ต้องวาดเองออกจากข้อความ
     *
     * @return array{0: string, 1: array<int, array{0: string, 1: string, 2: float, 3: float}>}
     *                                                                                          [ข้อความที่เหลือ, [[ข้อความก่อนหน้า, เครื่องหมาย, ยกขึ้น (เท่าของขนาด), ขยับซ้าย (เท่าของขนาด)]]]
     */
    public static function split(string $text): array
    {
        $chars = mb_str_split($text);
        $plain = '';
        $marks = [];
        $base = null;
        $afterUpperVowel = false;

        foreach ($chars as $char) {
            $isUpper = in_array($char, self::UPPER_VOWELS, true);
            $isTone = in_array($char, self::TONES, true);

            if (! $isUpper && ! $isTone) {
                $plain .= $char;
                $afterUpperVowel = false;
                // สระล่าง (ุ ู ฺ) ไม่ใช่ฐานใหม่ — เครื่องหมายบนที่ตามมายังเกาะพยัญชนะเดิม
                if (! in_array($char, ["\u{0E38}", "\u{0E39}", "\u{0E3A}"], true)) {
                    $base = $char;
                }

                continue;
            }

            $tall = in_array($base, self::TALL, true);
            $lift = $isTone && $afterUpperVowel ? 0.26 : 0.0;
            $shift = $tall ? 0.2 : 0.0;

            if ($lift === 0.0 && $shift === 0.0) {
                $plain .= $char;
            } else {
                $marks[] = [$plain, $char, $lift, $shift];
            }

            if ($isUpper) {
                $afterUpperVowel = true;
            }
        }

        return [$plain, $marks];
    }

    /**
     * ระยะที่ปากกาเดินไปหลังวาด $prefix — imagettfbbox ให้ขอบของหมึก ไม่ใช่
     * ระยะเดินของปากกา จึงวัดด้วยการต่อตัวอ้างอิงท้ายแล้วลบขอบขวาของตัวมันเองออก
     */
    private static function advance(float $size, string $font, string $prefix): int
    {
        if ($prefix === '') {
            return 0;
        }

        $with = imagettfbbox($size, 0, $font, $prefix.self::PROBE);
        $probe = imagettfbbox($size, 0, $font, self::PROBE);

        return $with[2] - $probe[2];
    }
}
