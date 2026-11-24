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
     * ตัวเลขทุกตัวในต้นฉบับยังอยู่ในคำแปลไหม — ราคา เวลา วันที่ ต้องไม่หาย
     * (เลขไทย ๑๒๓ เทียบเป็นเลขอารบิก, คอมมาหลักพันไม่นับ)
     */
    public static function numbersPreserved(string $source, string $translation): bool
    {
        return self::missingNumbers($source, $translation) === [];
    }

    /**
     * ตัวเลขในต้นฉบับที่หาไม่เจอในคำแปล — ว่าง = ใช้คำแปลได้ ไม่ว่าง = บอกโมเดล
     * ตอนขอแปลใหม่ว่าต้องใส่เลขไหนกลับ
     *
     * คำแปลเขียนเลขเป็นคำได้ ("1 คืน" = "one night", "อันดับ 3" = "third") และเลข
     * รัชกาลเป็นเลขโรมันได้ ("ร.9" = "King Rama IX") — คำอธิบายทริปยาว ๆ เคยถูกทิ้ง
     * ทั้งย่อหน้าเพราะแบบนี้ แต่เวลายังห้ามเปลี่ยนรูป: 13.00 ต้องเป็น 13 ไม่ใช่ 1 p.m.
     *
     * @return list<string>
     */
    public static function missingNumbers(string $source, string $translation): array
    {
        $missing = array_count_values(self::numbers($source));

        foreach (self::translatedNumbers($translation) as $number) {
            if (($missing[$number] ?? 0) > 0) {
                $missing[$number]--;

                continue;
            }
            // ปี พ.ศ. ที่แปลงเป็น ค.ศ. แล้ว (2569 → 2026) ถือว่าเป็นเลขเดียวกัน
            $buddhist = (string) ((int) $number + 543);
            if ((int) $number >= 1900 && (int) $number <= 2200 && ($missing[$buddhist] ?? 0) > 0) {
                $missing[$buddhist]--;
            }
        }

        $left = [];
        foreach ($missing as $number => $count) {
            for ($i = 0; $i < $count; $i++) {
                $left[] = (string) $number;
            }
        }

        return $left;
    }

    /** คำภาษาอังกฤษที่แทนเลขได้ตรงตัว */
    private const NUMBER_WORDS = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11,
        'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16,
        'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30,
        'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
        'once' => 1, 'twice' => 2,
    ];

    /** @return list<string> เลขในคำแปล รวมเลขที่เขียนเป็นคำ และเลขรัชกาลแบบโรมัน */
    private static function translatedNumbers(string $text): array
    {
        $numbers = self::numbers($text);

        preg_match_all('/\bRama\s+([IVXL]+)\b/', $text, $roman);
        foreach ($roman[1] as $numeral) {
            $numbers[] = (string) self::romanValue($numeral);
        }

        preg_match_all('/\b[a-z]+\b/i', $text, $words);
        foreach ($words[0] as $word) {
            $value = self::NUMBER_WORDS[strtolower($word)] ?? null;
            if ($value !== null) {
                $numbers[] = (string) $value;
            }
        }

        return $numbers;
    }

    private static function romanValue(string $numeral): int
    {
        $values = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50];
        $chars = str_split($numeral);
        $total = 0;

        foreach ($chars as $i => $char) {
            $value = $values[$char];
            $next = $values[$chars[$i + 1] ?? ''] ?? 0;
            $total += $value < $next ? -$value : $value;
        }

        return $total;
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
