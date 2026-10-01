<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\ChatRoomEventService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ประกาศในห้องแชทว่ามีคนมาร่วมทริปแทนคนเดิม (รับของขวัญทริป)
 *
 * แยกเป็น job ด้วยเหตุผลเดียวกับ AnnounceChatMemberJoinedJob — ห้องแชทล่ม
 * ต้องไม่ทำให้การกดรับของขวัญล้ม และต้องประกาศหลัง commit เท่านั้น
 */
class AnnounceChatMemberReplacedJob implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $userId,
    ) {}

    public function handle(ChatRoomEventService $events): void
    {
        $booking = Booking::with(['schedule', 'user'])->find($this->bookingId);

        // ยกเลิกไปแล้ว หรือเปลี่ยนมือไปอีกทอดระหว่างรอคิว = ข้อความนี้ไม่จริงแล้ว
        if (
            ! $booking
            || $booking->user_id !== $this->userId
            || ! in_array($booking->status, ['confirmed', 'completed'], true)
        ) {
            return;
        }

        $events->memberReplaced($booking);
    }
}
