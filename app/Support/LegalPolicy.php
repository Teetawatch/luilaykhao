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
