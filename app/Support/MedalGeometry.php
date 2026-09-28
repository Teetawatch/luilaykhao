<?php

namespace App\Support;

/**
 * รูปทรงของเหรียญแม่แบบ — แหล่งเดียวของตัวเลขที่ทุกที่ใช้วาดเหรียญดวงเดียวกัน
 *
 * หน่วยเป็นพิกัดบนผืน 100 × 118 (กว้าง × สูงรวมริบบิ้น) จุดศูนย์กลางดวงอยู่ที่
 * (50, 68) ผู้ใช้แต่ละที่แค่คูณสเกลเอง:
 *  - เว็บ (partials/medal-art.blade.php) — SVG viewBox 0 0 100 118
 *  - พรีวิวในหน้าแก้ทริป (components/MedalArt.vue) — รับชิ้นที่คำนวณ (ริบบิ้น/ขอบหยัก/
 *    ช่อใบไม้/ป้าย) ผ่าน /admin/medal-options แต่ **คัดลอกรัศมีและตำแหน่งไว้เอง**
 *  - ภาพ OG (MedalImageService) — GD
 *  - แอป (lib/widgets/medal_art.dart) — **คัดลอกตัวเลขชุดเดียวกันไว้ในภาษา Dart**
 *    แก้ตรงนี้แล้วต้องแก้ที่นั่นด้วย
 *
 * ชั้นจากล่างขึ้นบน: ริบบิ้น → ขอบหยักทอง → แถบทองเข้ม (มีตัวอักษรวิ่งรอบ) →
 * เส้นทองบาง → ดวงสีทริป → ช่อใบไม้ → ไอคอน + ชื่อ → แถบป้าย FINISHER
 */
class MedalGeometry
{
    public const WIDTH = 100;

    public const HEIGHT = 118;

    public const CX = 50;

    public const CY = 68;

    /** ขอบหยัก: วงฐาน + วงเล็กรอบ ๆ */
    public const ROSETTE_R = 44;

    public const SCALLOPS = 28;

    public const SCALLOP_R = 5.6;

    /** แถบทองเข้มที่ตัวอักษรวิ่งรอบ */
    public const BAND_R = 41;

    /** เส้นทองบางรอบดวงสี */
    public const RIM_R = 35.5;

    /** ดวงสีของทริป */
    public const DISC_R = 34;

    /** รัศมีเส้นกึ่งกลางของตัวอักษรที่วิ่งรอบ */
    public const RING_TEXT_R = 38.2;

    public const RING_TEXT_SIZE = 4.2;

    /** ริบบิ้นจมลงใต้ขอบหยักที่ระดับนี้ */
    public const RIBBON_JOIN = 40;

    public const ICON_Y = 52;

    public const ICON_SIZE = 17;

    /** กล่องชื่อบนดวง (สูงสุดสองบรรทัด) */
    public const NAME_BOX = ['x' => 28, 'y' => 62, 'w' => 44, 'h' => 15];

    public const NAME_SIZE = 5.6;

    public const BANNER_TEXT_Y = 87;

    public const BANNER_TEXT_SIZE = 5;

    public const GOLD = '#D9A441';

    public const GOLD_DEEP = '#A87A2A';

    public const RING_TEXT = '#FCE9C0';

    public const LAUREL = '#F2C66D';

    public const BANNER = '#FBF3E1';

    /**
     * ตัวอักษรที่วิ่งรอบขอบบน — อังกฤษ+ตัวเลขเท่านั้น เพราะวางทีละตัวอักษร
     * ข้อความไทยวางทีละตัวแล้วสระ/วรรณยุกต์จะหลุดจากพยัญชนะ
     */
    public static function ringText(?int $buddhistYear): string
    {
        return $buddhistYear
            ? 'LUILAYKHAO  •  FINISHER  •  '.$buddhistYear
            : 'LUILAYKHAO  •  FINISHER';
    }

    /**
     * ริบบิ้นสองข้าง แต่ละข้างเป็นแถบสี + เส้นขาวสองเส้น
     *
     * @return array{bands: array<int, array<int, float>>, stripes: array<int, array<int, float>>}
     */
    public static function ribbon(): array
    {
        $bands = [];
        $stripes = [];
        $join = self::RIBBON_JOIN;

        foreach ([-1, 1] as $s) {
            $bands[] = [
                self::CX + $s * 50, 0,
                self::CX + $s * 27, 0,
                self::CX - $s * 4, $join,
                self::CX + $s * 19, $join,
            ];

            foreach ([1 / 3, 2 / 3] as $f) {
                $top = 27 + 23 * $f;
                $bottom = -4 + 23 * $f;
                $stripes[] = [
                    self::CX + $s * ($top - 1.1), 0,
                    self::CX + $s * ($top + 1.1), 0,
                    self::CX + $s * ($bottom + 1.1), $join,
                    self::CX + $s * ($bottom - 1.1), $join,
                ];
            }
        }

        return ['bands' => $bands, 'stripes' => $stripes];
    }

    /** @return array<int, array{x: float, y: float}> จุดศูนย์กลางของวงเล็กรอบขอบหยัก */
    public static function scallops(): array
    {
        $points = [];

        for ($i = 0; $i < self::SCALLOPS; $i++) {
            $a = 2 * M_PI * $i / self::SCALLOPS;
            $points[] = [
                'x' => round(self::CX + self::ROSETTE_R * cos($a), 3),
                'y' => round(self::CY + self::ROSETTE_R * sin($a), 3),
            ];
        }

        return $points;
    }

    /**
     * ใบไม้ของช่อ (ซ้าย+ขวา) — แต่ละใบเป็นวงรี (cx, cy, rx, ry, หมุนกี่องศา)
     * ไล่จากโคนช่อใกล้ก้นเหรียญขึ้นไปทางด้านข้าง
     *
     * @return array<int, array{cx: float, cy: float, rx: float, ry: float, deg: float}>
     */
    public static function laurel(): array
    {
        $leaves = [];
        $r = 30;
        $steps = 8;

        foreach ([-1, 1] as $side) {
            for ($i = 0; $i <= $steps; $i++) {
                // ซ้าย: 118° → 212° (0° = ขวา, 90° = ลง ตามพิกัดจอ) ขวาสะท้อนกัน
                // โอบขึ้นมาถึงระดับไอคอน ใบโคนช่อจมอยู่หลังแถบป้าย
                $deg = 118 + 94 * $i / $steps;
                $a = deg2rad($side < 0 ? $deg : 180 - $deg);
                $px = self::CX + $r * cos($a);
                $py = self::CY + $r * sin($a);

                // ทิศเดินขึ้นตามก้านช่อ
                $tx = $side < 0 ? -sin($a) : sin($a);
                $ty = $side < 0 ? cos($a) : -cos($a);
                $tangent = atan2($ty, $tx);

                foreach ([-1, 1] as $fork) {
                    // ใบคู่แตกออกจากก้าน ±40° ใบบนสุดเหลือใบเดียวเป็นยอด
                    if ($i === $steps && $fork > 0) {
                        continue;
                    }

                    $leafAngle = $i === $steps ? $tangent : $tangent + $fork * deg2rad(40);
                    $leaves[] = [
                        'cx' => round($px + 2.1 * cos($leafAngle), 3),
                        'cy' => round($py + 2.1 * sin($leafAngle), 3),
                        'rx' => 3.2,
                        'ry' => 1.35,
                        'deg' => round(rad2deg($leafAngle), 2),
                    ];
                }
            }
        }

        return $leaves;
    }

    /** @return array<int, float> แถบป้ายหางนกนางแอ่นพาดล่างดวง */
    public static function banner(): array
    {
        return [14, 82, 86, 82, 82, 87, 86, 92, 14, 92, 18, 87];
    }

    /**
     * ทุกอย่างที่ฟอร์มแอดมินต้องใช้วาดพรีวิว — ส่งผ่าน /admin/medal-options
     *
     * @return array<string, mixed>
     */
    public static function forClient(): array
    {
        return [
            'ribbon' => self::ribbon(),
            'scallops' => self::scallops(),
            'laurel' => self::laurel(),
            'banner' => self::banner(),
        ];
    }
}
