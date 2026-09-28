<?php

namespace App\Support;

use App\Models\Trip;
use Illuminate\Support\Str;

/**
 * หน้าตาเหรียญพิชิตของทริป — แหล่งเดียวที่ตัดสินว่าเหรียญของทริปนี้ชื่ออะไร
 * ไอคอนอะไร สีอะไร และมีภาพที่ออกแบบเองหรือไม่
 *
 * "แบบผสม": ทุกทริปได้เหรียญแม่แบบทันทีโดยไม่ต้องตั้งค่าอะไรเลย (ชื่อตัดมาจาก
 * ชื่อทริป ไอคอนกับสีเดาจากประเภททริป) แอดมินปรับทีละช่องได้ และถ้าอัปโหลด
 * medal_image ไว้ ภาพนั้นจะแทนเหรียญแม่แบบทั้งดวง — ใช้กับทริปเด่นที่มีคน
 * ออกแบบเหรียญให้จริง ๆ
 *
 * ไอคอนเก็บเป็นชื่อ Material Symbols เพราะมีชื่อเดียวกันทั้งใน Flutter (Icons.*)
 * หน้าแอดมิน และหน้าเว็บสาธารณะ — วาดตรงกันทุกที่โดยไม่ต้องแปลงชื่อ
 */
class MedalDesign
{
    /** ชื่อบนเหรียญยาวเกินนี้จะล้นดวงเหรียญ */
    public const NAME_MAX = 40;

    /**
     * ไอคอนที่เลือกได้: ชื่อ Material Symbols => คำอธิบายสำหรับแอดมิน
     * เพิ่มรายการใหม่ได้ แต่ต้องมีชื่อเดียวกันใน Flutter ด้วย (medal_art.dart)
     */
    public const ICONS = [
        'hiking' => 'เดินป่า',
        'landscape' => 'ภูเขา',
        'terrain' => 'ภูผา',
        'flag' => 'ปักธงยอดเขา',
        'forest' => 'ผืนป่า',
        'water' => 'น้ำตก / ลำธาร',
        'coffee' => 'ไร่กาแฟ',
        'local_fire_department' => 'แคมป์ไฟ',
        'wb_sunny' => 'พระอาทิตย์ขึ้น / ทะเลหมอก',
        'waves' => 'ทะเล',
        'scuba_diving' => 'ดำน้ำ',
        'kayaking' => 'พายเรือ',
        'ac_unit' => 'หิมะ',
        'temple_buddhist' => 'วัด / วัฒนธรรม',
    ];

    /** จานสีสำเร็จรูปในหน้าแอดมิน — กรอกรหัสสีอื่นเองได้ */
    public const COLORS = [
        '#15803D' => 'เขียวป่า',
        '#047857' => 'มรกต',
        '#0369A1' => 'น้ำทะเล',
        '#1D4ED8' => 'น้ำเงิน',
        '#7C4A2D' => 'กาแฟ',
        '#C2410C' => 'พระอาทิตย์ตก',
        '#B91C1C' => 'แดงชาด',
        '#7E22CE' => 'ม่วง',
        '#B45309' => 'ทองแดง',
        '#334155' => 'หินชนวน',
    ];

    /** ไอคอนตั้งต้นตามประเภททริป (slug ของหมวด) เมื่อแอดมินไม่ได้เลือก */
    public const TYPE_ICONS = [
        'trekking' => 'hiking',
        'hiking' => 'hiking',
        'diving' => 'scuba_diving',
        'snorkeling' => 'waves',
        'kayaking' => 'kayaking',
        'rafting' => 'kayaking',
        'camping' => 'local_fire_department',
    ];

    public const DEFAULT_ICON = 'landscape';

    /** สีตั้งต้นตามประเภททริป — สายน้ำเป็นสีน้ำทะเล ที่เหลือเป็นเขียวป่า */
    public const TYPE_COLORS = [
        'diving' => '#0369A1',
        'snorkeling' => '#0369A1',
        'kayaking' => '#0369A1',
        'rafting' => '#0369A1',
    ];

    public const DEFAULT_COLOR = '#15803D';

    /**
     * @return array{name: string, icon: string, color: string, image_url: ?string, is_custom: bool}
     */
    public static function forTrip(Trip $trip): array
    {
        $image = MediaDisk::url($trip->medal_image);

        return [
            'name' => self::name($trip),
            'icon' => self::icon($trip),
            'color' => self::color($trip),
            'image_url' => $image,
            'is_custom' => $image !== null,
        ];
    }

    public static function name(Trip $trip): string
    {
        $custom = trim((string) $trip->medal_name);

        if ($custom !== '') {
            return Str::limit($custom, self::NAME_MAX, '');
        }

        return self::nameFromTitle((string) $trip->title);
    }

    /**
     * "เดินป่าดอยอินทนนท์ 2 วัน 1 คืน" → "เดินป่าดอยอินทนนท์"
     *
     * ชื่อทริปเกือบทุกชื่อลงท้ายด้วยระยะเวลา ซึ่งเป็นข้อมูลการขาย ไม่ใช่ชื่อของ
     * สิ่งที่พิชิต จึงตัดออก ตัดแล้วว่าง (ชื่อทริปมีแต่ระยะเวลา) ก็ใช้ชื่อเต็มแทน
     */
    public static function nameFromTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        $trimmed = preg_replace(
            '/[\s\-–(]*\d+\s*วัน(\s*\d+\s*คืน)?\)?\s*$/u',
            '',
            $title,
        ) ?? $title;

        $trimmed = trim($trimmed);
        $name = $trimmed !== '' ? $trimmed : $title;

        return Str::limit($name, self::NAME_MAX, '');
    }

    public static function icon(Trip $trip): string
    {
        $icon = (string) $trip->medal_icon;

        if (array_key_exists($icon, self::ICONS)) {
            return $icon;
        }

        return self::TYPE_ICONS[(string) $trip->type] ?? self::DEFAULT_ICON;
    }

    public static function color(Trip $trip): string
    {
        $color = self::normalizeColor($trip->medal_color);

        if ($color !== null) {
            return $color;
        }

        return self::TYPE_COLORS[(string) $trip->type] ?? self::DEFAULT_COLOR;
    }

    /** "#15803d" / "15803D" → "#15803D"; คืน null เมื่อไม่ใช่รหัสสี 6 หลัก */
    public static function normalizeColor(?string $color): ?string
    {
        $color = strtoupper(ltrim(trim((string) $color), '#'));

        return preg_match('/^[0-9A-F]{6}$/', $color) === 1 ? '#'.$color : null;
    }

    /**
     * ตัวเลือกสำหรับฟอร์มแอดมิน
     *
     * ค่าตั้งต้นส่งไปด้วย เพื่อให้พรีวิวในฟอร์มตรงกับที่แอปวาดจริงโดยไม่ต้อง
     * ก๊อปตารางนี้ไปไว้ฝั่ง JS
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'defaults' => [
                'type_icons' => self::TYPE_ICONS,
                'icon' => self::DEFAULT_ICON,
                'type_colors' => self::TYPE_COLORS,
                'color' => self::DEFAULT_COLOR,
            ],
            'icons' => collect(self::ICONS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'colors' => collect(self::COLORS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ];
    }
}
