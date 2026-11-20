<?php

namespace App\Support;

/**
 * กติกาเดียวของ "ข้อความไหนในเนื้อหาที่ต้องแปล" — ใช้ทั้งตอนตัดช่องส่งไปแปล
 * และตอนสวมคำแปลกลับใน API ทั้งสองฝั่งจึงเห็นช่องตรงกันเสมอ
 *
 * ช่องที่ไม่มีอักษรไทยไม่ต้องแปล — ค่าสถานะ (included/self/none) ไอคอน URL
 * เวลา และ key ไม่มีอักษรไทยอยู่แล้ว จึงไม่ต้องไล่จดชื่อคีย์ของทุกโครงสร้าง
 */
class ContentText
{
    /** คีย์ที่ห้ามแปลแม้จะมีอักษรไทย — เป็นค่าที่โค้ดใช้เทียบ ไม่ใช่ข้อความให้คนอ่าน */
    public const SKIP_KEYS = [
        'key', 'icon', 'image_url', 'images', 'url', 'map_url', 'time', 'status',
        'price_type', 'example_image_url', 'color', 'region', 'slug',
    ];

    public static function isThai(mixed $value): bool
    {
        return is_string($value) && preg_match('/\p{Thai}/u', $value) === 1;
    }

    /**
     * ข้อความไทยทุกช่องในค่า (string หรือ array ซ้อนกัน) เป็น path => ข้อความ
     *
     * @return array<string, string>
     */
    public static function segments(mixed $value, string $path): array
    {
        if (is_string($value)) {
            return self::isThai($value) ? [$path => trim($value)] : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $segments = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::SKIP_KEYS, true)) {
                continue;
            }
            $segments += self::segments($item, $path.'.'.$key);
        }

        return $segments;
    }

    /**
     * แทนข้อความไทยทุกช่องด้วยผลของ $replace(ข้อความ, path) — โครงสร้าง ตัวเลข
     * และคีย์อยู่ครบตามเดิม
     *
     * @param  callable(string, string): string  $replace
     */
    public static function map(mixed $value, string $path, callable $replace): mixed
    {
        if (is_string($value)) {
            return self::isThai($value) ? $replace(trim($value), $path) : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::SKIP_KEYS, true)) {
                continue;
            }
            $value[$key] = self::map($item, $path.'.'.$key, $replace);
        }

        return $value;
    }

    /**
     * ตัวเลขทุกตัวในต้นฉบับยังอยู่ในคำแปลไหม — ราคา เวลา วันที่ ต้องไม่หายหรือ
     * ถูกแต่งเพิ่ม (เลขไทย ๑๒๓ เทียบเป็นเลขอารบิก, คอมมาหลักพันไม่นับ)
     */
    public static function numbersPreserved(string $source, string $translation): bool
    {
        $missing = array_count_values(self::numbers($source));

        foreach (self::numbers($translation) as $number) {
            if (isset($missing[$number])) {
                $missing[$number]--;
            }
        }

        return array_sum(array_map(fn (int $left) => max(0, $left), $missing)) === 0;
    }

    /** @return list<string> */
    private static function numbers(string $text): array
    {
        $text = strtr($text, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4',
            '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
        // 1,500 → 1500 แต่ "1, 2" ยังเป็นสองตัว
        $text = preg_replace('/(?<=\d),(?=\d{3}\b)/', '', $text);
        preg_match_all('/\d+/', (string) $text, $matches);

        // 08 กับ 8 คือเลขเดียวกัน ("08.00 น." → "8:00 a.m.")
        return array_map(fn (string $n) => ltrim($n, '0') === '' ? '0' : ltrim($n, '0'), $matches[0]);
    }
}
