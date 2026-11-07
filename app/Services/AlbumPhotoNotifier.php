<?php

namespace App\Services;

use App\Jobs\NotifyAlbumPhotosAddedJob;
use App\Models\TripSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * บอกลูกค้าว่า "รูปทริปมาแล้ว" เมื่อสตาฟ (แอป) หรือแอดมิน (หลังบ้าน) เพิ่มรูปเข้า
 * อัลบั้มของรอบ — รวมเป็นแจ้งเตือนเดียวต่อการอัปหนึ่งชุด
 *
 * แอปสตาฟส่งรูปเป็นชุดละ 3 รูป อัลบั้มหนึ่งรอบจึงเป็นคำขอหลายสิบครั้ง ถ้าแจ้งทุกครั้ง
 * ลูกค้าจะได้ push เป็นสิบอัน — ที่นี่จึงตั้งงานส่งไว้ครั้งเดียวเมื่อรูปแรกของชุดเข้ามา
 * แล้วรอให้อัปเสร็จ ({@see SETTLE_MINUTES}) งานจะนับเองตอนส่งว่ามีรูปใหม่กี่รูป
 */
class AlbumPhotoNotifier
{
    /** รอหลังรูปแรกของชุดเท่านี้ ก่อนแจ้ง — ให้สตาฟอัปชุดใหญ่จนเสร็จ */
    public const SETTLE_MINUTES = 15;

    /** ระหว่างแจ้งสองครั้งของรอบเดียวกัน ต้องห่างกันอย่างน้อยเท่านี้ — รูปที่ตามมาทีหลังรวมไปแจ้งรอบถัดไป */
    public const COOLDOWN_MINUTES = 180;

    private const TIMEZONE = 'Asia/Bangkok';

    public function __construct(private BroadcastNotificationService $broadcasts) {}

    /** เรียกหลังรูปถูกผูกเข้ารอบแล้ว (transaction commit แล้ว) */
    public function photosAdded(TripSchedule $schedule): void
    {
        if (! $this->eligible($schedule)) {
            return;
        }

        $now = CarbonImmutable::now(self::TIMEZONE);
        $sendAt = $now->addMinutes(self::SETTLE_MINUTES);

        $lastSent = Cache::get(self::lastSentKey($schedule->id));
        if ($lastSent) {
            $cooldownEnds = CarbonImmutable::parse($lastSent)->setTimezone(self::TIMEZONE)
                ->addMinutes(self::COOLDOWN_MINUTES);
            if ($cooldownEnds->greaterThan($sendAt)) {
                $sendAt = $cooldownEnds;
            }
        }

        // ไม่ปลุกใครกลางดึก — สตาฟมักนั่งคัดรูปตอนถึงบ้านแล้ว
        $sendAt = $this->broadcasts->quietHoursDelay($sendAt) ?? $sendAt;

        // งานเดียวต่อรอบต่อชุด: คีย์อยู่จนงานเริ่มทำ (งานลบเองก่อนนับรูป) เผื่อเวลาไว้กันคีย์ค้าง
        $ttl = $sendAt->addHour();
        if (! Cache::add(self::pendingKey($schedule->id), $now->toIso8601String(), $ttl)) {
            return;
        }

        NotifyAlbumPhotosAddedJob::dispatch($schedule->id, $now->toIso8601String())->delay($sendAt);
    }

    /** ลูกค้าควรได้ยินเรื่องรูปของรอบนี้ไหม — รอบที่ยกเลิก/ยังไม่ออกเดินทางไม่แจ้ง */
    public function eligible(TripSchedule $schedule): bool
    {
        if ($schedule->status === 'cancelled' || ! $schedule->departure_date) {
            return false;
        }

        // ก่อนวันเดินทางยังไม่มีรูปทริปจริง (แอดมินอาจกำลังลองระบบ) ไม่ต้องบอกใคร
        return $schedule->departure_date->toDateString() <= now(self::TIMEZONE)->toDateString();
    }

    public static function pendingKey(int $scheduleId): string
    {
        return "album_photos_push:pending:{$scheduleId}";
    }

    public static function lastSentKey(int $scheduleId): string
    {
        return "album_photos_push:last_sent:{$scheduleId}";
    }
}
