<?php

namespace App\Support;

/**
 * ชื่อคนแบบ "ชื่อ นามสกุล" — ที่เดียวที่รู้วิธีรวมและแยก
 */
final class PersonName
{
    /** ตัดช่องว่างหัวท้าย และยุบช่องว่างซ้อนให้เหลือช่องเดียว */
    public static function normalize(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    public static function join(?string $first, ?string $last): string
    {
        return self::normalize(self::normalize($first).' '.self::normalize($last));
    }

    /**
     * แยกชื่อเต็มที่เก็บช่องเดียว — คำแรกเป็นชื่อ ที่เหลือเป็นนามสกุล
     * (นามสกุลไทยบางคนมีช่องว่างข้างใน จึงไม่ตัดจากท้าย) ใช้เฉพาะเมื่อไม่มีชื่อ/
     * นามสกุลที่ลูกค้ากรอกแยกไว้
     *
     * @return array{0: string, 1: string}
     */
    public static function split(?string $full): array
    {
        $parts = preg_split('/\s+/u', self::normalize($full), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
