<?php

namespace App\Jobs;

use App\Services\ChatFoodOrderService;
use App\Services\ChatPollService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ปิดของในห้องแชทที่หมดเวลาเอง แล้วประกาศผลเข้าห้อง — โหวตตัดสิน (ผลเสียงข้างมาก)
 * และรอบรับออเดอร์อาหาร (ยอดรวม) ทุกนาที เพราะโหวตบนรถมีอายุแค่ 5–15 นาที
 */
class SettleChatPollsJob implements ShouldQueue
{
    use Queueable;

    public function handle(ChatPollService $polls, ChatFoodOrderService $food): void
    {
        $polls->settleDue();
        $food->settleDue();
    }
}
