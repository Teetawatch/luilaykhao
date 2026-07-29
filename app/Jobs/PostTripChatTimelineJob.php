<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\TripSchedule;
use App\Services\TripChatTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ไล่โพสต์ข้อความอัตโนมัติตามไทม์ไลน์ทริปให้ทุกห้องแชทที่ถึงกำหนด
 * (ดูรายละเอียดจังหวะและเนื้อหาที่ TripChatTimelineService)
 *
 * รันทุก 15 นาที เพราะข้อความ "ก่อนรถออก 3 ชั่วโมง" ต้องละเอียดระดับชั่วโมง
 * ส่วนที่เหลือเป็นเวลาคงที่ของวัน การยิงถี่ไม่ทำให้ซ้ำ เพราะ system_key เป็น
 * unique ต่อห้องอยู่แล้ว
 */
class PostTripChatTimelineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** ช่วงรอบเดินทางที่ยังต้องดูแล: ก่อนเดินทาง 8 วัน ถึงหลังจบทริป 5 วัน */
    private const DAYS_BEFORE = 8;

    private const DAYS_AFTER = 5;

    public function handle(TripChatTimelineService $timeline): void
    {
        $now = CarbonImmutable::now(TripChatTimelineService::TIMEZONE);
        $from = $now->subDays(self::DAYS_AFTER)->toDateString();
        $to = $now->addDays(self::DAYS_BEFORE)->toDateString();

        $schedules = TripSchedule::query()
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($from, $to) {
                // ครอบทั้งรอบที่ยังไม่ออกและรอบที่เพิ่งจบ (เทียบวันกลับถ้ามี)
                $q->whereBetween('departure_date', [$from, $to])
                    ->orWhereBetween('return_date', [$from, $to]);
            })
            ->with('trip')
            ->get();

        $posted = 0;

        foreach ($schedules as $schedule) {
            // ห้องที่ยังไม่มีใครจอง = ยังไม่มีสมาชิก ไม่ต้องมีข้อความอัตโนมัติ
            $hasMembers = Booking::where('schedule_id', $schedule->id)
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
                ->exists();

            if (! $hasMembers) {
                continue;
            }

            try {
                $posted += count($timeline->syncFor($schedule, $now));
            } catch (\Throwable $e) {
                Log::warning('PostTripChatTimelineJob: โพสต์ไม่สำเร็จ', [
                    'schedule_id' => $schedule->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($posted > 0) {
            Log::info('PostTripChatTimelineJob: สรุปผล', [
                'schedules' => $schedules->count(),
                'messages_posted' => $posted,
            ]);
        }
    }
}
