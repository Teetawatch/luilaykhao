<?php

namespace App\Jobs;

use App\Models\SosAlert;
use App\Support\MediaDisk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ลบรูปที่แนบมากับเคส SOS ที่ปิดไปนานแล้ว
 *
 * รูปพวกนี้ถ่ายในสถานการณ์ฉุกเฉินและมักติดใบหน้า/สภาพร่างกายของคน จึงไม่ควร
 * ค้างบน storage ตลอดกาล ตัวเคสยังอยู่ครบเป็นบันทึกเหตุการณ์ ลบเฉพาะไฟล์รูป
 */
class PruneSosPhotosJob implements ShouldQueue
{
    use Queueable;

    /** เก็บรูปไว้ 180 วันหลังปิดเคส — พอสำหรับการสอบสวน/เคลมประกัน */
    public const RETENTION_DAYS = 180;

    public function handle(): void
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $deleted = 0;

        SosAlert::query()
            ->where('status', 'resolved')
            ->whereNotNull('photo_path')
            ->where('resolved_at', '<', $cutoff)
            ->chunkById(100, function ($alerts) use (&$deleted) {
                foreach ($alerts as $alert) {
                    try {
                        Storage::disk(MediaDisk::name())->delete($alert->photo_path);
                    } catch (\Throwable $e) {
                        Log::warning('Unable to delete SOS photo', [
                            'sos_alert_id' => $alert->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $alert->forceFill(['photo_path' => null])->save();
                    $deleted++;
                }
            });

        if ($deleted > 0) {
            Log::info('Pruned SOS photos', ['count' => $deleted]);
        }
    }
}
