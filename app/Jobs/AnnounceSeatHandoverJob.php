<?php

namespace App\Jobs;

use App\Models\SeatHandover;
use App\Services\ChatRoomEventService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ประกาศในห้องแชทว่ามีคนรับที่นั่งต่อ (ส่งต่อที่นั่ง)
 *
 * แยกเป็น job ด้วยเหตุผลเดียวกับ AnnounceChatMemberReplacedJob — ห้องแชทล่มต้อง
 * ไม่ทำให้การรับที่นั่งล้ม และต้องประกาศหลัง commit เท่านั้น
 */
class AnnounceSeatHandoverJob implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(public readonly int $handoverId) {}

    public function handle(ChatRoomEventService $events): void
    {
        $handover = SeatHandover::with(['booking.schedule', 'claimer'])->find($this->handoverId);

        // ใบจองถูกยกเลิกระหว่างรอคิว = ไม่มีใครมาร่วมทริปแล้ว
        if (
            ! $handover
            || $handover->status !== SeatHandover::STATUS_CLAIMED
            || ! $handover->claimer
            || ! $handover->booking
            || ! in_array($handover->booking->status, ['confirmed', 'completed'], true)
        ) {
            return;
        }

        $events->seatHandedOver($handover->booking, $handover->claimer, $handover->id);
    }
}
