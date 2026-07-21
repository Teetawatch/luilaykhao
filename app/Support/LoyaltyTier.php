<?php

namespace App\Support;

/**
 * ระดับสมาชิก — แหล่งความจริงเดียวของทั้งเว็บ แอป และหลังบ้าน
 *
 * เดิมชื่อระดับถูกเขียนซ้ำสองที่ (API ตอบ "Silver Member" ส่วนแอปแปลเองเป็น
 * "ระดับเงิน") คนเดียวกันเปิดสองที่จึงเห็นคนละชื่อ ทุกอย่างที่เกี่ยวกับระดับ
 * จึงมารวมไว้ที่นี่ที่เดียว แล้วให้ทุกฝั่งอ่านจาก API
 *
 * เกณฑ์เดิม (1,500 / 5,000 แต้ม) สูงจนไปไม่ถึง — ที่ 100 บาท = 1 แต้ม และทริป
 * เฉลี่ยราว 3,500 บาท ต้องเดินทาง ~43 ทริปถึงจะขึ้นระดับแรก ระบบจึงไม่เคยทำงาน
 * เกณฑ์ชุดนี้ตั้งให้ขาประจำจริง ๆ ไปถึงได้ในเวลาที่สมเหตุสมผล
 */
class LoyaltyTier
{
    public const FRIEND = 'friend';

    public const FREQUENT = 'frequent';

    public const COMRADE = 'comrade';

    public const INSIDER = 'insider';

    /**
     * เรียงจากระดับต่ำสุดขึ้นไป — ลำดับใน array นี้คือลำดับของระดับ
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => self::FRIEND,
                'label' => 'เพื่อนร่วมทาง',
                'tagline' => 'ยินดีที่ได้ออกเดินทางด้วยกัน',
                'min_points' => 0,
                'point_multiplier' => 1.0,
                'seat_lock_bonus_minutes' => 0,
                'early_access_hours' => 0,
                'deposit_discount_percent' => 0,
                'birthday_coupon_baht' => 0,
            ],
            [
                'code' => self::FREQUENT,
                'label' => 'ขาประจำ',
                'tagline' => 'กลับมาอีกแล้ว ดีใจที่ได้เจอกัน',
                'min_points' => 100,
                'point_multiplier' => 1.1,
                'seat_lock_bonus_minutes' => 5,
                'early_access_hours' => 0,
                'deposit_discount_percent' => 5,
                'birthday_coupon_baht' => 100,
            ],
            [
                'code' => self::COMRADE,
                'label' => 'สหายนักเดิน',
                'tagline' => 'ไปด้วยกันมาหลายเส้นทางแล้ว',
                'min_points' => 300,
                'point_multiplier' => 1.25,
                'seat_lock_bonus_minutes' => 10,
                'early_access_hours' => 12,
                'deposit_discount_percent' => 10,
                'birthday_coupon_baht' => 200,
            ],
            [
                'code' => self::INSIDER,
                'label' => 'คนกันเอง',
                'tagline' => 'สนิทกับเราจนเป็นครอบครัวเดียวกัน',
                'min_points' => 700,
                'point_multiplier' => 1.5,
                'seat_lock_bonus_minutes' => 15,
                'early_access_hours' => 24,
                'deposit_discount_percent' => 15,
                'birthday_coupon_baht' => 300,
            ],
        ];
    }

    /** โค้ดระดับทั้งหมด เรียงจากต่ำไปสูง. */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }

    /**
     * ระดับที่ควรอยู่ตามแต้มสะสมตลอดชีพ — ไล่จากสูงลงต่ำ เจออันแรกที่ถึงเกณฑ์
     */
    public static function forLifetimePoints(int $lifetimePoints): string
    {
        foreach (array_reverse(self::all()) as $tier) {
            if ($lifetimePoints >= $tier['min_points']) {
                return $tier['code'];
            }
        }

        return self::FRIEND;
    }

    /**
     * รายละเอียดของระดับหนึ่ง — ระดับที่ไม่รู้จัก (เช่นข้อมูลเก่าที่ยังไม่ถูก
     * ย้าย) ถูกปฏิบัติเหมือนระดับเริ่มต้น ดีกว่าปล่อยให้พัง
     */
    public static function find(?string $code): array
    {
        foreach (self::all() as $tier) {
            if ($tier['code'] === $code) {
                return $tier;
            }
        }

        return self::all()[0];
    }

    public static function label(?string $code): string
    {
        return self::find($code)['label'];
    }

    /**
     * ลำดับของระดับ (0 = ต่ำสุด) — ใช้เก็บเป็นตัวเลขในคิวรอที่นั่ง เพราะเรียงลำดับ
     * ด้วยตัวเลขในฐานข้อมูลได้ตรงไปตรงมากว่าเรียงด้วยชื่อระดับ
     */
    public static function rank(?string $code): int
    {
        $index = array_search($code, self::codes(), true);

        return $index === false ? 0 : $index;
    }

    /** ค่าของสิทธิ์หนึ่งข้อสำหรับระดับที่ระบุ. */
    public static function perk(?string $code, string $perk): float|int
    {
        return self::find($code)[$perk] ?? 0;
    }

    /**
     * ระดับถัดไปพร้อมระยะที่เหลือ — คืน null เมื่ออยู่ระดับสูงสุดแล้ว
     */
    public static function next(?string $code, int $lifetimePoints): ?array
    {
        $tiers = self::all();
        $currentIndex = null;

        foreach ($tiers as $i => $tier) {
            if ($tier['code'] === $code) {
                $currentIndex = $i;
                break;
            }
        }

        $next = $tiers[($currentIndex ?? 0) + 1] ?? null;

        if (! $next) {
            return null;
        }

        return [
            'tier' => $next['code'],
            'label' => $next['label'],
            'at' => $next['min_points'],
            'points_needed' => max(0, $next['min_points'] - $lifetimePoints),
        ];
    }
}
