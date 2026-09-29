<?php

namespace App\Support;

/**
 * เงื่อนไขที่ผูกพันลูกค้า ในรูปแบบที่ส่งให้ไคลเอนต์ได้
 *
 * config/legal.php เก็บตัวเลขกับประโยคดิบ ที่นี่แทนค่า :placeholder ให้เป็น
 * ประโยคที่อ่านได้ เพื่อให้ช่องทางที่ไม่มี build step (LIFF) ไม่ต้องพิมพ์
 * เงื่อนไขซ้ำเองผ่าน GET /legal/policy
 */
class LegalPolicy
{
    /**
     * ข้อตกลงก่อนยืนยันการจอง — ข้อความล้วน ไม่มีแท็ก
     *
     * @return array<int, string>
     */
    public static function bookingTerms(): array
    {
        $replacements = [];

        foreach ((array) config('legal.policy') as $key => $value) {
            if (is_bool($value)) {
                continue; // บูลีนไม่เคยถูกพิมพ์ลงประโยค — มันเปลี่ยนว่าจะพูดอะไร ไม่ใช่เลขในประโยค
            }

            $replacements[':'.$key] = (string) $value;
        }

        return array_values(array_map(
            fn (string $line) => strtr($line, $replacements),
            (array) config('legal.booking_terms', []),
        ));
    }

    /**
     * ข้อความของเงื่อนไขฉบับที่ระบุ ตามที่ประกาศใช้จริงในวันนั้น
     *
     * ทุกฉบับถูกเก็บไว้ที่ resources/legal/booking-terms/{version}.json และไม่
     * แก้ย้อนหลัง ใบจองเก่าที่มีแค่ terms_version (ก่อนมีตาราง
     * booking_term_acceptances) จึงยังเปิดดูได้ว่าตอนนั้นลูกค้าเห็นอะไร
     * คืน null เมื่อไม่มีฉบับนั้นในคลัง
     *
     * @return array<int, string>|null
     */
    public static function archivedBookingTerms(string $version): ?array
    {
        // เวอร์ชันมาจากฐานข้อมูล แต่ก็กันไว้ไม่ให้กลายเป็น path ไปไฟล์อื่น
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $version)) {
            return null;
        }

        $path = resource_path("legal/booking-terms/{$version}.json");

        if (! is_file($path)) {
            return null;
        }

        $lines = json_decode((string) file_get_contents($path), true);

        return is_array($lines) ? array_values($lines) : null;
    }

    /**
     * ลายนิ้วมือของข้อความที่ลูกค้ากดยอมรับ — แก้ตัวอักษรเดียวค่าก็เปลี่ยน
     *
     * @param  array<int, string>  $lines
     */
    public static function fingerprint(string $version, array $lines): string
    {
        return hash('sha256', $version."\n".implode("\n", array_values($lines)));
    }

    /**
     * ก้อนเดียวที่ไคลเอนต์ต้องใช้: เวอร์ชันเอกสาร ตัวเลขนโยบาย และประโยคที่แสดง
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'terms_version' => config('legal.terms_version'),
            'privacy_version' => config('legal.privacy_version'),
            'policy' => (array) config('legal.policy'),
            'booking_terms' => self::bookingTerms(),
        ];
    }
}
