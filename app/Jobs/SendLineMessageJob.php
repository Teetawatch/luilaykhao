<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use App\Services\LineMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ส่งการแจ้งเตือนหนึ่งใบเข้า LINE OA
 *
 * อยู่ในคิวเพราะการจองกับการชำระเงินไม่ควรรอ HTTP ของ LINE — มันมี FCM ให้รอ
 * อยู่แล้วหนึ่งตัว ([SmartNotification::send]) การเพิ่มอีกตัวในสายคำขอคือการ
 * เอาความเร็วของหน้าจ่ายเงินไปแลกกับช่องทางแจ้งเตือนเสริม
 *
 * ลองแค่สองครั้ง: ข้อความแจ้งเตือนที่มาช้าเกินไปไม่มีประโยชน์ และกรณีที่ส่งไม่ได้
 * ส่วนใหญ่ (บล็อก OA / ตั้งค่า channel ผิด) ลองอีกกี่ครั้งก็ไม่ผ่าน
 */
class SendLineMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 20;

    public function __construct(public readonly int $notificationId) {}

    public function handle(LineMessagingService $line): void
    {
        $notification = SmartNotification::with('user')->find($this->notificationId);

        if (! $notification) {
            return; // ผู้ใช้ลบการแจ้งเตือนทิ้งระหว่างรอคิว
        }

        $line->sendNotification($notification);
    }
}
