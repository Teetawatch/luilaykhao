<?php

namespace App\Jobs;

use App\Models\ScheduleStaffAssignment;
use App\Models\TripSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ปลดสตาฟออกจากรอบเดินทางที่จบไปแล้ว (หรือถูกยกเลิก)
 *
 * แถวมอบหมายยังอยู่ครบ แค่ประทับ released_at ไว้ — ประวัติว่าใครเคยไปรอบไหน,
 * ตัวนับผลงาน และสิทธิ์เข้าห้องแชท/SOS ของทริปเก่าจึงไม่หายไป ส่วนหน้าจัดการ
 * สตาฟกับตารางงานอ่านเฉพาะคนที่ยัง active ทำให้ไม่มีใครค้างอยู่หลังจบทริป
 */
class ReleaseEndedTripStaffJob implements ShouldQueue
{
    use Queueable;

    public const TIMEZONE = 'Asia/Bangkok';

    public int $tries = 1;

    public function handle(): int
    {
        // เผื่อทริปที่กลับดึก — ปลดเฉพาะรอบที่จบตั้งแต่เมื่อวานลงไป
        $cutoff = now(self::TIMEZONE)->subDay()->toDateString();

        $endedIds = TripSchedule::query()
            ->where(function ($query) use ($cutoff) {
                $query->whereDate('return_date', '<=', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('return_date')->whereDate('departure_date', '<=', $cutoff);
                    });
            })
            ->orWhere('status', 'cancelled')
            ->pluck('id');

        if ($endedIds->isEmpty()) {
            return 0;
        }

        $released = ScheduleStaffAssignment::query()
            ->whereIn('schedule_id', $endedIds)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        if ($released > 0) {
            Log::info('ReleaseEndedTripStaffJob completed', ['assignments_released' => $released]);
        }

        return $released;
    }
}
