<?php

namespace App\Jobs;

use App\Models\TripMedal;
use App\Services\ChallengeService;
use App\Services\MedalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * แจกเหรียญพิชิตให้รอบที่เดินจบแล้ว แล้วส่ง push "เหรียญมาแล้ว" ที่ถึงเวลา
 *
 * รายชั่วโมงเพราะงานนี้ทำสองอย่างที่เวลาต่างกัน: เหรียญโผล่ในตู้ทันทีที่ทริปจบ
 * (20:00 วันสุดท้าย) ส่วน push รอถึง 10:00 เช้าวันรุ่งขึ้น (ดู MedalService)
 * รอบแรกที่รันหลังเปิดใช้ระบบจะแจกย้อนหลังให้ทุกรอบในอดีตแบบเงียบ ๆ ตามลำดับเวลา
 */
class AwardTripMedalsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public int $timeout = 600;

    public function handle(MedalService $medals, ?ChallengeService $challenges = null): void
    {
        $challenges ??= app(ChallengeService::class);
        $created = 0;
        $failed = 0;

        foreach ($medals->schedulesToAward() as $schedule) {
            try {
                $created += $medals->awardSchedule($schedule);
            } catch (\Throwable $e) {
                // รอบเดียวพังต้องไม่ลากรอบอื่นทั้งหมดไปด้วย — รอบนี้จะถูกลองใหม่ชั่วโมงหน้า
                $failed++;
                Log::warning('AwardTripMedalsJob: schedule failed', [
                    'schedule_id' => $schedule->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $notified = $medals->notifyPending();

        // เหรียญใหม่อาจทำให้ชาเลนจ์สำเร็จ — ตรวจทุกคนที่ได้เหรียญใน 24 ชม. ล่าสุด
        // (ไม่ใช่แค่รอบนี้) รอบก่อนพังกลางทางก็ยังถูกเก็บตก sync ซ้ำได้ไม่เพิ่มซ้ำ
        $completed = 0;
        foreach (TripMedal::where('created_at', '>=', now()->subDay())->distinct()->pluck('user_id') as $userId) {
            try {
                $completed += $challenges->sync((int) $userId);
            } catch (\Throwable $e) {
                Log::warning('AwardTripMedalsJob: challenge sync failed', [
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        $challengePushes = $challenges->notifyPending();

        Log::info('AwardTripMedalsJob completed', [
            'created' => $created,
            'failed' => $failed,
            'notified' => $notified,
            'challenges_completed' => $completed,
            'challenge_pushes' => $challengePushes,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AwardTripMedalsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
