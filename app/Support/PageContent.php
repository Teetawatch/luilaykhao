<?php

namespace App\Support;

use App\Models\Setting;

/**
 * เนื้อหาของหน้า "ข้อมูลก่อนไป" ที่เดิมฝังเป็นค่าคงที่ในไฟล์ frontend
 *
 * แต่ละหน้าประกาศ schema (ว่ามีช่องอะไรให้กรอกบ้าง) กับ defaults (เนื้อหาที่ใช้อยู่ตอนนี้)
 * ไว้ที่เดียวกัน แอดมินแก้ผ่านหน้า /admin/content แล้วค่าที่แก้ทับ defaults ทีละคีย์
 * ถ้ายังไม่เคยแก้ หน้าเว็บก็ยังได้เนื้อหาเดิมครบ — ไม่มีทางที่หน้าจะว่างเพราะยังไม่มีใครกรอก
 *
 * ชนิดของช่อง (type):
 *   text|textarea  ข้อความบรรทัดเดียว / หลายบรรทัด
 *   list           รายการข้อความ (บรรทัดละข้อ)
 *   repeater       รายการของกลุ่มช่อง — ซ้อนได้อีกหนึ่งชั้น
 *   bool           สวิตช์เปิด/ปิด
 *   select         เลือกหนึ่งค่าจาก options
 *   multiselect    เลือกได้หลายค่า โดยตัวเลือกมาจาก repeater อีกช่อง (options_from)
 *   icon           ชื่อไอคอน Material Symbols
 *   color          สี hex
 */
class PageContent
{
    /** ค่าที่แอดมินแก้แล้วเก็บใน settings ด้วยคีย์นี้ */
    private const STORAGE_PREFIX = 'page_content:';

    /** โทนสีที่หน้าเว็บรองรับ — จำกัดไว้เพื่อไม่ให้แอดมินต้องรู้จัก CSS */
    public const TONES = [
        'primary' => 'เขียวเข้ม (หลัก)',
        'accent' => 'เขียวสด',
        'gold' => 'ทอง',
        'teal' => 'ฟ้าเขียว',
    ];

    /** โทนของระดับความยาก */
    public const LEVEL_TONES = [
        'easy' => 'เขียว (ง่าย)',
        'medium' => 'เหลือง (ปานกลาง)',
        'hard' => 'แดง (ยาก)',
    ];

    /**
     * ทะเบียนหน้าเนื้อหาทั้งหมด
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        return [
            'checklist' => self::checklist(),
            'difficulty' => self::difficulty(),
            'faq' => self::faq(),
            'booking_guide' => self::bookingGuide(),
            'problem' => self::problem(),
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::pages());
    }

    /** @return array<string, mixed> */
    public static function definition(string $key): array
    {
        return self::pages()[$key];
    }

    /**
     * รายการหน้าแบบย่อ สำหรับหน้ารวมในแอดมิน
     *
     * @return array<int, array<string, mixed>>
     */
    public static function summaries(): array
    {
        $customised = Setting::query()
            ->where('key', 'like', self::STORAGE_PREFIX.'%')
            ->pluck('updated_at', 'key');

        $rows = [];

        foreach (self::pages() as $key => $page) {
            $stored = $customised[self::STORAGE_PREFIX.$key] ?? null;

            $rows[] = [
                'key' => $key,
                'label' => $page['label'],
                'route' => $page['route'],
                'description' => $page['description'],
                'customised' => $stored !== null,
                'updated_at' => $stored,
            ];
        }

        return $rows;
    }

    /**
     * เนื้อหาที่ควรแสดงจริง — ค่าที่แอดมินแก้ทับ defaults ทีละคีย์บนสุด
     *
     * ที่ merge ทีละคีย์ (ไม่ใช่แทนทั้งก้อน) เพราะถ้าวันหลังเพิ่มช่องใหม่ใน schema
     * หน้าที่แอดมินเคยแก้ไว้แล้วจะได้ค่าเริ่มต้นของช่องใหม่ไปด้วย ไม่ใช่ค่าว่าง
     *
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        $defaults = self::defaults($key);
        $stored = Setting::get(self::STORAGE_PREFIX.$key);

        if (! is_array($stored)) {
            return $defaults;
        }

        return array_merge($defaults, $stored);
    }

    /** @return array<string, mixed> */
    public static function defaults(string $key): array
    {
        return self::definition($key)['defaults'];
    }

    /** @param array<string, mixed> $value */
    public static function put(string $key, array $value): void
    {
        Setting::put(self::STORAGE_PREFIX.$key, $value);
    }

    /** กลับไปใช้เนื้อหาเริ่มต้นที่มากับระบบ */
    public static function reset(string $key): void
    {
        Setting::where('key', self::STORAGE_PREFIX.$key)->delete();
        Setting::forget(self::STORAGE_PREFIX.$key);
    }

    /**
     * กฎ validate ที่สร้างจาก schema ของหน้านั้น ทุกช่องอยู่ใต้ content.*
     *
     * @return array<string, mixed>
     */
    public static function rules(string $key): array
    {
        return self::rulesFor(self::definition($key)['fields'], 'content');
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function rulesFor(array $fields, string $prefix): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $path = $prefix.'.'.$field['key'];

            $rules = array_merge($rules, match ($field['type']) {
                'text', 'icon' => [$path => ['present', 'nullable', 'string', 'max:300']],
                'textarea' => [$path => ['present', 'nullable', 'string', 'max:5000']],
                'color' => [$path => ['present', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']],
                'bool' => [$path => ['present', 'boolean']],
                'select' => [$path => ['present', 'nullable', 'string', 'in:'.implode(',', array_keys($field['options']))]],
                'list' => [
                    $path => ['present', 'array', 'max:60'],
                    $path.'.*' => ['string', 'max:1000'],
                ],
                'multiselect' => [
                    $path => ['present', 'array', 'max:30'],
                    $path.'.*' => ['string', 'max:60'],
                ],
                'repeater' => array_merge(
                    [$path => ['present', 'array', 'max:'.($field['max'] ?? 60)]],
                    self::rulesFor($field['fields'], $path.'.*'),
                ),
                default => [],
            });
        }

        return $rules;
    }

    // ── นิยามของแต่ละหน้า ──────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function checklist(): array
    {
        return [
            'label' => 'เช็คลิสต์ของที่ต้องเตรียม',
            'route' => '/checklist',
            'description' => 'รายการของที่ต้องแพ็ก แยกตามแบบทริป ฤดู และไป-กลับ/ค้างคืน',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'หัวข้อหน้า'],
                ['key' => 'intro', 'type' => 'textarea', 'label' => 'คำโปรย'],
                ['key' => 'footnote', 'type' => 'textarea', 'label' => 'ข้อความปิดท้าย'],
                [
                    'key' => 'trip_types',
                    'type' => 'repeater',
                    'label' => 'แบบทริป',
                    'item_label' => 'แบบทริป',
                    'hint' => 'คีย์ใช้อ้างอิงในรายการของ ถ้าแก้คีย์ต้องไปแก้ที่รายการของด้วย',
                    'max' => 12,
                    'fields' => [
                        ['key' => 'key', 'type' => 'text', 'label' => 'คีย์'],
                        ['key' => 'label', 'type' => 'text', 'label' => 'ชื่อที่แสดง'],
                    ],
                ],
                [
                    'key' => 'seasons',
                    'type' => 'repeater',
                    'label' => 'ฤดู',
                    'item_label' => 'ฤดู',
                    'max' => 12,
                    'fields' => [
                        ['key' => 'key', 'type' => 'text', 'label' => 'คีย์'],
                        ['key' => 'label', 'type' => 'text', 'label' => 'ชื่อที่แสดง'],
                    ],
                ],
                [
                    'key' => 'nights',
                    'type' => 'repeater',
                    'label' => 'ระยะเวลา',
                    'item_label' => 'ตัวเลือก',
                    'hint' => 'ต้องมีคีย์ overnight อยู่เสมอ เพราะรายการของที่ติ๊กว่า "เฉพาะค้างคืน" อ้างถึงคีย์นี้',
                    'max' => 6,
                    'fields' => [
                        ['key' => 'key', 'type' => 'text', 'label' => 'คีย์'],
                        ['key' => 'label', 'type' => 'text', 'label' => 'ชื่อที่แสดง'],
                    ],
                ],
                [
                    'key' => 'categories',
                    'type' => 'repeater',
                    'label' => 'หมวดของที่ต้องเตรียม',
                    'item_label' => 'หมวด',
                    'max' => 20,
                    'fields' => [
                        ['key' => 'key', 'type' => 'text', 'label' => 'คีย์'],
                        ['key' => 'title', 'type' => 'text', 'label' => 'ชื่อหมวด'],
                        [
                            'key' => 'items',
                            'type' => 'repeater',
                            'label' => 'รายการของ',
                            'item_label' => 'ของ',
                            'max' => 60,
                            'fields' => [
                                ['key' => 'label', 'type' => 'text', 'label' => 'ชื่อของ'],
                                ['key' => 'note', 'type' => 'textarea', 'label' => 'หมายเหตุ'],
                                ['key' => 'essential', 'type' => 'bool', 'label' => 'ขาดไม่ได้'],
                                ['key' => 'overnight', 'type' => 'bool', 'label' => 'เฉพาะทริปค้างคืน'],
                                [
                                    'key' => 'trips',
                                    'type' => 'multiselect',
                                    'label' => 'เฉพาะแบบทริป',
                                    'options_from' => 'trip_types',
                                    'hint' => 'ไม่เลือก = ใช้ทุกแบบ',
                                ],
                                [
                                    'key' => 'seasons',
                                    'type' => 'multiselect',
                                    'label' => 'เฉพาะฤดู',
                                    'options_from' => 'seasons',
                                    'hint' => 'ไม่เลือก = ใช้ทุกฤดู',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'defaults' => [
                'title' => 'เช็คลิสต์ของที่ต้องเตรียม',
                'intro' => 'เลือกแบบทริปที่จะไป แล้วได้รายการที่ตรงกับสถานการณ์จริง ติ๊กแล้วระบบจำไว้ให้ ปรินต์หรือเปิดค้างไว้ตอนจัดของก็ได้',
                'footnote' => 'รายการนี้เป็นพื้นฐานทั่วไป — ทริปที่จองกับเราจะมีรายการเฉพาะของทริปนั้นแจ้งอีกที',
                'trip_types' => [
                    ['key' => 'hiking', 'label' => 'เดินป่า / ขึ้นดอย'],
                    ['key' => 'snorkel', 'label' => 'ทะเล / ดำน้ำตื้น'],
                    ['key' => 'camping', 'label' => 'แคมป์ปิ้ง / กางเต็นท์'],
                ],
                'seasons' => [
                    ['key' => 'winter', 'label' => 'หน้าหนาว (พ.ย.–ก.พ.)'],
                    ['key' => 'summer', 'label' => 'หน้าร้อน (มี.ค.–พ.ค.)'],
                    ['key' => 'rainy', 'label' => 'หน้าฝน (มิ.ย.–ต.ค.)'],
                ],
                'nights' => [
                    ['key' => 'day', 'label' => 'ไป-กลับวันเดียว'],
                    ['key' => 'overnight', 'label' => 'ค้างคืน'],
                ],
                'categories' => [
                    [
                        'key' => 'documents',
                        'title' => 'เอกสารและของสำคัญ',
                        'items' => [
                            self::item('บัตรประชาชน (ตัวจริง)', note: 'อุทยานหลายแห่งขอตรวจก่อนเข้า', essential: true),
                            self::item('เงินสดย่อย', note: 'บนดอยส่วนใหญ่ไม่มีสัญญาณให้สแกนจ่าย', essential: true),
                            self::item('ยาประจำตัว + ใบสั่งยา', essential: true),
                            self::item('เบอร์ติดต่อฉุกเฉินเขียนใส่กระดาษ', note: 'เผื่อโทรศัพท์แบตหมดหรือเปียก'),
                            self::item('ประกันการเดินทาง (ถ้ามี)'),
                        ],
                    ],
                    [
                        'key' => 'clothing',
                        'title' => 'เสื้อผ้า',
                        'items' => [
                            self::item('เสื้อแขนยาวกันแดด/กันแมลง', essential: true),
                            self::item('กางเกงขายาวที่เคลื่อนไหวสะดวก', essential: true, trips: ['hiking', 'camping']),
                            self::item('เสื้อผ้าเปลี่ยนต่อวัน', essential: true),
                            self::item('ชุดว่ายน้ำ / เสื้อรัดกล้ามเนื้อกันแดด', essential: true, trips: ['snorkel']),
                            self::item('เสื้อกันหนาวหรือแจ็กเก็ตขนเป็ด', note: 'ยอดดอยหน้าหนาวลงต่ำกว่า 10 องศาได้', essential: true, seasons: ['winter']),
                            self::item('หมวกไหมพรม + ถุงมือ', seasons: ['winter']),
                            self::item('เสื้อกันฝน / ปอนโช', essential: true, seasons: ['rainy']),
                            self::item('หมวกกันแดดปีกกว้าง', seasons: ['summer', 'rainy']),
                            self::item('ชุดนอนอุ่น ๆ แยกจากชุดเดิน', note: 'อย่านอนด้วยชุดที่เดินมาทั้งวัน จะหนาวกว่าเดิม', overnight: true),
                            self::item('ถุงเท้าเดินป่าสำรอง 1-2 คู่', essential: true, trips: ['hiking', 'camping']),
                        ],
                    ],
                    [
                        'key' => 'gear',
                        'title' => 'อุปกรณ์',
                        'items' => [
                            self::item('รองเท้าเดินป่าที่ใส่จนเข้ารูปแล้ว', note: 'ห้ามใส่คู่ใหม่เอี่ยมขึ้นดอย รับรองได้แผล', essential: true, trips: ['hiking', 'camping']),
                            self::item('รองเท้ารัดส้น / รองเท้าลุยน้ำ', trips: ['snorkel']),
                            self::item('เป้สะพายขนาดเหมาะกับจำนวนวัน', essential: true),
                            self::item('ถุงกันน้ำ (dry bag) หรือถุงซิปล็อกใส่ของสำคัญ', essential: true),
                            self::item('ไฟฉายคาดหัว + ถ่านสำรอง', essential: true, trips: ['hiking', 'camping']),
                            self::item('ไม้เท้าเดินป่า (trekking pole)', note: 'ช่วยเข่ามากโดยเฉพาะขาลง', trips: ['hiking']),
                            self::item('พาวเวอร์แบงก์', essential: true),
                            self::item('ขวดน้ำหรือถุงน้ำ ความจุอย่างน้อย 2 ลิตร', essential: true, trips: ['hiking', 'camping']),
                            self::item('ถุงขยะสำหรับเก็บขยะของตัวเองกลับ', note: 'กติกาพื้นฐานของทุกอุทยาน', essential: true),
                            self::item('หน้ากากดำน้ำ + สน็อกเกิล (ถ้ามีของตัวเอง)', trips: ['snorkel']),
                            self::item('ถุงนอน / แผ่นรองนอน', overnight: true, trips: ['camping', 'hiking']),
                            self::item('ผ้าขนหนูแห้งเร็ว', overnight: true),
                        ],
                    ],
                    [
                        'key' => 'health',
                        'title' => 'สุขภาพและความปลอดภัย',
                        'items' => [
                            self::item('ยาสามัญ (แก้ปวด ลดไข้ แก้ท้องเสีย)', essential: true),
                            self::item('พลาสเตอร์ปิดแผล + พลาสเตอร์กันรองเท้ากัด', essential: true),
                            self::item('ครีมกันแดด SPF 50', essential: true),
                            self::item('ครีมกันแดดที่ไม่ทำลายปะการัง', essential: true, trips: ['snorkel']),
                            self::item('ยากันยุง / สเปรย์กันทาก', essential: true, trips: ['hiking', 'camping'], seasons: ['rainy']),
                            self::item('เกลือแร่ผง', note: 'เหนื่อยจนไม่อยากกินข้าว แต่ยังจิบเกลือแร่ได้', trips: ['hiking']),
                            self::item('ยาแก้เมารถ', note: 'ทางขึ้นดอยส่วนใหญ่โค้งเยอะ'),
                            self::item('ลิปมันกันแตก', seasons: ['winter']),
                        ],
                    ],
                    [
                        'key' => 'nice',
                        'title' => 'มีก็ดี',
                        'items' => [
                            self::item('ขนมให้พลังงานสูง', note: 'ถั่ว ช็อกโกแลต หรือกล้วยตาก'),
                            self::item('ผ้าบัฟหรือผ้าอเนกประสงค์'),
                            self::item('กล้อง / ขาตั้งเล็ก'),
                            self::item('ที่อุดหู + ผ้าปิดตา', note: 'ที่พักรวมมักมีคนกรน', overnight: true),
                            self::item('ถุงผ้าใส่เสื้อผ้าเปียกแยกจากของแห้ง'),
                            self::item('สมุดจดเล็ก ๆ'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * ย่อการเขียน default ของรายการเช็คลิสต์ ให้ทุกรายการมีคีย์ครบเท่ากัน
     *
     * @param  array<int, string>  $trips
     * @param  array<int, string>  $seasons
     * @return array<string, mixed>
     */
    private static function item(
        string $label,
        string $note = '',
        bool $essential = false,
        bool $overnight = false,
        array $trips = [],
        array $seasons = [],
    ): array {
        return [
            'label' => $label,
            'note' => $note,
            'essential' => $essential,
            'overnight' => $overnight,
            'trips' => $trips,
            'seasons' => $seasons,
        ];
    }

    /** @return array<string, mixed> */
    private static function difficulty(): array
    {
        return [
            'label' => 'ระดับความยากหมายถึงอะไร',
            'route' => '/difficulty',
            'description' => 'เกณฑ์การจัดระดับทริป สายชิล/ปานกลาง/สายโหด และสิ่งที่ระดับไม่ได้บอก',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'หัวข้อหน้า'],
                ['key' => 'intro', 'type' => 'textarea', 'label' => 'คำโปรย'],
                [
                    'key' => 'levels',
                    'type' => 'repeater',
                    'label' => 'ระดับความยาก',
                    'item_label' => 'ระดับ',
                    'max' => 8,
                    'fields' => [
                        ['key' => 'key', 'type' => 'text', 'label' => 'คีย์'],
                        ['key' => 'emoji', 'type' => 'text', 'label' => 'อีโมจิ'],
                        ['key' => 'title', 'type' => 'text', 'label' => 'ชื่อระดับ'],
                        ['key' => 'range', 'type' => 'text', 'label' => 'ช่วงตัวเลข'],
                        ['key' => 'tone', 'type' => 'select', 'label' => 'โทนสี', 'options' => self::LEVEL_TONES],
                        ['key' => 'description', 'type' => 'textarea', 'label' => 'คำอธิบาย'],
                        ['key' => 'reference', 'type' => 'textarea', 'label' => 'เทียบให้เห็นภาพ'],
                        ['key' => 'suited_for', 'type' => 'textarea', 'label' => 'เหมาะกับใคร'],
                    ],
                ],
                ['key' => 'factors_title', 'type' => 'text', 'label' => 'หัวข้อส่วนเกณฑ์'],
                [
                    'key' => 'factors',
                    'type' => 'repeater',
                    'label' => 'เกณฑ์ที่ใช้ตัดสิน',
                    'item_label' => 'เกณฑ์',
                    'max' => 12,
                    'fields' => [
                        ['key' => 'icon', 'type' => 'icon', 'label' => 'ไอคอน'],
                        ['key' => 'title', 'type' => 'text', 'label' => 'ชื่อเกณฑ์'],
                        ['key' => 'detail', 'type' => 'textarea', 'label' => 'รายละเอียด'],
                    ],
                ],
                ['key' => 'self_check_title', 'type' => 'text', 'label' => 'หัวข้อกล่องเขียวเข้ม'],
                ['key' => 'self_check_body', 'type' => 'textarea', 'label' => 'เนื้อหากล่องเขียวเข้ม'],
                ['key' => 'caveats_title', 'type' => 'text', 'label' => 'หัวข้อส่วนข้อควรรู้'],
                ['key' => 'caveats', 'type' => 'list', 'label' => 'ที่ระดับความยากไม่ได้บอก'],
            ],
            'defaults' => [
                'title' => 'ระดับความยากหมายถึงอะไร',
                'intro' => 'คำว่า "ง่าย" ของแต่ละคนไม่เท่ากัน หน้านี้อธิบายว่าเราใช้เกณฑ์อะไรตัดสิน และคุณจะเทียบกับตัวเองยังไง — ส่งให้เพื่อนที่กำลังลังเลอ่านได้เลย',
                'levels' => [
                    [
                        'key' => 'easy',
                        'emoji' => '🌿',
                        'title' => 'สายชิล',
                        'range' => 'เดินรวมไม่เกิน ~8 กม. · ไต่ขึ้นไม่เกิน ~400 ม.',
                        'tone' => 'easy',
                        'description' => 'ทางเดินชัดเจน ความชันไม่ต่อเนื่อง พักได้บ่อยตามจังหวะตัวเอง จบวันแล้วยังเหลือแรงเที่ยวต่อ',
                        'reference' => 'ประมาณเดินเล่นรอบสวนลุมฯ 3-4 รอบ แต่มีทางขึ้นเนินคั่นเป็นช่วง ๆ',
                        'suited_for' => 'คนที่ไม่เคยเดินป่ามาก่อน ครอบครัวที่มีเด็กโต หรือคนที่อยากลองก่อนตัดสินใจไปหนักกว่านี้',
                    ],
                    [
                        'key' => 'medium',
                        'emoji' => '⛰️',
                        'title' => 'ปานกลาง',
                        'range' => 'เดินรวม ~8-18 กม. · ไต่ขึ้น ~400-1,000 ม.',
                        'tone' => 'medium',
                        'description' => 'มีช่วงชันต่อเนื่องที่ต้องใช้แรงขาจริงจัง อาจต้องแบกของค้างคืนเอง และเดินติดต่อกันหลายชั่วโมงต่อวัน',
                        'reference' => 'เทียบได้กับขึ้นบันไดตึก 100-300 ชั้น กระจายทั้งวัน โดยมีเป้าอยู่บนหลัง',
                        'suited_for' => 'คนที่ออกกำลังกายสม่ำเสมอ หรือเคยจบทริประดับสายชิลมาแล้วอย่างน้อยหนึ่งครั้ง',
                    ],
                    [
                        'key' => 'hard',
                        'emoji' => '🔥',
                        'title' => 'สายโหด',
                        'range' => 'เดินรวมเกิน ~18 กม. · ไต่ขึ้นเกิน ~1,000 ม.',
                        'tone' => 'hard',
                        'description' => 'ชันยาวและต่อเนื่อง บางช่วงต้องใช้มือช่วยปีน อากาศและเวลาบังคับให้เดินต่อแม้เหนื่อย จุดถอยกลางทางมีน้อย',
                        'reference' => 'เทียบได้กับปีนตึกใบหยก 2 สามรอบขึ้นไป ต่อเนื่องภายในหนึ่งถึงสองวัน',
                        'suited_for' => 'คนที่เคยจบทริประดับปานกลางมาแล้ว และซ้อมเดินระยะไกลมาก่อนล่วงหน้าอย่างน้อยหนึ่งเดือน',
                    ],
                ],
                'factors_title' => 'เราดูอะไรบ้างในการจัดระดับ',
                'factors' => [
                    ['icon' => 'trending_up', 'title' => 'ความสูงที่ต้องไต่ (elevation gain)', 'detail' => 'ตัวชี้วัดที่หนักที่สุด — ระยะทางเท่ากันแต่ไต่ต่างกันสองเท่า คือคนละทริปเลย'],
                    ['icon' => 'straighten', 'title' => 'ระยะทางรวมตลอดทริป', 'detail' => 'นับทั้งขาขึ้นและขาลง เพราะขาลงกินแรงเข่ามากกว่าที่หลายคนคิด'],
                    ['icon' => 'schedule', 'title' => 'จำนวนวันและชั่วโมงเดินต่อวัน', 'detail' => 'เดิน 15 กม. ในสองวันกับในวันเดียว ต่างกันมาก เราคิดเวลาพักฟื้นระหว่างวันด้วย'],
                    ['icon' => 'backpack', 'title' => 'ต้องแบกของเองไหม', 'detail' => 'ทริปที่มีลูกหาบขนสัมภาระให้ จะเบากว่าทริปที่ต้องแบกเต็มเป้าเองอย่างชัดเจน'],
                    ['icon' => 'terrain', 'title' => 'สภาพทาง', 'detail' => 'ทางดินอัดแน่นกับทางหินลื่นหรือลุยน้ำ ให้ความรู้สึกเหนื่อยไม่เท่ากันแม้ตัวเลขจะเท่ากัน'],
                ],
                'self_check_title' => 'แล้วจะรู้ได้ยังไงว่าเราไหว',
                'self_check_body' => 'ระดับความยากเป็นค่ากลาง ไม่ได้บอกว่าคุณไหวไหม ในหน้าทริปเราจะเทียบความหนักของทริปนั้นกับสิ่งที่คุณเคยเดินมาจริง แล้วบอกตรง ๆ ว่าสบาย ท้าทาย หรือเกินตัว — และถ้าข้อมูลไม่พอ เราจะบอกว่าไม่พอ ไม่เดาให้',
                'caveats_title' => 'ที่ระดับความยากไม่ได้บอก',
                'caveats' => [
                    'สภาพอากาศเปลี่ยนทุกอย่าง — เส้นทางระดับปานกลางกลายเป็นโหดได้ทันทีเมื่อฝนตกหนัก',
                    'ระดับนี้ไม่ได้ประเมินความสูงจากระดับน้ำทะเล คนที่แพ้ความสูงควรดูตัวเลข "ม. เหนือระดับน้ำทะเล" ในหน้าสถานที่ประกอบ',
                    'โรคประจำตัวและอาการบาดเจ็บเก่าสำคัญกว่าระดับความยาก แจ้งทีมงานไว้ก่อนเสมอ',
                    'ความเร็วของกลุ่มคือความเร็วของคนที่ช้าที่สุด — เลือกระดับที่ทุกคนในกลุ่มไหว ไม่ใช่ที่คนแข็งแรงที่สุดไหว',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function faq(): array
    {
        return [
            'label' => 'คำถามที่พบบ่อย',
            'route' => '/faq',
            'description' => 'คำถาม-คำตอบแยกตามหมวด (ส่งเป็น FAQ schema ให้ Google ด้วย)',
            'fields' => [
                ['key' => 'hero_title', 'type' => 'text', 'label' => 'หัวข้อใหญ่'],
                ['key' => 'hero_subtitle', 'type' => 'textarea', 'label' => 'คำโปรยใต้หัวข้อ'],
                [
                    'key' => 'groups',
                    'type' => 'repeater',
                    'label' => 'หมวดคำถาม',
                    'item_label' => 'หมวด',
                    'max' => 20,
                    'fields' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'ชื่อหมวด'],
                        ['key' => 'tone', 'type' => 'select', 'label' => 'โทนสี', 'options' => self::TONES],
                        [
                            'key' => 'questions',
                            'type' => 'repeater',
                            'label' => 'คำถามในหมวด',
                            'item_label' => 'คำถาม',
                            'max' => 40,
                            'fields' => [
                                ['key' => 'q', 'type' => 'textarea', 'label' => 'คำถาม'],
                                ['key' => 'a', 'type' => 'textarea', 'label' => 'คำตอบ'],
                            ],
                        ],
                    ],
                ],
                ['key' => 'footer_text', 'type' => 'text', 'label' => 'ข้อความก่อนปุ่มติดต่อ'],
                ['key' => 'footer_cta', 'type' => 'text', 'label' => 'ป้ายปุ่มติดต่อ'],
            ],
            'defaults' => [
                'hero_title' => 'คำถามที่พบบ่อย',
                'hero_subtitle' => 'รวบรวมคำตอบสำหรับข้อสงสัย เพื่อให้คุณเตรียมพร้อมสำหรับทริปสุดพิเศษ',
                'groups' => [
                    [
                        'title' => 'การจองและชำระเงิน',
                        'tone' => 'primary',
                        'questions' => [
                            ['q' => 'ต้องชำระเงินมัดจำเท่าไหร่?', 'a' => 'โดยปกติเราให้ชำระเงินเต็มจำนวนเพื่อเป็นการยืนยันที่นั่งและดำเนินการจองที่พัก/ตั๋วเครื่องบินในทันที ยกเว้นทริปพิเศษเป็นกรณีไป'],
                            ['q' => 'สามารถออกใบกำกับภาษีได้หรือไม่?', 'a' => 'ได้ครับ หากท่านจองในนามนิติบุคคล โปรดแจ้งข้อมูลทาง LINE @luilaykhao หลังจากจองสำเร็จ'],
                        ],
                    ],
                    [
                        'title' => 'การเตรียมตัวเดินทาง',
                        'tone' => 'accent',
                        'questions' => [
                            ['q' => 'ต้องเตรียมอุปกรณ์อะไรไปบ้างในทริปเดินป่า?', 'a' => 'แต่ละทริปจะมี "สิ่งที่ต้องเตรียม" ระบุไว้ในหน้ารายละเอียดทริปครับ เบื้องต้นควรมีรองเท้าเดินป่า เสื้อผ้าแห้งไว และขวดน้ำส่วนตัว'],
                            ['q' => 'กรณีแพ้อาหารต้องแจ้งตอนไหน?', 'a' => 'สามารถระบุได้ในขั้นตอนการจองในส่วนของ "หมายเหตุ" หรือแจ้งทีมงานทันทีหลังจากจองสำเร็จครับ'],
                        ],
                    ],
                    [
                        'title' => 'นโยบายการเปลี่ยนแปลง',
                        'tone' => 'gold',
                        'questions' => [
                            ['q' => 'หากติดธุระด่วนสามารถส่งต่อที่นั่งให้เพื่อนได้ไหม?', 'a' => 'สามารถทำได้ครับ แต่ต้องแจ้งทีมงานล่วงหน้าอย่างน้อย 3-7 วันเพื่อเปลี่ยนชื่อผู้ทำประกันและประสานงานจุดนัดพบ'],
                            ['q' => 'ถ้าฝนตกหนักทริปจะยกเลิกไหม?', 'a' => 'เรายึดถือความปลอดภัยเป็นสำคัญ หากสภาพอากาศไม่เอื้ออำนวย ไกด์ผู้เชี่ยวชาญจะประเมินหน้างาน หรือหากจำเป็นต้องยกเลิกทริป เรายินดีคืนเงินเต็มจำนวนครับ'],
                        ],
                    ],
                ],
                'footer_text' => 'หากยังมีข้อสงสัยด้านอื่นๆ สามารถสอบถามเราได้โดยตรง',
                'footer_cta' => 'ติดต่อเจ้าหน้าที่',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function bookingGuide(): array
    {
        return [
            'label' => 'วิธีการจอง',
            'route' => '/booking-guide',
            'description' => 'ขั้นตอนการจองทีละสเต็ป และกล่องช่องทางขอความช่วยเหลือ',
            'fields' => [
                ['key' => 'hero_title', 'type' => 'text', 'label' => 'หัวข้อใหญ่'],
                ['key' => 'hero_subtitle', 'type' => 'textarea', 'label' => 'คำโปรยใต้หัวข้อ'],
                [
                    'key' => 'steps',
                    'type' => 'repeater',
                    'label' => 'ขั้นตอน',
                    'item_label' => 'ขั้นตอน',
                    'hint' => 'เลขลำดับจะรันให้อัตโนมัติตามการเรียง',
                    'max' => 10,
                    'fields' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'ชื่อขั้นตอน'],
                        ['key' => 'detail', 'type' => 'textarea', 'label' => 'รายละเอียด'],
                    ],
                ],
                ['key' => 'help_title', 'type' => 'text', 'label' => 'หัวข้อกล่องช่วยเหลือ'],
                ['key' => 'help_detail', 'type' => 'textarea', 'label' => 'เนื้อหากล่องช่วยเหลือ'],
                ['key' => 'cta_label', 'type' => 'text', 'label' => 'ป้ายปุ่มปิดท้าย'],
            ],
            'defaults' => [
                'hero_title' => 'วิธีการจอง',
                'hero_subtitle' => 'เพียงไม่กี่ขั้นตอนง่ายๆ คุณก็พร้อมออกไปสัมผัสโลกกว้างกับเรา',
                'steps' => [
                    ['title' => 'เลือกทริปที่ต้องการ', 'detail' => 'เข้าชมหน้ารวมกิจกรรม เลือกทริปดำน้ำ เดินป่า หรือรถตู้พรีเมียมที่ท่านสนใจ อ่านรายละเอียดการเดินทางและรอบวันที่สะดวก'],
                    ['title' => 'ตรวจสอบรอบและจองที่นั่ง', 'detail' => 'กดปุ่ม "จองตอนนี้" เลือกวันที่ต้องการ และระบุจำนวนผู้เดินทาง หรือเลือกที่นั่งรถตู้ตามที่ระบบกำหนดไว้'],
                    ['title' => 'ชำระเงินออนไลน์', 'detail' => 'ทำตามขั้นตอนการชำระเงินผ่านระบบอัตโนมัติ ซึ่งรองรับการสแกน QR Code หรือบัตรเครดิต สะดวกรวดเร็วและปลอดภัยระดับสากล'],
                    ['title' => 'รับการยืนยันและออกเดินทาง', 'detail' => 'เมื่อชำระเงินสำเร็จ ท่านจะได้รับ Voucher ยืนยันทางอีเมลและประวัติการจองในบัญชีสมาชิก เพียงเท่านี้ก็เตรียมชุดสวยๆ แล้วไปลุยกันเลย!'],
                ],
                'help_title' => 'ต้องการความช่วยเหลือ?',
                'help_detail' => 'หากติดปัญหาในการจอง สามารถติดต่อสอบถามผ่าน LINE @luilaykhao ได้ตลอด 24 ชม.',
                'cta_label' => 'เริ่มจองทริปแรกของคุณ',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function problem(): array
    {
        return [
            'label' => 'อุปสรรคของการเดินทาง',
            'route' => '/problem',
            'description' => 'ปัญหาที่ลูกค้าเจอและเราอยากแก้ พร้อมบทสรุปปิดท้าย',
            'fields' => [
                ['key' => 'hero_title', 'type' => 'text', 'label' => 'หัวข้อใหญ่'],
                ['key' => 'hero_subtitle', 'type' => 'textarea', 'label' => 'คำโปรยใต้หัวข้อ'],
                ['key' => 'section_eyebrow', 'type' => 'text', 'label' => 'ข้อความเล็กเหนือหัวข้อส่วนปัญหา'],
                ['key' => 'section_title', 'type' => 'text', 'label' => 'หัวข้อส่วนปัญหา'],
                [
                    'key' => 'problems',
                    'type' => 'repeater',
                    'label' => 'ปัญหา',
                    'item_label' => 'ปัญหา',
                    'max' => 20,
                    'fields' => [
                        ['key' => 'icon', 'type' => 'icon', 'label' => 'ไอคอน'],
                        ['key' => 'title', 'type' => 'text', 'label' => 'ชื่อปัญหา'],
                        ['key' => 'desc', 'type' => 'textarea', 'label' => 'คำอธิบาย'],
                        ['key' => 'color', 'type' => 'color', 'label' => 'สีไอคอน'],
                    ],
                ],
                ['key' => 'quote_eyebrow', 'type' => 'text', 'label' => 'ข้อความเล็กเหนือบทสรุป'],
                ['key' => 'quote', 'type' => 'textarea', 'label' => 'บทสรุป'],
                ['key' => 'cta_label', 'type' => 'text', 'label' => 'ป้ายปุ่มปิดท้าย'],
            ],
            'defaults' => [
                'hero_title' => 'อุปสรรคของการเดินทาง',
                'hero_subtitle' => 'สิ่งที่เราอยากแก้ไข เพื่อให้ทุกคนได้ออกไปเที่ยวอย่างแท้จริง',
                'section_eyebrow' => 'ปัญหาที่พบเจอและอยากแก้ไข',
                'section_title' => 'ปัญหาที่หลายคนต้องเจอ',
                'problems' => [
                    ['icon' => 'chat', 'title' => 'การสอบถามที่ยุ่งยาก', 'desc' => 'ต้องทัก LINE / Facebook เพื่อสอบถาม', 'color' => '#007B8F'],
                    ['icon' => 'hourglass_empty', 'title' => 'รอการตอบกลับนาน', 'desc' => 'รอแอดมินตอบ (บางครั้งนาน)', 'color' => '#C8963E'],
                    ['icon' => 'event_busy', 'title' => 'สถานะทริปไม่ชัดเจน', 'desc' => 'ไม่รู้ว่าทริปว่างหรือเต็ม', 'color' => '#E53935'],
                    ['icon' => 'category', 'title' => 'ข้อมูลไม่รวมศูนย์', 'desc' => 'ข้อมูลกระจัดกระจาย', 'color' => '#66C291'],
                    ['icon' => 'airline_seat_recline_extra', 'title' => 'ปัญหาการจองที่นั่ง', 'desc' => 'จองที่นั่งได้ตามไม่ต้องการ (จองก่อนได้นั่งข้างหลัง การจองค่อนข้างยุ่งยาก)', 'color' => '#4CAF7D'],
                    ['icon' => 'calendar_today', 'title' => 'ข้อจำกัดเรื่องเวลา', 'desc' => 'อยากไป "วันนี้" แต่หาทริปไม่ได้', 'color' => '#FF9800'],
                    ['icon' => 'directions_bus', 'title' => 'จุดขึ้นรถไม่สะดวก', 'desc' => 'อยู่ต่างจังหวัดแต่ต้องไปขึ้นรถที่กรุงเทพ', 'color' => '#9C27B0'],
                ],
                'quote_eyebrow' => 'บทสรุปของปัญหา',
                'quote' => '"ลูกค้าอยากไป…แต่ไปไม่ได้ เพราะระบบยังไม่เอื้อ"',
                'cta_label' => 'ดูเป้าหมายของเรา',
            ],
        ];
    }
}
