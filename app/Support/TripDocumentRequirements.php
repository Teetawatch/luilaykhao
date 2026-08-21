<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * ตัวอ่าน/ตัวจัดระเบียบ "เอกสารที่ทริปนี้ขอ" — ที่เดียวที่รู้รูปร่างของ
 * `trips.document_requirements`
 *
 * ทำไมต้องมี: ค่านี้เป็น JSON ที่แอดมินพิมพ์เอง จึงเจอทั้งแถวว่าง แถวไม่มี key
 * และแถวที่มาจากทริปเก่าที่ยังไม่เคยตั้งค่า ทุกฝั่ง (หน้าเว็บ แอป แอดมิน
 * ตัวตรวจตอนอัปโหลด) ต้องเห็นรายการชุดเดียวกันเป๊ะ ๆ ไม่ใช่ต่างคนต่าง normalize
 */
class TripDocumentRequirements
{
    /**
     * จัดระเบียบรายการดิบให้เหลือเฉพาะแถวที่ใช้งานได้จริง
     *
     * แถวที่ไม่มีชื่อเอกสารถูกตัดทิ้ง — เอกสารที่ไม่มีชื่อ ลูกค้าอ่านไม่ออกว่า
     * ต้องส่งอะไร ส่วนแถวที่ไม่มี `key` (แอดมินเพิ่งเพิ่มในหน้าแก้ทริป) จะได้
     * key จากชื่อของมันเอง
     *
     * @return array<int, array{key: string, label: string, note: string, required: bool}>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $result = [];
        $seen = [];

        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $key = self::slug($row['key'] ?? '') ?: self::slug($label) ?: 'doc';
            // กัน key ชนกันเมื่อสองแถวชื่อเหมือนกัน — ไฟล์จะได้ไม่ไปโผล่ผิดช่อง
            if (isset($seen[$key])) {
                $key = $key.'-'.($index + 1);
            }
            $seen[$key] = true;

            $result[] = [
                'key' => $key,
                'label' => $label,
                'note' => trim((string) ($row['note'] ?? '')),
                'required' => (bool) ($row['required'] ?? false),
            ];
        }

        return $result;
    }

    /** ข้อกำหนดที่ตรงกับ key นี้ หรือ null เมื่อทริปไม่ได้ขอเอกสารชิ้นนี้ */
    public static function find(mixed $raw, string $key): ?array
    {
        foreach (self::normalize($raw) as $requirement) {
            if ($requirement['key'] === $key) {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * key ที่ปลอดภัยกับทั้ง URL และชื่อโฟลเดอร์
     *
     * ชื่อเอกสารเป็นภาษาไทยแทบทุกใบ ซึ่ง Str::slug ตัดทิ้งจนเหลือค่าว่าง จึง
     * ถอยไปใช้ hash สั้น ๆ ของชื่อแทน — ไม่สวย แต่คงที่ ผูกไฟล์เก่าได้ตลอด
     */
    private static function slug(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $slug = Str::slug($value);

        return $slug !== '' ? Str::limit($slug, 60, '') : 'doc-'.substr(md5($value), 0, 8);
    }
}
