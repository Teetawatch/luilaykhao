<?php

namespace App\Support;

use App\Jobs\PurgeBorderPassDocumentsJob;
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
    /** ใช้กับผู้เดินทางทุกคน — เอกสารที่แอดมินตั้งเองบนทริป */
    public const FOR_ALL = 'all';

    /** ใช้เฉพาะคนที่เลือกให้ทีมงานทำ Border Pass */
    public const FOR_BORDER_PASS = 'border_pass';

    public const BORDER_PASS_FRONT = 'border-pass-id-front';

    public const BORDER_PASS_BACK = 'border-pass-id-back';

    public const BORDER_PASS_KEYS = [self::BORDER_PASS_FRONT, self::BORDER_PASS_BACK];

    /** ข้อความที่บอกลูกค้าว่าไฟล์นี้ไปที่ไหนและอยู่นานแค่ไหน — ต้องตรงกับ PurgeBorderPassDocumentsJob */
    public const BORDER_PASS_NOTE = 'ด่านใช้เป็นหลักฐานทำ Border Pass — ใช้ยื่นด่านเท่านั้น เปิดดูได้เฉพาะทีมงาน '
        .'และระบบลบทิ้งอัตโนมัติ '.PurgeBorderPassDocumentsJob::RETENTION_DAYS.' วันหลังจบทริป';

    /**
     * บัตรประชาชนด้านหน้า/ด้านหลังของคนทำ Border Pass — กฎของด่าน ไม่ใช่สิ่งที่
     * แอดมินเลือก จึงไม่อยู่ใน `trips.document_requirements` แต่ระบบใส่ให้เอง
     * กับทุกทริปที่ให้เลือก Border Pass (ดู BookingDocumentService::requirementsFor)
     *
     * @return array<int, array{key: string, label: string, note: string, required: bool, for: string}>
     */
    public static function borderPass(): array
    {
        return [
            [
                'key' => self::BORDER_PASS_FRONT,
                'label' => 'บัตรประชาชน (ด้านหน้า)',
                'note' => self::BORDER_PASS_NOTE,
                'required' => true,
                'for' => self::FOR_BORDER_PASS,
            ],
            [
                'key' => self::BORDER_PASS_BACK,
                'label' => 'บัตรประชาชน (ด้านหลัง)',
                'note' => self::BORDER_PASS_NOTE,
                'required' => true,
                'for' => self::FOR_BORDER_PASS,
            ],
        ];
    }

    public static function isBorderPassKey(string $key): bool
    {
        return in_array($key, self::BORDER_PASS_KEYS, true);
    }

    /**
     * จัดระเบียบรายการดิบให้เหลือเฉพาะแถวที่ใช้งานได้จริง
     *
     * แถวที่ไม่มีชื่อเอกสารถูกตัดทิ้ง — เอกสารที่ไม่มีชื่อ ลูกค้าอ่านไม่ออกว่า
     * ต้องส่งอะไร ส่วนแถวที่ไม่มี `key` (แอดมินเพิ่งเพิ่มในหน้าแก้ทริป) จะได้
     * key จากชื่อของมันเอง
     *
     * @return array<int, array{key: string, label: string, note: string, required: bool, for: string}>
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
            // (รวมถึง key ที่ระบบจองไว้ให้บัตรประชาชนของ Border Pass)
            if (isset($seen[$key]) || self::isBorderPassKey($key)) {
                $key = $key.'-'.($index + 1);
            }
            $seen[$key] = true;

            $result[] = [
                'key' => $key,
                'label' => $label,
                'note' => trim((string) ($row['note'] ?? '')),
                'required' => (bool) ($row['required'] ?? false),
                'for' => self::FOR_ALL,
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
