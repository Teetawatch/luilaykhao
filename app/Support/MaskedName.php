<?php

namespace App\Support;

/**
 * "สมชาย ก." — ชื่อต้น + อักษรแรกของนามสกุล สำหรับที่ที่คนแปลกหน้าเห็นชื่อกัน
 */
final class MaskedName
{
    private const TITLES = ['นาย', 'นาง', 'นางสาว', 'น.ส.', 'ด.ช.', 'ด.ญ.', 'Mr.', 'Mrs.', 'Ms.', 'Miss'];

    public static function of(?string $name, string $fallback = 'สมาชิก'): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        if ($parts === []) {
            return $fallback;
        }

        // คำนำหน้าชื่อไม่ใช่ชื่อ — "นาย ก." บอกอะไรใครไม่ได้เลย
        if (count($parts) > 1 && in_array($parts[0], self::TITLES, true)) {
            array_shift($parts);
        }

        $first = $parts[0];

        return isset($parts[1]) ? $first.' '.mb_substr($parts[1], 0, 1).'.' : $first;
    }
}
