<?php

namespace App\Support;

use App\Models\BookingPassenger;
use App\Models\TripSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * รายการข้อมูลที่วางลงบนแบบฟอร์มอุทยานได้ และวิธีดึงค่าของแต่ละช่อง
 *
 * หน้าแอดมินได้รายการนี้ไปทำเมนูเลือกช่อง (`catalog()`) ส่วน PermitFormService
 * ใช้ `values()` เติมค่าจริงต่อผู้เดินทาง ช่องที่ไม่มีข้อมูลคืนสตริงว่าง — ปล่อย
 * ให้ลูกค้าเขียนเองบนกระดาษ ไม่เดาแทน
 */
class PermitFormFields
{
    public const TYPE_TEXT = 'text';

    public const TYPE_CHECK = 'check';

    public const TYPE_SIGNATURE = 'signature';

    /** ลายเซ็นที่ผู้เดินทางวาดเอง ดู PermitSignatureService — ค่าของช่องคือ path ของภาพ */
    public const SIGNATURE = 'signature';

    /**
     * ช่องที่ห้ามโชว์เต็มในหน้าที่เปิดด้วยลิงก์ (ลิงก์ของเพื่อนถูกส่งต่อในกลุ่มแชทได้)
     * — แสดงแค่ท้ายตัวเลขให้เจ้าตัวจำได้ว่าเป็นของตัวเอง
     */
    public const SENSITIVE = [
        'id_card', 'passport_no', 'id_or_passport', 'phone', 'email', 'emergency_phone',
        'health_notes', 'allergies', 'birth_date', 'blood_group',
        'addr_house_no', 'addr_moo', 'addr_soi', 'addr_road', 'addr_full', 'home_phone',
    ];

    /**
     * ข้อมูลที่เราไม่เก็บในการจอง (ไม่จำเป็นต่อการเดินทาง + PDPA) แต่อุทยานบังคับกรอก
     * — ถามตอนเซ็นแบบฟอร์มเท่านั้น เฉพาะฟอร์มที่วางช่องเหล่านี้ไว้ เก็บเข้ารหัสใน
     * `booking_passengers.permit_extra` และลบพร้อมลายเซ็นหลังจบทริป
     *
     * key => [label, ตอบ "ไม่มี" ได้ไหม] — "ไม่มี" พิมพ์เป็น "-" อุทยานไม่รับช่องว่าง
     */
    public const ASK_INPUTS = [
        'house_no' => ['บ้านเลขที่', false],
        'moo' => ['หมู่ที่', true],
        'soi' => ['ซอย', true],
        'road' => ['ถนน', true],
        'sub_district' => ['แขวง/ตำบล', false],
        'district' => ['เขต/อำเภอ', false],
        'province' => ['จังหวัด', false],
        'home_phone' => ['โทรศัพท์ (บ้าน)', true],
    ];

    /** ช่องบนฟอร์ม → ข้อมูลที่ต้องถามลูกค้า */
    private const SOURCE_INPUTS = [
        'addr_house_no' => ['house_no'],
        'addr_moo' => ['moo'],
        'addr_soi' => ['soi'],
        'addr_road' => ['road'],
        'addr_sub_district' => ['sub_district', 'district', 'province'],
        'addr_district' => ['sub_district', 'district', 'province'],
        'addr_province' => ['sub_district', 'district', 'province'],
        'addr_zip' => ['sub_district', 'district', 'province'],
        'addr_full' => ['house_no', 'moo', 'soi', 'road', 'sub_district', 'district', 'province'],
        'home_phone' => ['home_phone'],
    ];

    /** ช่องที่ตรงกับข้อมูลที่ถามตัวเดียว — แอป/เว็บใช้แสดงค่าที่กำลังพิมพ์บนฟอร์มทันที */
    private const SOURCE_SINGLE_INPUT = [
        'addr_house_no' => 'house_no',
        'addr_moo' => 'moo',
        'addr_soi' => 'soi',
        'addr_road' => 'road',
        'addr_sub_district' => 'sub_district',
        'addr_district' => 'district',
        'addr_province' => 'province',
        'home_phone' => 'home_phone',
    ];

    /** ช่องที่ข้อความมาจากแอดมินพิมพ์เอง (เช่น ชื่อบริษัทประกัน) */
    public const STATIC = 'static';

    /** เครื่องหมาย ✓ ที่ติ๊กทุกใบ (เช่น ☐ ทำประกันภัยอุบัติเหตุ) */
    public const CHECK_ALWAYS = 'check';

    /**
     * key => [label, group, type, sample]
     */
    private const FIELDS = [
        'title' => ['คำนำหน้า', 'ผู้เดินทาง', self::TYPE_TEXT, 'นาย'],
        'first_name' => ['ชื่อ', 'ผู้เดินทาง', self::TYPE_TEXT, 'สมชาย'],
        'last_name' => ['นามสกุล', 'ผู้เดินทาง', self::TYPE_TEXT, 'ใจดีมาก'],
        'full_name' => ['ชื่อ-นามสกุล', 'ผู้เดินทาง', self::TYPE_TEXT, 'สมชาย ใจดีมาก'],
        'title_full_name' => ['คำนำหน้า+ชื่อ-นามสกุล', 'ผู้เดินทาง', self::TYPE_TEXT, 'นายสมชาย ใจดีมาก'],
        'name_en' => ['ชื่อภาษาอังกฤษ', 'ผู้เดินทาง', self::TYPE_TEXT, 'Somchai Jaidee'],
        'nickname' => ['ชื่อเล่น', 'ผู้เดินทาง', self::TYPE_TEXT, 'ชาย'],
        'age' => ['อายุ (ณ วันเดินทาง)', 'ผู้เดินทาง', self::TYPE_TEXT, '32'],
        'birth_date' => ['วันเกิด', 'ผู้เดินทาง', self::TYPE_TEXT, '14 กุมภาพันธ์ 2537'],
        'nationality' => ['สัญชาติ', 'ผู้เดินทาง', self::TYPE_TEXT, 'ไทย'],
        'id_card' => ['เลขบัตรประชาชน', 'ผู้เดินทาง', self::TYPE_TEXT, '1103700123456'],
        'passport_no' => ['เลขหนังสือเดินทาง', 'ผู้เดินทาง', self::TYPE_TEXT, 'AA1234567'],
        'id_or_passport' => ['เลขบัตร หรือ พาสปอร์ต', 'ผู้เดินทาง', self::TYPE_TEXT, '1103700123456'],
        'health_notes' => ['โรคประจำตัว', 'ผู้เดินทาง', self::TYPE_TEXT, 'ภูมิแพ้'],
        'allergies' => ['แพ้ยา/อาหาร', 'ผู้เดินทาง', self::TYPE_TEXT, 'แพ้กุ้ง'],
        'blood_group' => ['กรุ๊ปเลือด', 'ผู้เดินทาง', self::TYPE_TEXT, 'O'],
        'phone' => ['เบอร์มือถือ', 'ผู้เดินทาง', self::TYPE_TEXT, '0812345678'],
        'email' => ['อีเมล', 'ผู้เดินทาง', self::TYPE_TEXT, 'somchai@example.com'],
        'emergency_name' => ['ผู้ติดต่อฉุกเฉิน (ทั้งชื่อ)', 'ผู้เดินทาง', self::TYPE_TEXT, 'สมหญิง ใจดีมาก'],
        'emergency_first_name' => ['ผู้ติดต่อฉุกเฉิน — ชื่อ', 'ผู้เดินทาง', self::TYPE_TEXT, 'สมหญิง'],
        'emergency_last_name' => ['ผู้ติดต่อฉุกเฉิน — นามสกุล', 'ผู้เดินทาง', self::TYPE_TEXT, 'ใจดีมาก'],
        'emergency_phone' => ['เบอร์ผู้ติดต่อฉุกเฉิน', 'ผู้เดินทาง', self::TYPE_TEXT, '0898765432'],
        'check_mr' => ['✓ ถ้าเป็น "นาย"', 'ผู้เดินทาง', self::TYPE_CHECK, '1'],
        'check_mrs' => ['✓ ถ้าเป็น "นาง"', 'ผู้เดินทาง', self::TYPE_CHECK, ''],
        'check_miss' => ['✓ ถ้าเป็น "นางสาว"', 'ผู้เดินทาง', self::TYPE_CHECK, ''],
        self::SIGNATURE => ['ลายเซ็น', 'ผู้เดินทาง', self::TYPE_SIGNATURE, ''],

        'trip_name' => ['ชื่อทริป', 'ทริป/รอบ', self::TYPE_TEXT, 'เขาช้างเผือก 3 วัน 2 คืน'],
        'days_count' => ['จำนวนวัน', 'ทริป/รอบ', self::TYPE_TEXT, '3'],
        'start_day' => ['วันที่ไป — วัน', 'ทริป/รอบ', self::TYPE_TEXT, '12'],
        'start_month' => ['วันที่ไป — เดือน', 'ทริป/รอบ', self::TYPE_TEXT, 'ธันวาคม'],
        'start_year' => ['วันที่ไป — ปี พ.ศ.', 'ทริป/รอบ', self::TYPE_TEXT, '2569'],
        'start_date' => ['วันที่ไป (เต็ม)', 'ทริป/รอบ', self::TYPE_TEXT, '12 ธันวาคม 2569'],
        'end_day' => ['วันที่กลับ — วัน', 'ทริป/รอบ', self::TYPE_TEXT, '14'],
        'end_month' => ['วันที่กลับ — เดือน', 'ทริป/รอบ', self::TYPE_TEXT, 'ธันวาคม'],
        'end_year' => ['วันที่กลับ — ปี พ.ศ.', 'ทริป/รอบ', self::TYPE_TEXT, '2569'],
        'end_date' => ['วันที่กลับ (เต็ม)', 'ทริป/รอบ', self::TYPE_TEXT, '14 ธันวาคม 2569'],
        'group_size' => ['จำนวนคนทั้งรอบ', 'ทริป/รอบ', self::TYPE_TEXT, '12'],
        'booking_ref' => ['เลขที่การจอง', 'ทริป/รอบ', self::TYPE_TEXT, 'LLK-20261212-0001'],
        'company_name' => ['ชื่อบริษัท/ผู้จัด', 'ทริป/รอบ', self::TYPE_TEXT, 'ลุยเลเขา'],

        'addr_house_no' => ['บ้านเลขที่', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, '99/1'],
        'addr_moo' => ['หมู่ที่', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, '5'],
        'addr_soi' => ['ซอย', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, '-'],
        'addr_road' => ['ถนน', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, 'สุขุมวิท'],
        'addr_sub_district' => ['แขวง/ตำบล', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, 'คลองเตย'],
        'addr_district' => ['เขต/อำเภอ', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, 'คลองเตย'],
        'addr_province' => ['จังหวัด', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, 'กรุงเทพมหานคร'],
        'addr_zip' => ['รหัสไปรษณีย์', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, '10110'],
        'addr_full' => ['ที่อยู่เต็ม (บรรทัดเดียว)', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, '99/1 หมู่ 5 ถนนสุขุมวิท แขวงคลองเตย เขตคลองเตย กรุงเทพมหานคร 10110'],
        'home_phone' => ['โทรศัพท์ (บ้าน)', 'ที่อยู่ (ลูกค้ากรอกตอนเซ็น)', self::TYPE_TEXT, '-'],

        'today_day' => ['วันที่ออกเอกสาร — วัน', 'วันที่ยื่น', self::TYPE_TEXT, '28'],
        'today_month' => ['วันที่ออกเอกสาร — เดือน', 'วันที่ยื่น', self::TYPE_TEXT, 'ตุลาคม'],
        'today_year' => ['วันที่ออกเอกสาร — ปี พ.ศ.', 'วันที่ยื่น', self::TYPE_TEXT, '2569'],

        self::STATIC => ['ข้อความที่พิมพ์เอง', 'อื่น ๆ', self::TYPE_TEXT, ''],
        self::CHECK_ALWAYS => ['✓ ติ๊กทุกใบ', 'อื่น ๆ', self::TYPE_CHECK, '1'],
    ];

    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * ข้อมูลที่ต้องถามลูกค้าตอนเซ็นสำหรับฟอร์มนี้ (เรียงตามลำดับในฟอร์มกรอก)
     *
     * @return list<string>
     */
    public static function askedInputs(array $fields): array
    {
        $needed = collect($fields)
            ->flatMap(fn ($f) => self::SOURCE_INPUTS[$f['source'] ?? ''] ?? [])
            ->unique()
            ->all();

        return array_values(array_filter(array_keys(self::ASK_INPUTS), fn ($k) => in_array($k, $needed, true)));
    }

    /** ช่องนี้แสดงค่าที่ลูกค้ากำลังพิมพ์ช่องไหน (null = ไม่ใช่ช่องที่ถาม หรือประกอบจากหลายช่อง) */
    public static function liveInput(string $source): ?string
    {
        return self::SOURCE_SINGLE_INPUT[$source] ?? null;
    }

    /** ที่อยู่บรรทัดเดียว — ข้ามช่องที่ตอบ "ไม่มี" กรุงเทพใช้ แขวง/เขต ที่อื่นใช้ ต./อ./จ. */
    public static function fullAddress(array $extra): string
    {
        $v = fn (string $k) => trim((string) ($extra[$k] ?? ''));
        $has = fn (string $k) => ! in_array($v($k), ['', '-'], true);
        $bkk = ThaiAddress::isBangkok($v('province'));

        $parts = [
            $has('house_no') ? $v('house_no') : null,
            $has('moo') ? 'หมู่ '.$v('moo') : null,
            $has('soi') ? 'ซอย'.$v('soi') : null,
            $has('road') ? 'ถนน'.$v('road') : null,
            $has('sub_district') ? ($bkk ? 'แขวง' : 'ต.').$v('sub_district') : null,
            $has('district') ? ($bkk ? 'เขต' : 'อ.').$v('district') : null,
            $has('province') ? ($bkk ? '' : 'จ.').$v('province') : null,
            $has('zip') ? $v('zip') : null,
        ];

        return implode(' ', array_filter($parts));
    }

    public static function type(string $key): string
    {
        return self::FIELDS[$key][2] ?? self::TYPE_TEXT;
    }

    /** เมนูเลือกช่องของหน้าแอดมิน */
    public static function catalog(): array
    {
        return collect(self::FIELDS)->map(fn (array $f, string $key) => [
            'key' => $key,
            'label' => $f[0],
            'group' => $f[1],
            'type' => $f[2],
            'sample' => $f[3],
        ])->values()->all();
    }

    /** ค่าตัวอย่างสำหรับปุ่ม "ดูตัวอย่าง" — ไม่ต้องมีการจองจริง */
    public static function sampleValues(): array
    {
        return array_map(fn (array $f) => $f[3], self::FIELDS);
    }

    /**
     * ค่าทุกช่องของผู้เดินทางหนึ่งคน
     *
     * @param  int  $groupSize  จำนวนคนที่ไปจริงทั้งรอบ (ช่อง "กลุ่มคณะ ... คน")
     */
    public static function values(BookingPassenger $passenger, TripSchedule $schedule, int $groupSize, ?CarbonInterface $today = null): array
    {
        $today ??= Carbon::now('Asia/Bangkok');
        $title = trim((string) $passenger->title);
        [$first, $last] = self::splitName($passenger->name);
        [$emergencyFirst, $emergencyLast] = self::splitName($passenger->emergency_contact);

        $start = $schedule->departure_date;
        $end = $schedule->return_date ?? ($start && $schedule->trip?->duration_days
            ? $start->copy()->addDays(max(0, $schedule->trip->duration_days - 1))
            : $start);

        $idCard = preg_replace('/\D/', '', (string) $passenger->id_card);

        return [
            'title' => $title,
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => trim($first.' '.$last),
            'title_full_name' => trim($title.trim($first.' '.$last)),
            'name_en' => (string) $passenger->name_en,
            'nickname' => (string) $passenger->nickname,
            'age' => self::ageOn($passenger->birth_date, $start),
            'birth_date' => $passenger->birth_date ? ThaiDate::full($passenger->birth_date) : '',
            'nationality' => self::nationality($passenger, $idCard),
            'id_card' => $idCard,
            'passport_no' => (string) $passenger->passport_no,
            'id_or_passport' => $idCard !== '' ? $idCard : (string) $passenger->passport_no,
            'health_notes' => (string) $passenger->health_notes,
            'allergies' => (string) $passenger->allergies,
            'blood_group' => (string) $passenger->blood_group,
            'phone' => (string) ($passenger->phone ?: $passenger->booking?->user?->phone),
            'email' => (string) $passenger->email,
            'emergency_name' => trim((string) $passenger->emergency_contact),
            'emergency_first_name' => $emergencyFirst,
            'emergency_last_name' => $emergencyLast,
            'emergency_phone' => (string) $passenger->emergency_phone,
            'check_mr' => $title === 'นาย' ? '1' : '',
            'check_mrs' => $title === 'นาง' ? '1' : '',
            'check_miss' => $title === 'นางสาว' ? '1' : '',
            // PermitFormService ใส่ path ของภาพให้เองเมื่อเซ็นแบบฟอร์มฉบับนั้นแล้ว
            self::SIGNATURE => '',

            'trip_name' => (string) $schedule->trip?->title,
            'days_count' => $start && $end ? (string) ((int) $start->diffInDays($end, true) + 1) : '',
            ...self::dateParts('start', $start),
            ...self::dateParts('end', $end),
            'group_size' => (string) $groupSize,
            'booking_ref' => (string) $passenger->booking?->booking_ref,
            'company_name' => (string) config('company.name'),

            ...self::dateParts('today', $today, withFull: false),

            ...self::extraValues((array) ($passenger->permit_extra ?? [])),

            self::STATIC => '',
            self::CHECK_ALWAYS => '1',
        ];
    }

    /** ค่าของช่องที่ลูกค้ากรอกตอนเซ็น (ว่าง = ยังไม่ได้กรอก ปล่อยว่างในฟอร์ม) */
    private static function extraValues(array $extra): array
    {
        $v = fn (string $k) => trim((string) ($extra[$k] ?? ''));

        return [
            'addr_house_no' => $v('house_no'),
            'addr_moo' => $v('moo'),
            'addr_soi' => $v('soi'),
            'addr_road' => $v('road'),
            'addr_sub_district' => $v('sub_district'),
            'addr_district' => $v('district'),
            'addr_province' => $v('province'),
            'addr_zip' => $v('zip'),
            'addr_full' => $extra === [] ? '' : self::fullAddress($extra),
            'home_phone' => $v('home_phone'),
        ];
    }

    /** "•••••••••3456" — เหลือ 4 ตัวท้ายพอให้เจ้าตัวรู้ว่าเป็นข้อมูลของตัวเอง */
    public static function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length === 0) {
            return '';
        }

        $keep = $length > 6 ? 4 : 0;

        return str_repeat('•', min($length - $keep, 12)).mb_substr($value, $length - $keep);
    }

    /**
     * แยก "ชื่อ นามสกุล" ที่เก็บรวมช่องเดียว — คำแรกเป็นชื่อ ที่เหลือเป็นนามสกุล
     * (นามสกุลไทยบางคนมีช่องว่างข้างใน จึงไม่ตัดจากท้าย)
     */
    public static function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/u', trim((string) $name), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private static function ageOn(?CarbonInterface $birth, ?CarbonInterface $on): string
    {
        if (! $birth) {
            return '';
        }

        return (string) (int) floor($birth->diffInYears($on ?? Carbon::now('Asia/Bangkok'), true));
    }

    /**
     * สัญชาติเป็นชื่อไทย (ในระบบเก็บเป็นรหัสประเทศ เช่น TH) — ถ้าว่างแต่มีเลขบัตร
     * ประชาชนไทย 13 หลักก็เป็นคนไทยแน่นอน
     */
    private static function nationality(BookingPassenger $passenger, string $idCard): string
    {
        $stored = trim((string) $passenger->nationality);

        if ($stored !== '') {
            return Countries::name(strtoupper($stored)) ?? $stored;
        }

        return strlen($idCard) === 13 ? (string) Countries::name(Countries::HOME) : '';
    }

    private static function dateParts(string $prefix, ?CarbonInterface $date, bool $withFull = true): array
    {
        $parts = [
            "{$prefix}_day" => $date ? (string) $date->day : '',
            "{$prefix}_month" => $date ? ThaiDate::monthName($date->month) : '',
            "{$prefix}_year" => $date ? (string) ($date->year + 543) : '',
        ];

        if ($withFull) {
            $parts["{$prefix}_date"] = $date ? ThaiDate::full($date) : '';
        }

        return $parts;
    }
}
