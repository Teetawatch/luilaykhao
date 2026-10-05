<?php

namespace App\Jobs;

use App\Services\ChatFoodOrderService;
use App\Services\ChatPollService;
use App\Services\ChatRestStopService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * งานตามเวลาของการ์ดในห้องแชท ทุกนาที — โหวตตัดสินหมดเวลา (ประกาศผลเสียงข้างมาก),
 * รอบรับออเดอร์อาหารหมดเวลา (ประกาศยอดรวม) และจุดพัก (เตือนก่อนรถออก 5 นาที /
 * ถึงเวลาแล้วบอกสตาฟว่าขาดใคร) — ของพวกนี้มีอายุแค่ไม่กี่นาที
 */
class SettleChatPollsJob implements ShouldQueue
{
    use Queueable;

    public function handle(ChatPollService $polls, ChatFoodOrderService $food, ChatRestStopService $restStops): void
    {
        $polls->settleDue();
        $food->settleDue();
        $restStops->settleDue();
    }
}
