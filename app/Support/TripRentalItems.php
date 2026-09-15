<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * ตัวอ่าน/ตัวจัดระเบียบ "อุปกรณ์ให้เช่า" — ที่เดียวที่รู้รูปร่างของ
 * `trips.rental_items`
 *
 * ทำไมต้องมี `key`: ทุกอย่างที่อ้างถึงอุปกรณ์ชิ้นหนึ่ง (ใบจองที่แช่ snapshot ไว้
 * ใบเตรียมของที่ต้องรู้ว่าชุดนี้มีอะไรบ้าง) เคยผูกกันด้วย "ชื่อ" ซึ่งแปลว่าพอ
 * แอดมินแก้ชื่อ ใบจองเก่าก็หลุดจากแคตตาล็อกเงียบ ๆ แล้วชุดกลายเป็นของชิ้นเดียว
 * ในใบเตรียมของ — เหมือนที่ [[TripDocumentRequirements]] เคยเจอกับไฟล์ที่ลูกค้า
 * อัปโหลด จึงแก้ด้วยวิธีเดียวกัน: เซิร์ฟเวอร์ตั้ง key ให้ตอนบันทึกครั้งแรก
 * ฟอร์มแอดมินส่งกลับมาทุกครั้ง แล้วทุกฝั่ง join ด้วย key ไม่ใช่ชื่อ
 */
class TripRentalItems
{
    /**
     * จัดระเบียบรายการดิบให้เหลือเฉพาะแถวที่ใช้งานได้จริง พร้อม key ถาวร
     *
     * แถวที่ไม่มีชื่อถูกตัดทิ้ง (ลูกค้าอ่านไม่ออกว่าเช่าอะไร) ส่วนแถวที่ยังไม่มี key
     * — ทริปเก่าที่บันทึกไว้ก่อนมีระบบนี้ หรือแถวที่แอดมินเพิ่งเพิ่ม — จะได้ key
     * จากชื่อของมันเอง ครั้งแรกครั้งเดียว
     *
     * @return array<int, array{key: string, name: string, price: float, description: string, image_url: string, parts: array<int, array{name: string, quantity: int}>}>
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

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = self::slug($row['key'] ?? '') ?: self::slug($name) ?: 'rental';
            // กัน key ชนกันเมื่อสองแถวชื่อเหมือนกัน — ใบจองจะได้ไม่ชี้ผิดชิ้น
            if (isset($seen[$key])) {
                $key = $key.'-'.($index + 1);
            }
            $seen[$key] = true;

            $result[] = [
                'key' => $key,
                'name' => $name,
                'price' => (float) ($row['price'] ?? 0),
                'description' => trim((string) ($row['description'] ?? '')),
                'image_url' => trim((string) ($row['image_url'] ?? '')),
                'parts' => self::normalizeParts($row['parts'] ?? []),
            ];
        }

        return $result;
    }

    /** อุปกรณ์ที่ตรงกับ key นี้ หรือ null เมื่อทริปไม่มีรายการนี้แล้ว */
    public static function find(mixed $raw, string $key): ?array
    {
        foreach (self::normalize($raw) as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * ของที่อยู่ในชุด — [{name, quantity}] ที่ตัดแถวว่างและจำนวน 0 ออกแล้ว
     *
     * ใช้ได้กับทั้งแคตตาล็อกและ snapshot บนใบจอง เพราะเก็บรูปร่างเดียวกัน
     *
     * @return array<int, array{name: string, quantity: int}>
     */
    public static function normalizeParts(mixed $parts): array
    {
        if (! is_array($parts)) {
            return [];
        }

        $result = [];

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $name = trim((string) ($part['name'] ?? ''));
            $quantity = max(0, (int) ($part['quantity'] ?? 0));

            if ($name === '' || $quantity === 0) {
                continue;
            }

            $result[] = ['name' => $name, 'quantity' => $quantity];
        }

        return $result;
    }

    /**
     * key ที่คงที่และอ่านได้พอประมาณ
     *
     * ชื่ออุปกรณ์เป็นภาษาไทยแทบทั้งหมด ซึ่ง Str::slug ตัดทิ้งจนเหลือค่าว่าง จึง
     * ถอยไปใช้ hash สั้น ๆ ของชื่อแทน — ไม่สวย แต่คงที่ ผูกใบจองเก่าได้ตลอด
     */
    private static function slug(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $slug = Str::slug($value);

        return $slug !== '' ? Str::limit($slug, 60, '') : 'rental-'.substr(md5($value), 0, 8);
    }
}
