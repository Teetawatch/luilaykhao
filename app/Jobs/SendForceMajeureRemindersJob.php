<?php

namespace App\Jobs;

use App\Services\ForceMajeureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * เตือนลูกค้าที่รอบถูกเลื่อนเพราะเหตุสุดวิสัยแต่ยังไม่ได้เลือกรอบใหม่ เมื่อสิทธิ์
 * เหลือ 30 / 7 / 1 วัน — ทุกวัน 10:00 (Asia/Bangkok) ดู ForceMajeureService
 */
class SendForceMajeureRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(ForceMajeureService $forceMajeure): void
    {
        $result = $forceMajeure->sendDeadlineReminders();

        if ($result['reminded'] > 0) {
            Log::info('SendForceMajeureRemindersJob completed', $result);
        }
    }
}
