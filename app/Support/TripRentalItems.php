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
     * แปลง "key → จำนวน" ที่ลูกค้าเลือก ให้เป็น snapshot ชุดเดียวกับที่ใบจองเก็บ
     *
     * ทั้ง `bookings.selected_rentals` และ `customer_intake_people.selected_rentals`
     * ใช้รูปร่างนี้ ตอนแอดมินดึงข้อมูลไปเปิดใบจองจึงเป็นการคัดลอก ไม่ใช่การแปลง
     *
     * key ที่ไม่มีอยู่ในแคตตาล็อกแล้วถูกข้ามเงียบ ๆ — แคตตาล็อกถูกแก้ระหว่างที่
     * ลูกค้ากรอกฟอร์มค้างไว้ได้ และการปฏิเสธทั้งฟอร์มเพราะของชิ้นเดียวที่ถูกถอด
     * ออกไป แลกไม่คุ้มกับข้อมูลผู้เดินทางทั้งชุดที่จะหายไปด้วย
     *
     * @param  array<string, mixed>  $quantities  key ของอุปกรณ์ → จำนวน
     * @param  array<int, array<string, mixed>>  $catalog  ผลจาก normalize()
     * @return array<int, array<string, mixed>>
     */
    public static function snapshotSelection(array $quantities, array $catalog): array
    {
        $byKey = [];
        foreach ($catalog as $item) {
            $byKey[$item['key']] = $item;
        }

        $result = [];

        foreach ($quantities as $key => $quantity) {
            $quantity = (int) $quantity;
            $item = $byKey[(string) $key] ?? null;

            if ($quantity <= 0 || ! $item) {
                continue;
            }

            $unitPrice = (float) ($item['price'] ?? 0);

            $result[] = [
                'key' => $item['key'],
                'name' => $item['name'],
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'total_price' => $unitPrice * $quantity,
                'image_url' => (string) ($item['image_url'] ?? ''),
                'parts' => self::normalizeParts($item['parts'] ?? []),
            ];
        }

        return $result;
    }

    /**
     * รวม snapshot ของหลายคนให้เหลือรายการเดียวต่ออุปกรณ์หนึ่งชิ้น
     *
     * ใบจองมีรายการเช่าชุดเดียวต่อใบ ไม่ใช่รายคน — กลุ่มที่แต่ละคนเลือกถุงนอน
     * คนละหนึ่งใบต้องกลายเป็น "ถุงนอน 4" ก่อนจะกลายเป็นใบจอง
     *
     * จัดกลุ่มด้วย key ไม่ใช่ชื่อ ด้วยเหตุผลเดียวกับที่ทั้งไฟล์นี้มีอยู่ — ชื่อถูกแก้ได้
     * ราคาต่อหน่วยใช้ของแถวแรกที่เจอ — สองคนที่กรอกคนละวันหลังแอดมินขึ้นราคา
     * ต้องได้ราคาเดียวกันในใบเดียวกัน แล้วให้แอดมินเห็นยอดรวมก่อนกดบันทึก
     *
     * @param  iterable<array<int, array<string, mixed>>>  $selections
     * @return array<int, array<string, mixed>>
     */
    public static function mergeSelections(iterable $selections): array
    {
        $merged = [];

        foreach ($selections as $rows) {
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $name = trim((string) ($row['name'] ?? ''));
                $quantity = (int) ($row['quantity'] ?? 0);
                if ($name === '' || $quantity <= 0) {
                    continue;
                }

                // ของที่ถูกบันทึกไว้ก่อนมีระบบ key ยังจับกลุ่มด้วยชื่อได้เหมือนเดิม
                $key = (string) ($row['key'] ?? '') ?: 'name:'.$name;

                if (! isset($merged[$key])) {
                    $merged[$key] = [
                        'key' => (string) ($row['key'] ?? ''),
                        'name' => $name,
                        'unit_price' => (float) ($row['unit_price'] ?? 0),
                        'quantity' => 0,
                        'total_price' => 0.0,
                        'image_url' => (string) ($row['image_url'] ?? ''),
                    ];
                }

                $merged[$key]['quantity'] += $quantity;
                $merged[$key]['total_price'] = $merged[$key]['unit_price'] * $merged[$key]['quantity'];
            }
        }

        return array_values($merged);
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
