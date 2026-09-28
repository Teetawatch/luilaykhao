<?php

namespace App\Support;

/**
 * ผิวโลหะของเหรียญพิชิต — ขึ้นกับว่ามาพิชิตทริปเดิมเป็นครั้งที่เท่าไร
 *
 *  - ครั้งที่ 1–2  ทอง
 *  - ครั้งที่ 3–4  แพลทินัม
 *  - ครั้งที่ 5+   ดำทอง
 *
 * ไม่ถูกเก็บลงแถว: นับสดจากเหรียญของทริปเดียวกันที่คนนี้ถืออยู่ (เรียงตามวันที่
 * พิชิต) ดู MedalService::attemptsFor() — ใบจองรอบเก่าที่ถูกเพิ่มย้อนหลังจึงเลื่อน
 * ผิวของรอบหลังให้ถูกเอง
 *
 * ทรงเหรียญเป็นของเจ้าของเลือก ส่วนผิวเป็นของที่ต้องเดินไปให้ได้ — ผิวจึงเลือกเอง
 * ไม่ได้ สีทุกชุดคัดลอกไว้ใน lib/widgets/medal_art.dart (MedalFinish) แก้ด้วยกัน
 */
class MedalFinish
{
    public const GOLD = 'gold';

    public const PLATINUM = 'platinum';

    public const OBSIDIAN = 'obsidian';

    /** ครั้งแรกที่ได้ผิวนั้น */
    public const PLATINUM_FROM = 3;

    public const OBSIDIAN_FROM = 5;

    public const LABELS = [
        self::GOLD => 'ทอง',
        self::PLATINUM => 'แพลทินัม',
        self::OBSIDIAN => 'ดำทอง',
    ];

    /**
     * สีของแต่ละชั้นบนเหรียญแม่แบบ — banner_ink = null ใช้สีทริปแบบเข้ม
     *
     * @var array<string, array{frame: string, band: string, rim: string, ring: string, laurel: string, banner: string, banner_ink: string|null}>
     */
    public const PALETTES = [
        self::GOLD => [
            'frame' => MedalGeometry::GOLD,
            'band' => MedalGeometry::GOLD_DEEP,
            'rim' => MedalGeometry::GOLD,
            'ring' => MedalGeometry::RING_TEXT,
            'laurel' => MedalGeometry::LAUREL,
            'banner' => MedalGeometry::BANNER,
            'banner_ink' => null,
        ],
        self::PLATINUM => [
            'frame' => '#C9D2DC',
            'band' => '#6F8093',
            'rim' => '#C9D2DC',
            'ring' => '#F4F7FA',
            'laurel' => '#E6ECF2',
            'banner' => '#F4F7FA',
            'banner_ink' => null,
        ],
        self::OBSIDIAN => [
            'frame' => MedalGeometry::GOLD,
            'band' => '#1C1916',
            'rim' => MedalGeometry::GOLD,
            'ring' => '#F2C66D',
            'laurel' => '#F2C66D',
            'banner' => '#1C1916',
            'banner_ink' => '#F2C66D',
        ],
    ];

    public static function forAttempt(int $attempt): string
    {
        return match (true) {
            $attempt >= self::OBSIDIAN_FROM => self::OBSIDIAN,
            $attempt >= self::PLATINUM_FROM => self::PLATINUM,
            default => self::GOLD,
        };
    }

    /** @return array{frame: string, band: string, rim: string, ring: string, laurel: string, banner: string, banner_ink: string|null} */
    public static function palette(?string $finish): array
    {
        return self::PALETTES[$finish] ?? self::PALETTES[self::GOLD];
    }

    public static function label(?string $finish): string
    {
        return self::LABELS[$finish] ?? self::LABELS[self::GOLD];
    }
}
