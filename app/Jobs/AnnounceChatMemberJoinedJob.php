<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\ChatRoomEventService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ประกาศในห้องแชทว่ามีเพื่อนร่วมทริปคนใหม่เข้ารอบ
 *
 * แยกเป็น job เพราะจุดที่เรียก (BookingObserver) ทำงานอยู่ "ใน" ทรานแซกชันของ
 * BookingService — โพสต์ตรง ๆ จะ broadcast ออกไปก่อน commit และถ้าจองล้มทีหลัง
 * ห้องจะเหลือข้อความค้างของการจองที่ไม่มีอยู่จริง จึงตั้ง afterCommit ไว้
 */
class AnnounceChatMemberJoinedJob implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(public readonly int $bookingId) {}

    public function handle(ChatRoomEventService $events): void
    {
        $booking = Booking::with(['schedule', 'user'])->find($this->bookingId);

        // ยกเลิก/คืนเงินไปแล้วระหว่างรอคิว = ไม่ต้องประกาศว่ามาร่วมทริป
        if (! $booking || ! in_array($booking->status, ['confirmed', 'completed'], true)) {
            return;
        }

        $events->memberJoined($booking);
    }
}
