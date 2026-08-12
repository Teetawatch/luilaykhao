<?php

namespace App\Support;

/**
 * ประเทศปลายทาง — ชื่อไทย ธง และเขตเวลา ไว้ที่เดียว
 *
 * ทะเบียนนี้ตั้งใจให้สั้น ไม่ใช่รายชื่อ 200 ประเทศ: เก็บเฉพาะปลายทางที่เรา
 * พาไปจริงหรือใกล้จะพาไป เพราะทุกชื่อในนี้ต้องแปลไทยให้ถูกและตรวจแล้ว
 * ประเทศใหม่เพิ่มได้ที่นี่ที่เดียว แล้วทั้งเว็บ แอป และแอดมินเห็นพร้อมกัน
 *
 * รหัสเป็น ISO 3166-1 alpha-2 ตัวใหญ่เสมอ
 */
class Countries
{
    /** code => [ชื่อไทย, ธง, เขตเวลา IANA] */
    private const LIST = [
        'TH' => ['ไทย', '🇹🇭', 'Asia/Bangkok'],
        'LA' => ['ลาว', '🇱🇦', 'Asia/Vientiane'],
        'VN' => ['เวียดนาม', '🇻🇳', 'Asia/Ho_Chi_Minh'],
        'KH' => ['กัมพูชา', '🇰🇭', 'Asia/Phnom_Penh'],
        'MM' => ['เมียนมา', '🇲🇲', 'Asia/Yangon'],
        'MY' => ['มาเลเซีย', '🇲🇾', 'Asia/Kuala_Lumpur'],
        'SG' => ['สิงคโปร์', '🇸🇬', 'Asia/Singapore'],
        'ID' => ['อินโดนีเซีย', '🇮🇩', 'Asia/Jakarta'],
        'PH' => ['ฟิลิปปินส์', '🇵🇭', 'Asia/Manila'],
        'NP' => ['เนปาล', '🇳🇵', 'Asia/Kathmandu'],
        'IN' => ['อินเดีย', '🇮🇳', 'Asia/Kolkata'],
        'BT' => ['ภูฏาน', '🇧🇹', 'Asia/Thimphu'],
        'LK' => ['ศรีลังกา', '🇱🇰', 'Asia/Colombo'],
        'CN' => ['จีน', '🇨🇳', 'Asia/Shanghai'],
        'TW' => ['ไต้หวัน', '🇹🇼', 'Asia/Taipei'],
        'HK' => ['ฮ่องกง', '🇭🇰', 'Asia/Hong_Kong'],
        'JP' => ['ญี่ปุ่น', '🇯🇵', 'Asia/Tokyo'],
        'KR' => ['เกาหลีใต้', '🇰🇷', 'Asia/Seoul'],
        'KG' => ['คีร์กีซสถาน', '🇰🇬', 'Asia/Bishkek'],
        'GE' => ['จอร์เจีย', '🇬🇪', 'Asia/Tbilisi'],
        'TR' => ['ตุรกี', '🇹🇷', 'Europe/Istanbul'],
        'AU' => ['ออสเตรเลีย', '🇦🇺', 'Australia/Sydney'],
        'NZ' => ['นิวซีแลนด์', '🇳🇿', 'Pacific/Auckland'],
        'CH' => ['สวิตเซอร์แลนด์', '🇨🇭', 'Europe/Zurich'],
        'FR' => ['ฝรั่งเศส', '🇫🇷', 'Europe/Paris'],
        'IT' => ['อิตาลี', '🇮🇹', 'Europe/Rome'],
        'IS' => ['ไอซ์แลนด์', '🇮🇸', 'Atlantic/Reykjavik'],
        'NO' => ['นอร์เวย์', '🇳🇴', 'Europe/Oslo'],
        'GB' => ['สหราชอาณาจักร', '🇬🇧', 'Europe/London'],
        'US' => ['สหรัฐอเมริกา', '🇺🇸', 'America/New_York'],
        'PE' => ['เปรู', '🇵🇪', 'America/Lima'],
        'TZ' => ['แทนซาเนีย', '🇹🇿', 'Africa/Dar_es_Salaam'],
    ];

    /** รหัสประเทศของเราเอง — จุดอ้างอิงว่าอะไรคือ "ในประเทศ" */
    public const HOME = 'TH';

    /** ชื่อไทยของประเทศ; คืน null เมื่อไม่รู้จักรหัสนี้ */
    public static function name(?string $code): ?string
    {
        return self::entry($code)[0] ?? null;
    }

    /** ธงประจำชาติเป็น emoji; คืนสตริงว่างเมื่อไม่รู้จัก จะได้ต่อสตริงได้เลย */
    public static function flag(?string $code): string
    {
        return self::entry($code)[1] ?? '';
    }

    /** เขตเวลา IANA ของประเทศ; คืน null เมื่อไม่รู้จัก */
    public static function timezone(?string $code): ?string
    {
        return self::entry($code)[2] ?? null;
    }

    /** "🇳🇵 เนปาล" — รูปที่เอาไปแสดงได้เลย; คืน null เมื่อไม่รู้จัก */
    public static function label(?string $code): ?string
    {
        $entry = self::entry($code);
        if (! $entry) {
            return null;
        }

        return trim($entry[1].' '.$entry[0]);
    }

    public static function exists(?string $code): bool
    {
        return self::entry($code) !== null;
    }

    /** ทุกประเทศเรียงตามชื่อไทย — ใช้เติม dropdown ฝั่งแอดมิน */
    public static function options(): array
    {
        $options = [];
        foreach (self::LIST as $code => [$name, $flag, $timezone]) {
            $options[] = [
                'code' => $code,
                'name' => $name,
                'flag' => $flag,
                'timezone' => $timezone,
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
