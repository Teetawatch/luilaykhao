<?php

namespace App\Jobs;

use App\Services\ChatDropoffService;
use App\Services\ChatFoodOrderService;
use App\Services\ChatPollService;
use App\Services\ChatRestStopService;
use App\Services\ChatTrailService;
use App\Services\ChatWellnessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * งานตามเวลาของการ์ดในห้องแชท ทุกนาที — โหวตตัดสินหมดเวลา (ประกาศผลเสียงข้างมาก),
 * รอบรับออเดอร์อาหารหมดเวลา (ประกาศยอดรวม) และจุดพัก (เตือนก่อนรถออก 5 นาที /
 * ถึงเวลาแล้วบอกสตาฟว่าขาดใคร / ปลุกรวม) เช็คอาการ (ถามซ้ำคนที่เงียบ) จุดลงขากลับ
 * (รถใกล้ถึงจุดไหนเตือนคนที่จะลง) และเช็คพอยต์ที่ลืมปิด — ของพวกนี้มีอายุแค่ไม่กี่นาที
 */
class SettleChatPollsJob implements ShouldQueue
{
    use Queueable;

    public function handle(
        ChatPollService $polls,
        ChatFoodOrderService $food,
        ChatRestStopService $restStops,
        ChatWellnessService $wellness,
        ChatDropoffService $dropoffs,
        ChatTrailService $trails,
    ): void {
        $polls->settleDue();
        $food->settleDue();
        $restStops->settleDue();
        $wellness->settleDue();
        $dropoffs->settleDue();
        $trails->settleDue();
    }
}
