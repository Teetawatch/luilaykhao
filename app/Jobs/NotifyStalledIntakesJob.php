<?php

namespace App\Jobs;

use App\Models\CustomerIntake;
use App\Services\MailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ตามเก็บกลุ่มที่กรอกค้างไว้แล้วเงียบไป
 *
 * ทีมงานได้เมลอัตโนมัติเมื่อกลุ่ม "กรอกครบ" แต่กลุ่มที่แจ้งว่ามา 4 คนแล้วมีแค่
 * 2 คนที่กรอก จะไม่มีวันครบเอง — ถ้าไม่ตามเก็บก็จะจมอยู่ในหน้าแอดมินเงียบ ๆ
 * จนถูกลบทิ้งตามอายุข้อมูล ทั้งที่คนสองคนนั้นอุตส่าห์กรอกให้แล้ว
 *
 * แจ้งครั้งเดียวต่อกลุ่มเหมือนกัน (team_notified_at) หลังจากนี้เป็นหน้าที่คน
 */
class NotifyStalledIntakesJob implements ShouldQueue
{
    use Queueable;

    /** เงียบเกินกี่ชั่วโมงถึงถือว่าไม่น่ามีใครมากรอกเพิ่มแล้ว */
    public const STALE_HOURS = 12;

    public int $tries = 1;

    public function handle(MailService $mail): void
    {
        CustomerIntake::query()
            ->open()
            ->whereNull('team_notified_at')
            ->where('last_activity_at', '<', now()->subHours(self::STALE_HOURS))
            ->with(['people', 'schedule.trip', 'link'])
            ->orderBy('id')
            ->chunkById(100, function ($intakes) use ($mail) {
                foreach ($intakes as $intake) {
                    // ไม่มีใครกรอกเลยสักคน = ไม่มีอะไรให้ทีมงานทำ
                    if ($intake->people->isEmpty()) {
                        continue;
                    }

                    $intake->forceFill(['team_notified_at' => now()])->save();
                    $mail->sendAdminIntakeReady($intake, 'stalled');
                }
            });
    }
}
