<?php

namespace App\Support;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\Redis;

/**
 * id ข้อความล่าสุดของแต่ละห้องแชท เก็บใน Redis — ให้การถามหาข้อความใหม่ตอบว่า
 * "ไม่มี" ได้โดยไม่ต้องแตะตาราง chat_messages
 *
 * แอปถามหาข้อความใหม่ทุก 20 วินาที (ทุก 4 วินาทีเมื่อ WebSocket หลุด) ต่อคน และ
 * เกือบทุกครั้งคำตอบคือ "ไม่มี"
 *
 * กติกาที่ทำให้ไม่มีวันพลาดข้อความ: ค่าในแคช **ขยับได้ทางขึ้นทางเดียว** และต้อง
 * ไม่ต่ำกว่า id ข้อความจริงที่ commit แล้ว
 *   - เก็บเป็น sorted set (score = id) แล้วตัดทิ้งทุกตัวยกเว้นตัวสูงสุด — ZADD ของ
 *     สองข้อความที่มาพร้อมกันจะวิ่งสลับลำดับอย่างไร ตัวสูงสุดก็ยังอยู่เสมอ ไม่ต้องใช้
 *     lock หรือ Lua
 *   - ขยับทันทีตอนสร้างข้อความ (ก่อน commit ก็ได้): ค่าที่สูงกว่าความจริงแค่ทำให้
 *     ถามฐานข้อมูลเพิ่มหนึ่งครั้ง ไม่เคยทำให้ข้อความหาย
 *   - ตอนแคชว่าง เติมจาก MAX(id) ด้วยวิธีเดียวกัน — ค่าจากฐานข้อมูลที่ช้ากว่าความจริง
 *     กดค่าที่สูงกว่าซึ่งข้อความใหม่เพิ่งเขียนไว้ลงไม่ได้
 *   - คีย์หมดอายุเร็ว ([TTL_SECONDS]) เผื่อกรณีเขียนพลาดตอน Redis สะดุดแวบเดียว
 *     ค่าจะค้างต่ำไม่เกินเท่านี้ และระหว่างนั้น WebSocket ยังส่งข้อความถึงแอปตามปกติ
 *
 * Redis ล่ม = ตอบว่า "ไม่รู้" ทุกครั้ง ผู้เรียกถามฐานข้อมูลเหมือนเดิม
 */
final class ChatRoomCursor
{
    public const TTL_SECONDS = 120;

    public static function key(int $scheduleId): string
    {
        return "chat:last-id:{$scheduleId}";
    }

    /** เรียกทุกครั้งที่มีข้อความใหม่ — พังเงียบ ๆ ได้ (ผู้อ่านถอยไปฐานข้อมูล) */
    public static function bump(int $scheduleId, int $messageId): void
    {
        try {
            self::raise($scheduleId, $messageId);
        } catch (\Throwable) {
            // Redis สะดุด — คีย์จะหมดอายุภายใน TTL แล้วเติมใหม่จากฐานข้อมูล
        }
    }

    /**
     * true = แน่ใจว่าห้องนี้ไม่มีข้อความที่ id มากกว่า [afterId]
     * false = มี หรือไม่รู้ (ผู้เรียกต้องถามฐานข้อมูล)
     */
    public static function nothingAfter(int $scheduleId, int $afterId): bool
    {
        try {
            $last = self::read($scheduleId);

            if ($last === null) {
                $last = (int) ChatMessage::where('schedule_id', $scheduleId)->max('id');
                self::raise($scheduleId, $last);
                // อ่านซ้ำ — ระหว่างนั้นอาจมีข้อความใหม่ขยับค่าขึ้นไปแล้ว
                $last = max($last, (int) self::read($scheduleId));
            }

            return $last <= $afterId;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function read(int $scheduleId): ?int
    {
        $top = Redis::zrevrange(self::key($scheduleId), 0, 0);

        return is_array($top) && $top !== [] ? (int) reset($top) : null;
    }

    private static function raise(int $scheduleId, int $messageId): void
    {
        $key = self::key($scheduleId);

        Redis::zadd($key, [(string) $messageId => $messageId]);
        // เหลือไว้ตัวเดียว (ตัวสูงสุด) — ไม่ว่าคำสั่งของใครจะวิ่งก่อนหลัง
        Redis::zremrangebyrank($key, 0, -2);
        Redis::expire($key, self::TTL_SECONDS);
    }
}
