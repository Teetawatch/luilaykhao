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

    /** คู่ช่อง [ชื่อเต็ม, ชื่อ, นามสกุล] ที่ลูกค้ากรอกแยกได้ — ไทย และอังกฤษตามพาสปอร์ต */
    public const PART_COLUMNS = [
        ['name', 'first_name', 'last_name'],
        ['name_en', 'first_name_en', 'last_name_en'],
    ];

    /**
     * ข้อมูลฟอร์มที่ส่งชื่อ/นามสกุลแยกช่องมา → เติมชื่อเต็มจากสองช่องนั้นก่อนตรวจ
     * (กฎ ThaiName / name_en จึงตรวจชื่อเต็มเหมือนเดิม) ช่องที่ไม่ได้ส่งมาเลยปล่อย
     * ไว้ตามเดิม — แอปรุ่นเก่าส่งแค่ชื่อเต็ม
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function composeParts(array $data): array
    {
        foreach (self::PART_COLUMNS as [$full, $first, $last]) {
            if (! array_key_exists($first, $data) && ! array_key_exists($last, $data)) {
                continue;
            }

            $firstValue = self::normalize(is_string($data[$first] ?? null) ? $data[$first] : '');
            $lastValue = self::normalize(is_string($data[$last] ?? null) ? $data[$last] : '');
            $data[$first] = $firstValue !== '' ? $firstValue : null;
            $data[$last] = $lastValue !== '' ? $lastValue : null;

            if ($firstValue !== '' || $lastValue !== '') {
                $data[$full] = self::join($firstValue, $lastValue);
            }
        }

        return $data;
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
