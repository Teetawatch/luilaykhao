<?php

namespace App\Jobs;

use App\Models\SchedulePhoto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ลบรูปประจำรอบเดินทางที่อัปโหลดเกิน {@see SchedulePhoto::RETENTION_DAYS} วันแล้ว
 * ทั้งแถวในฐานข้อมูล ความสัมพันธ์กับรอบ และไฟล์จริงบน R2 เพื่อคืนพื้นที่เก็บข้อมูล
 *
 * นับจาก created_at ของรูป (วันที่อัปโหลด) ไม่ใช่วันเดินทาง — รูปที่ถูกนำไปใช้
 * ร่วมกับหลายรอบจึงหมดอายุพร้อมกันทุกรอบตามอายุไฟล์จริง
 *
 * รันรายชั่วโมงเพื่อให้ลบใกล้เวลาครบกำหนดจริง ไม่ต้องรอถึงรอบกลางคืน
 */
class PurgeExpiredSchedulePhotosJob implements ShouldQueue
{
    use Queueable;

    /** ลบทีละกี่แถวต่อรอบ — กันไม่ให้แบตช์เดียวถือรูปเป็นหมื่นไว้ในหน่วยความจำ */
    private const CHUNK = 200;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(): void
    {
        $deleted = 0;
        $files = 0;

        // chunkById เดินหน้าด้วย id > ตัวสุดท้ายเสมอ การลบแถวที่ประมวลผลไปแล้ว
        // จึงไม่ทำให้ข้ามแถวถัดไป
        SchedulePhoto::expired()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($photos) use (&$deleted, &$files) {
                $ids = $photos->pluck('id')->all();

                DB::transaction(function () use ($ids) {
                    // ตัดความสัมพันธ์กับทุกรอบก่อน แล้วค่อยลบแถวรูป
                    DB::table('schedule_photo')->whereIn('photo_id', $ids)->delete();
                    SchedulePhoto::whereIn('id', $ids)->delete();
                });

                // mass delete ไม่ยิง model event จึงต้องกวาดไฟล์เอง
                // (หนึ่งงานต่อหนึ่ง disk ไม่ว่าจะลบกี่รูปก็ตาม)
                foreach ($photos->groupBy(fn (SchedulePhoto $p) => $p->storageDisk()) as $disk => $group) {
                    $paths = $group->flatMap->mediaPaths()->all();
                    DeleteMediaFilesJob::dispatch($disk, $paths);
                    $files += count($paths);
                }

                $deleted += count($ids);
            });

        if ($deleted > 0) {
            Log::info('PurgeExpiredSchedulePhotosJob completed', [
                'retention_days' => SchedulePhoto::RETENTION_DAYS,
                'photos_deleted' => $deleted,
                'files_removed' => $files,
            ]);
        }
    }
}
