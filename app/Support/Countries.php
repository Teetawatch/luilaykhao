<?php

namespace App\Support;

/**
 * ประเทศปลายทาง — ชื่อไทย ธง เขตเวลา วีซ่า และเบอร์ฉุกเฉิน ไว้ที่เดียว
 *
 * ทะเบียนนี้ตั้งใจให้สั้น ไม่ใช่รายชื่อ 200 ประเทศ: เก็บเฉพาะปลายทางที่เรา
 * พาไปจริงหรือใกล้จะพาไป เพราะทุกชื่อในนี้ต้องแปลไทยให้ถูกและตรวจแล้ว
 * ประเทศใหม่เพิ่มได้ที่นี่ที่เดียว แล้วทั้งเว็บ แอป และแอดมินเห็นพร้อมกัน
 *
 * รหัสเป็น ISO 3166-1 alpha-2 ตัวใหญ่เสมอ
 *
 * แต่ละแถวมีคีย์:
 *   name / flag / timezone  ชื่อไทย, ธง emoji, เขตเวลา IANA
 *   visa                    หมวดวีซ่า **สำหรับผู้ถือพาสปอร์ตไทย** (ดู VISA_*)
 *   visa_days               พำนักได้กี่วันโดยไม่ต้องขอวีซ่า (null = ไม่เกี่ยว)
 *   visa_note               รายละเอียดสั้น ๆ ภาษาไทย
 *   emergency               เบอร์ฉุกเฉินท้องถิ่น [ป้าย => เบอร์]
 *
 * ⚠️ ข้อมูลวีซ่าเปลี่ยนได้ตลอดและเปลี่ยนบ่อย — ทุกที่ที่แสดงต้องขึ้นวันที่ตรวจ
 * (VISA_CHECKED_AT) พร้อมข้อความให้ยืนยันกับสถานทูตก่อนเดินทางเสมอ ทีมงานต้อง
 * ทบทวนแถวเหล่านี้ก่อนเปิดขายปลายทางใหม่ทุกครั้ง อย่าถือว่าเป็นคำตอบสุดท้าย
 *
 * ส่วนเบอร์ฉุกเฉินเป็นเลขสามหลักของรัฐที่ไม่ค่อยเปลี่ยน จึงเชื่อถือได้กว่า —
 * และเดินทางไปกับ payload ของทริป ซึ่งแอปแคชไว้อ่านได้ตอนไม่มีสัญญาณอยู่แล้ว
 * (ดู TripDayPack) จึงไม่ต้องมีทะเบียนซ้ำฝั่ง Dart ให้หลุดจากกันภายหลัง
 */
class Countries
{
    /** ไม่ต้องขอวีซ่าล่วงหน้า */
    public const VISA_FREE = 'free';

    /** ขอที่ด่านตรวจคนเข้าเมืองปลายทางได้ */
    public const VISA_ON_ARRIVAL = 'on_arrival';

    /** ต้องยื่นออนไลน์ก่อนบิน (e-Visa / ETA / K-ETA) */
    public const VISA_ONLINE = 'online';

    /** ต้องยื่นที่สถานทูต/ศูนย์รับคำร้องก่อนเดินทาง */
    public const VISA_REQUIRED = 'required';

    /** วันที่ทีมงานทบทวนข้อมูลวีซ่าในไฟล์นี้ครั้งล่าสุด */
    public const VISA_CHECKED_AT = '2026-08-17';

    public const VISA_DISCLAIMER = 'ข้อมูลวีซ่าเปลี่ยนได้ตลอด กรุณายืนยันกับสถานทูตหรือทีมงานอีกครั้งก่อนเดินทาง';

    private const LIST = [
        'TH' => [
            'name' => 'ไทย', 'flag' => '🇹🇭', 'timezone' => 'Asia/Bangkok',
            'visa' => self::VISA_FREE, 'visa_days' => null, 'visa_note' => 'ประเทศของเราเอง',
            'emergency' => ['ตำรวจ' => '191', 'การแพทย์ฉุกเฉิน' => '1669', 'ตำรวจท่องเที่ยว' => '1155'],
        ],
        'LA' => [
            'name' => 'ลาว', 'flag' => '🇱🇦', 'timezone' => 'Asia/Vientiane',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า',
            'emergency' => ['ตำรวจ' => '191', 'รถพยาบาล' => '195'],
        ],
        'VN' => [
            'name' => 'เวียดนาม', 'flag' => '🇻🇳', 'timezone' => 'Asia/Ho_Chi_Minh',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า',
            'emergency' => ['ตำรวจ' => '113', 'รถพยาบาล' => '115', 'ดับเพลิง' => '114'],
        ],
        'KH' => [
            'name' => 'กัมพูชา', 'flag' => '🇰🇭', 'timezone' => 'Asia/Phnom_Penh',
            'visa' => self::VISA_FREE, 'visa_days' => 14, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า',
            'emergency' => ['ตำรวจ' => '117', 'รถพยาบาล' => '119'],
        ],
        'MM' => [
            'name' => 'เมียนมา', 'flag' => '🇲🇲', 'timezone' => 'Asia/Yangon',
            'visa' => self::VISA_ONLINE, 'visa_days' => null, 'visa_note' => 'ยื่น e-Visa ออนไลน์ก่อนเดินทาง',
            'emergency' => ['ตำรวจ' => '199', 'รถพยาบาล' => '192'],
        ],
        'MY' => [
            'name' => 'มาเลเซีย', 'flag' => '🇲🇾', 'timezone' => 'Asia/Kuala_Lumpur',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า (ต้องลงทะเบียน MDAC ออนไลน์ก่อน)',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '999'],
        ],
        'SG' => [
            'name' => 'สิงคโปร์', 'flag' => '🇸🇬', 'timezone' => 'Asia/Singapore',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า (ต้องยื่น SG Arrival Card ก่อนถึง)',
            'emergency' => ['ตำรวจ' => '999', 'รถพยาบาล' => '995'],
        ],
        'ID' => [
            'name' => 'อินโดนีเซีย', 'flag' => '🇮🇩', 'timezone' => 'Asia/Jakarta',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า',
            'emergency' => ['ตำรวจ' => '110', 'รถพยาบาล' => '119'],
        ],
        'PH' => [
            'name' => 'ฟิลิปปินส์', 'flag' => '🇵🇭', 'timezone' => 'Asia/Manila',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '911'],
        ],
        'NP' => [
            'name' => 'เนปาล', 'flag' => '🇳🇵', 'timezone' => 'Asia/Kathmandu',
            'visa' => self::VISA_ON_ARRIVAL, 'visa_days' => null, 'visa_note' => 'ขอวีซ่าที่สนามบินกาฐมาณฑุได้ (เตรียมเงินสด USD + รูปถ่าย)',
            'emergency' => ['ตำรวจ' => '100', 'รถพยาบาล' => '102'],
        ],
        'IN' => [
            'name' => 'อินเดีย', 'flag' => '🇮🇳', 'timezone' => 'Asia/Kolkata',
            'visa' => self::VISA_ONLINE, 'visa_days' => null, 'visa_note' => 'ต้องยื่น e-Visa ออนไลน์ล่วงหน้า',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '112'],
        ],
        'BT' => [
            'name' => 'ภูฏาน', 'flag' => '🇧🇹', 'timezone' => 'Asia/Thimphu',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องมีวีซ่าและค่าธรรมเนียมพัฒนาการท่องเที่ยวรายวัน ทีมงานยื่นให้',
            'emergency' => ['ตำรวจ' => '113', 'รถพยาบาล' => '112'],
        ],
        'LK' => [
            'name' => 'ศรีลังกา', 'flag' => '🇱🇰', 'timezone' => 'Asia/Colombo',
            'visa' => self::VISA_ONLINE, 'visa_days' => null, 'visa_note' => 'ต้องยื่น ETA ออนไลน์ก่อนเดินทาง',
            'emergency' => ['ตำรวจ' => '119', 'รถพยาบาล' => '110'],
        ],
        'CN' => [
            'name' => 'จีน', 'flag' => '🇨🇳', 'timezone' => 'Asia/Shanghai',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'ไทย-จีนยกเว้นวีซ่าให้กันแล้ว',
            'emergency' => ['ตำรวจ' => '110', 'รถพยาบาล' => '120'],
        ],
        'TW' => [
            'name' => 'ไต้หวัน', 'flag' => '🇹🇼', 'timezone' => 'Asia/Taipei',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่าตามมาตรการชั่วคราว',
            'emergency' => ['ตำรวจ' => '110', 'ดับเพลิง/รถพยาบาล' => '119'],
        ],
        'HK' => [
            'name' => 'ฮ่องกง', 'flag' => '🇭🇰', 'timezone' => 'Asia/Hong_Kong',
            'visa' => self::VISA_FREE, 'visa_days' => 30, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '999'],
        ],
        'JP' => [
            'name' => 'ญี่ปุ่น', 'flag' => '🇯🇵', 'timezone' => 'Asia/Tokyo',
            'visa' => self::VISA_FREE, 'visa_days' => 15, 'visa_note' => 'คนไทยได้รับการยกเว้นวีซ่า พำนักได้ไม่เกิน 15 วัน',
            'emergency' => ['ตำรวจ' => '110', 'ดับเพลิง/รถพยาบาล' => '119'],
        ],
        'KR' => [
            'name' => 'เกาหลีใต้', 'flag' => '🇰🇷', 'timezone' => 'Asia/Seoul',
            'visa' => self::VISA_ONLINE, 'visa_days' => 90, 'visa_note' => 'ยกเว้นวีซ่าแต่ต้องขอ K-ETA ออนไลน์ก่อนบิน',
            'emergency' => ['ตำรวจ' => '112', 'ดับเพลิง/รถพยาบาล' => '119'],
        ],
        'KG' => [
            'name' => 'คีร์กีซสถาน', 'flag' => '🇰🇬', 'timezone' => 'Asia/Bishkek',
            'visa' => self::VISA_ONLINE, 'visa_days' => null, 'visa_note' => 'ต้องยื่น e-Visa ออนไลน์ก่อนเดินทาง',
            'emergency' => ['ตำรวจ' => '102', 'รถพยาบาล' => '103'],
        ],
        'GE' => [
            'name' => 'จอร์เจีย', 'flag' => '🇬🇪', 'timezone' => 'Asia/Tbilisi',
            'visa' => self::VISA_FREE, 'visa_days' => 365, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่า',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '112'],
        ],
        'TR' => [
            'name' => 'ตุรกี', 'flag' => '🇹🇷', 'timezone' => 'Europe/Istanbul',
            'visa' => self::VISA_ONLINE, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่า ยื่น e-Visa ออนไลน์ได้ตามเงื่อนไข',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '112'],
        ],
        'AU' => [
            'name' => 'ออสเตรเลีย', 'flag' => '🇦🇺', 'timezone' => 'Australia/Sydney',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่าท่องเที่ยวล่วงหน้า ใช้เวลาพิจารณาหลายสัปดาห์',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '000'],
        ],
        'NZ' => [
            'name' => 'นิวซีแลนด์', 'flag' => '🇳🇿', 'timezone' => 'Pacific/Auckland',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่าท่องเที่ยวล่วงหน้า',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '111'],
        ],
        'CH' => [
            'name' => 'สวิตเซอร์แลนด์', 'flag' => '🇨🇭', 'timezone' => 'Europe/Zurich',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่าเชงเก้นล่วงหน้า และต้องมีประกันการเดินทาง',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '112', 'ตำรวจ' => '117', 'รถพยาบาล' => '144'],
        ],
        'FR' => [
            'name' => 'ฝรั่งเศส', 'flag' => '🇫🇷', 'timezone' => 'Europe/Paris',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่าเชงเก้นล่วงหน้า และต้องมีประกันการเดินทาง',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '112', 'ตำรวจ' => '17', 'รถพยาบาล' => '15'],
        ],
        'IT' => [
            'name' => 'อิตาลี', 'flag' => '🇮🇹', 'timezone' => 'Europe/Rome',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่าเชงเก้นล่วงหน้า และต้องมีประกันการเดินทาง',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '112'],
        ],
        'IS' => [
            'name' => 'ไอซ์แลนด์', 'flag' => '🇮🇸', 'timezone' => 'Atlantic/Reykjavik',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่าเชงเก้นล่วงหน้า และต้องมีประกันการเดินทาง',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '112'],
        ],
        'NO' => [
            'name' => 'นอร์เวย์', 'flag' => '🇳🇴', 'timezone' => 'Europe/Oslo',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่าเชงเก้นล่วงหน้า และต้องมีประกันการเดินทาง',
            'emergency' => ['ตำรวจ' => '112', 'รถพยาบาล' => '113', 'ดับเพลิง' => '110'],
        ],
        'GB' => [
            'name' => 'สหราชอาณาจักร', 'flag' => '🇬🇧', 'timezone' => 'Europe/London',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่าล่วงหน้า (คนละใบกับเชงเก้น)',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '999'],
        ],
        'US' => [
            'name' => 'สหรัฐอเมริกา', 'flag' => '🇺🇸', 'timezone' => 'America/New_York',
            'visa' => self::VISA_REQUIRED, 'visa_days' => null, 'visa_note' => 'ต้องขอวีซ่า B1/B2 และเข้าสัมภาษณ์ที่สถานทูต จองคิวล่วงหน้านานมาก',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '911'],
        ],
        'PE' => [
            'name' => 'เปรู', 'flag' => '🇵🇪', 'timezone' => 'America/Lima',
            'visa' => self::VISA_FREE, 'visa_days' => 183, 'visa_note' => 'คนไทยเข้าได้โดยไม่ต้องขอวีซ่าเพื่อการท่องเที่ยว',
            'emergency' => ['ตำรวจ' => '105', 'รถพยาบาล' => '106'],
        ],
        'TZ' => [
            'name' => 'แทนซาเนีย', 'flag' => '🇹🇿', 'timezone' => 'Africa/Dar_es_Salaam',
            'visa' => self::VISA_ONLINE, 'visa_days' => null, 'visa_note' => 'ต้องยื่น e-Visa ออนไลน์ก่อนเดินทาง',
            'emergency' => ['ฉุกเฉินทุกกรณี' => '112', 'ตำรวจ' => '111'],
        ],
    ];

    /** ป้ายไทยของหมวดวีซ่า — ใช้ติดชิปบนการ์ด */
    private const VISA_LABELS = [
        self::VISA_FREE => 'ไม่ต้องขอวีซ่า',
        self::VISA_ON_ARRIVAL => 'ขอวีซ่าที่ปลายทาง',
        self::VISA_ONLINE => 'ยื่นออนไลน์ก่อนบิน',
        self::VISA_REQUIRED => 'ต้องขอวีซ่าล่วงหน้า',
    ];

    /** รหัสประเทศของเราเอง — จุดอ้างอิงว่าอะไรคือ "ในประเทศ" */
    public const HOME = 'TH';

    /** ชื่อไทยของประเทศ; คืน null เมื่อไม่รู้จักรหัสนี้ */
    public static function name(?string $code): ?string
    {
        return self::entry($code)['name'] ?? null;
    }

    /** ธงประจำชาติเป็น emoji; คืนสตริงว่างเมื่อไม่รู้จัก จะได้ต่อสตริงได้เลย */
    public static function flag(?string $code): string
    {
        return self::entry($code)['flag'] ?? '';
    }

    /** เขตเวลา IANA ของประเทศ; คืน null เมื่อไม่รู้จัก */
    public static function timezone(?string $code): ?string
    {
        return self::entry($code)['timezone'] ?? null;
    }

    /** "🇳🇵 เนปาล" — รูปที่เอาไปแสดงได้เลย; คืน null เมื่อไม่รู้จัก */
    public static function label(?string $code): ?string
    {
        $entry = self::entry($code);
        if (! $entry) {
            return null;
        }

        return trim($entry['flag'].' '.$entry['name']);
    }

    public static function exists(?string $code): bool
    {
        return self::entry($code) !== null;
    }

    /**
     * ข้อมูลวีซ่าของผู้ถือพาสปอร์ตไทย พร้อมวันที่ตรวจและข้อความกำกับ
     *
     * ส่ง disclaimer มาด้วยทุกครั้งโดยตั้งใจ — ทุกหน้าจอที่แสดงเรื่องวีซ่าต้องขึ้น
     * ข้อความนี้ ไม่ใช่เลือกได้ เพราะกฎเปลี่ยนเร็วกว่ารอบปล่อยแอปของเรา
     *
     * @return array<string, mixed>|null
     */
    public static function visa(?string $code): ?array
    {
        $entry = self::entry($code);
        if (! $entry) {
            return null;
        }

        return [
            'status' => $entry['visa'],
            'label' => self::VISA_LABELS[$entry['visa']] ?? null,
            'days' => $entry['visa_days'],
            'note' => $entry['visa_note'],
            'checked_at' => self::VISA_CHECKED_AT,
            'disclaimer' => self::VISA_DISCLAIMER,
        ];
    }

    /**
     * เบอร์ฉุกเฉินท้องถิ่น [ป้าย => เบอร์] — ว่างเมื่อไม่รู้จักประเทศ
     *
     * 191/1669 ของไทยใช้ที่ต่างประเทศไม่ได้ และนี่เป็นข้อมูลที่ต้องอ่านได้ตอน
     * ไม่มีสัญญาณเน็ต (โรมมิ่งไม่ได้เปิดกันทุกคน) จึงต้องเดินทางไปกับแอปตั้งแต่
     * ตอนบิลด์ ไม่ใช่รอโหลดจาก API ตอนเกิดเหตุ
     *
     * @return array<string, string>
     */
    public static function emergency(?string $code): array
    {
        return self::entry($code)['emergency'] ?? [];
    }

    /** ทุกประเทศเรียงตามชื่อไทย — ใช้เติม dropdown ฝั่งแอดมิน */
    public static function options(): array
    {
        $options = [];
        foreach (self::LIST as $code => $entry) {
            $options[] = [
                'code' => $code,
                'name' => $entry['name'],
                'flag' => $entry['flag'],
                'timezone' => $entry['timezone'],
                'visa' => self::visa($code),
                'emergency' => $entry['emergency'],
            ];
        }

        usort($options, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $options;
    }

    private static function entry(?string $code): ?array
    {
        if (! $code) {
            return null;
        }

        return self::LIST[strtoupper($code)] ?? null;
    }
}
