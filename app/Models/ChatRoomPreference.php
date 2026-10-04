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

    public const LEVELS = [self::LEVEL_ALL, self::LEVEL_IMPORTANT, self::LEVEL_OFF];

    protected $fillable = ['schedule_id', 'user_id', 'notify_level'];

    /**
     * ข้อความนี้ควรเด้งถึงคนที่ตั้งค่าไว้ระดับนี้หรือไม่
     *
     * "ทีมงาน" = สตาฟประจำรอบหรือแอดมิน (sender_role) ไม่ใช่ข้อความระบบ —
     * ข้อความระบบตามไทม์ไลน์ไม่ยิง push อยู่แล้วโดยเจตนา
     */
    public static function wantsPush(string $level, bool $isMention, bool $fromTeam): bool
    {
        return match ($level) {
            self::LEVEL_OFF => false,
            self::LEVEL_IMPORTANT => $isMention || $fromTeam,
            default => true,
        };
    }
}
