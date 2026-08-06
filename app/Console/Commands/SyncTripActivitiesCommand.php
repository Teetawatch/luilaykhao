<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\LiveActivity;
use App\Models\TripSchedule;
use App\Services\TripActivityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * ทำให้การ์ด "วันเดินทาง" บนหน้าจอล็อกตรงกับความจริง — ทุกนาที
 *
 * สามหน้าที่ในรอบเดียว เพราะทั้งสามอ่านชุดรอบเดินทางเดียวกัน:
 *   1. เปิดการ์ดให้เครื่องที่ยังไม่มี (push-to-start, iOS 17.2+)
 *   2. อัปเดตการ์ดที่เปิดอยู่ (ETA ลดลง / เช็คอินแล้ว / รถถึงจุดรับ)
 *   3. ปิดการ์ดของรอบที่จบไปแล้ว ไม่ให้ค้างบนหน้าจอล็อกข้ามวัน
 */
class SyncTripActivitiesCommand extends Command
{
    protected $signature = 'trip-activity:sync';

    protected $description = 'Push Live Activity / ongoing-notification updates for trips happening now.';

    /** เริ่มพยายามเปิดการ์ดให้เองล่วงหน้ากี่ชั่วโมงก่อนรถออก */
    private const PUSH_TO_START_HOURS = 6;

    public function handle(TripActivityService $activities): int
    {
        $schedules = TripSchedule::with('trip')
            ->inProgressOn(today('Asia/Bangkok'))
            ->whereNotIn('status', ['cancelled'])
            ->get();

        // รถที่ออกคืนก่อนวันทริปทำให้ "รอบของพรุ่งนี้" ต้องมีการ์ดตั้งแต่คืนนี้
        $schedules = $schedules->merge(
            TripSchedule::with('trip')
                ->departingOn(today('Asia/Bangkok')->addDay())
                ->whereNotIn('status', ['cancelled'])
                ->get()
        )->unique('id');

        $this->closeFinished($activities, $schedules->pluck('id')->all());

        $started = 0;
        $pushed = 0;

        foreach ($schedules as $schedule) {
            $started += $this->openMissing($schedule, $activities);
            $pushed += $activities->syncSchedule($schedule);
        }

        if ($started > 0 || $pushed > 0) {
            $this->info("Live activities started: {$started}, updated: {$pushed}.");
        }

        return self::SUCCESS;
    }

    /**
     * เปิดการ์ดให้ใบจองที่ยังไม่มีบนเครื่องไหนเลย
     *
     * กันยิงซ้ำด้วย cache รายใบจอง เพราะ push-to-start ที่ไม่สำเร็จ (เครื่อง iOS 16,
     * ผู้ใช้ปิด Live Activity ในตั้งค่า) จะไม่มีวันสำเร็จในรอบถัดไปเช่นกัน
     */
    private function openMissing(TripSchedule $schedule, TripActivityService $activities): int
    {
        $hoursAway = $activities->hoursUntilDeparture($schedule);
        if ($hoursAway === null || $hoursAway > self::PUSH_TO_START_HOURS) {
            return 0;
        }

        $bookings = Booking::with(['schedule.trip', 'schedule.vehicle', 'pickupPoint'])
            ->where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->get();

        $started = 0;

        foreach ($bookings as $booking) {
            $key = "trip_activity_started:{$booking->id}";
            if (Cache::has($key)) {
                continue;
            }

            $count = $activities->pushToStart($booking);
            if ($count > 0) {
                $started += $count;
            }

            Cache::put($key, true, now()->addHours(12));
        }

        return $started;
    }

    /**
     * ปิดการ์ดของรอบที่ไม่ได้อยู่ในชุด "กำลังเกิดขึ้นตอนนี้" อีกแล้ว
     *
     * รอบที่จบไปเมื่อวานหลุดออกจาก loop หลักไปเลย ถ้าไม่กวาดตรงนี้ การ์ดของมันจะ
     * ค้างบนหน้าจอล็อกไปเรื่อย ๆ โดยไม่มีใครไปสั่งปิด — [TripActivityService::sync()]
     * เห็น state เป็น null เองแล้วสั่งปิดให้ เราแค่ต้องพามันไปเจอ
     *
     * @param  array<int, int>  $activeScheduleIds
     */
    private function closeFinished(TripActivityService $activities, array $activeScheduleIds): void
    {
        $stale = LiveActivity::live()
            ->with(['booking.schedule.trip', 'booking.pickupPoint'])
            ->when($activeScheduleIds, fn ($query) => $query->whereNotIn('schedule_id', $activeScheduleIds))
            ->get();

        foreach ($stale as $activity) {
            $booking = $activity->booking;

            if (! $booking) {
                $activity->forceFill(['ended_at' => now()])->save();

                continue;
            }

            $activities->sync($booking, $activity);
        }
    }
}
