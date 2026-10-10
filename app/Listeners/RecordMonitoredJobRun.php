<?php

namespace App\Listeners;

use App\Services\OpsHealthService;
use Illuminate\Queue\Events\JobProcessed;

/**
 * จดเวลาที่งานตั้งเวลาสำคัญทำงานจบจริงบน worker — ให้ OpsHealthService รู้ว่า
 * งานไหน "เงียบไป" (ส่งเข้าคิวแล้วไม่มีใครรับ = ไม่มี error ให้เห็นเลย)
 *
 * auto-discovered เหมือน LogSentEmail — อย่าลงทะเบียนเพิ่มเอง ไม่งั้นทำงานซ้ำ
 */
class RecordMonitoredJobRun
{
    public function __construct(private OpsHealthService $health) {}

    public function handle(JobProcessed $event): void
    {
        // งานที่ถูกปล่อยกลับเข้าคิว (release) หรือล้มเหลวยังไม่นับว่าทำงานจบ
        if ($event->job->isReleased() || $event->job->hasFailed()) {
            return;
        }

        try {
            $this->health->recordJobRun($event->job->resolveName());
        } catch (\Throwable) {
            // การเฝ้าระบบต้องไม่ทำให้งานจริงล้ม
        }
    }
}
