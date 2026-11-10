<?php

namespace App\Support;

/**
 * จุดแวะในแต่ละวันของ Trip::itinerary
 *
 * โครงของแผนการเดินทางคือ ภาค → วัน → จุดแวะ:
 *   [{sector, items: [{day, title, description, stops: [{time, title, detail, images}]}]}]
 *
 * `stops` เพิ่มเข้ามาทีหลัง ทริปเก่าไม่มีคีย์นี้เลย และวันหนึ่งจะมีแค่ย่อหน้าบรรยาย
 * ก็ได้ ทุกที่ที่อ่านแผนจึงอ่านผ่าน stops() ตัวเดียว ซึ่งยอมรับข้อมูลเพี้ยนได้
 * (คีย์หาย, เวลาเป็น "8.30", รูปเป็นค่าว่าง) แล้วคืนรูปแบบเดียวเสมอ
 */
final class TripItinerary
{
    public const MAX_STOPS = 30;

    public const MAX_IMAGES = 6;

    /**
     * @return array<int, array{time: ?string, title: string, detail: ?string, images: array<int, string>}>
     */
    public static function stops(mixed $day): array
    {
        if (! is_array($day) || ! is_array($day['stops'] ?? null)) {
            return [];
        }

        $out = [];

        foreach ($day['stops'] as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $title = self::text($stop['title'] ?? null);
            $detail = self::text($stop['detail'] ?? null);
            $images = self::images($stop['images'] ?? null);

            if ($title === '' && $detail === '' && $images === []) {
                continue;
            }

            $out[] = [
                'time' => self::time($stop['time'] ?? null),
                'title' => $title,
                'detail' => $detail !== '' ? $detail : null,
                'images' => $images,
            ];

            if (count($out) >= self::MAX_STOPS) {
                break;
            }
        }

        return $out;
    }

    /**
     * "8.30" / "08:30" / "8:30 น." → "08:30" — อย่างอื่นถือว่าไม่ได้ระบุเวลา
     */
    public static function time(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        if (! preg_match('/^\s*(\d{1,2})[:.](\d{2})/u', (string) $value, $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @return array<int, string>
     */
    private static function images(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $urls = [];

        foreach ($value as $url) {
            $url = self::text($url);

            if ($url !== '' && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return array_slice($urls, 0, self::MAX_IMAGES);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
