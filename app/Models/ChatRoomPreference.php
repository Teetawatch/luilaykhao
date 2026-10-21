<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * การตั้งค่าแจ้งเตือนของห้องแชททริปรายคน — ไม่มีแถว = ทุกข้อความ
 */
class ChatRoomPreference extends Model
{
    /** ทุกข้อความ (ค่าเริ่มต้น — ไม่เก็บเป็นแถว) */
    public const LEVEL_ALL = 'all';

    /** เฉพาะข้อความจากทีมงาน และข้อความที่แท็กถึงฉัน */
    public const LEVEL_IMPORTANT = 'important';

    /** ไม่แจ้งเตือนจากห้องนี้เลย */
    public const LEVEL_OFF = 'off';

    /**
     * เงียบไว้ก่อน (เท่ากับ "เฉพาะสำคัญ") จนเหลือ UNTIL_TRIP_DAYS วันก่อนเดินทาง
     * แล้วกลายเป็น "ทุกข้อความ" เอง — คนจองล่วงหน้าเป็นเดือนไม่ต้องโดนแชทคุยเล่น
     * รบกวนทั้งเดือน แต่ไม่พลาดช่วงที่ห้องคุยเรื่องจริงก่อนออกเดินทาง
     *
     * คำนวณจากวันเดินทางตอนส่ง push ทุกครั้ง (ไม่เก็บวันที่) เลื่อนรอบแล้วยังถูก
     */
    public const LEVEL_UNTIL_TRIP = 'until_trip';

    public const UNTIL_TRIP_DAYS = 3;

    public const LEVELS = [self::LEVEL_ALL, self::LEVEL_UNTIL_TRIP, self::LEVEL_IMPORTANT, self::LEVEL_OFF];

    protected $fillable = ['schedule_id', 'user_id', 'notify_level'];

    /**
     * ข้อความนี้ควรเด้งถึงคนที่ตั้งค่าไว้ระดับนี้หรือไม่
     *
     * "ทีมงาน" = สตาฟประจำรอบหรือแอดมิน (sender_role) ไม่ใช่ข้อความระบบ —
     * ข้อความระบบตามไทม์ไลน์ไม่ยิง push อยู่แล้วโดยเจตนา
     *
     * $level ต้องผ่าน ChatService::effectiveLevel() มาก่อน — until_trip ที่หลุดมา
     * ถูกถือเป็น "เฉพาะสำคัญ" (เงียบไว้ก่อนปลอดภัยกว่าดังผิดเวลา)
     */
    public static function wantsPush(string $level, bool $isMention, bool $fromTeam): bool
    {
        return match ($level) {
            self::LEVEL_OFF => false,
            self::LEVEL_IMPORTANT, self::LEVEL_UNTIL_TRIP => $isMention || $fromTeam,
            default => true,
        };
    }
}
