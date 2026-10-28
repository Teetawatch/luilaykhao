<?php

namespace App\Support;

/**
 * รายชื่อตำบล/อำเภอ/จังหวัดของไทย — ใช้ให้ลูกค้าเลือกที่อยู่จากรายการตอนเซ็นแบบฟอร์ม
 * อุทยาน (พิมพ์เองสะกดผิดบ่อย แล้วอุทยานตีกลับ) และตรวจฝั่งเซิร์ฟเวอร์ว่าเลือกมาจริง
 *
 * ข้อมูลจาก kongvut/thai-province-data (MIT) ย่อเหลือชื่อไทย + รหัสไปรษณีย์ไว้ที่
 * resources/data/thai_address.json — แอป/เว็บดึงผ่าน GET /api/v1/thai-address
 */
class ThaiAddress
{
    public const PATH = 'data/thai_address.json';

    private static ?array $data = null;

    private static ?array $index = null;

    /** @return array{source: string, columns: list<string>, rows: list<array{0: string, 1: string, 2: string, 3: int|null}>} */
    public static function data(): array
    {
        return self::$data ??= json_decode((string) file_get_contents(resource_path(self::PATH)), true);
    }

    /** รหัสไปรษณีย์ของตำบลนี้ — null = ไม่มีตำบล/อำเภอ/จังหวัดชุดนี้อยู่จริง */
    public static function zipFor(string $subDistrict, string $district, string $province): ?string
    {
        if (self::$index === null) {
            self::$index = [];
            foreach (self::data()['rows'] as [$sub, $dist, $prov, $zip]) {
                self::$index[$prov.'|'.$dist.'|'.$sub] = (string) ($zip ?? '');
            }
        }

        $zip = self::$index[trim($province).'|'.trim($district).'|'.trim($subDistrict)] ?? null;

        return $zip;
    }

    public static function exists(string $subDistrict, string $district, string $province): bool
    {
        return self::zipFor($subDistrict, $district, $province) !== null;
    }

    public static function isBangkok(?string $province): bool
    {
        return trim((string) $province) === 'กรุงเทพมหานคร';
    }
}
