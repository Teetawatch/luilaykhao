<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\LiveActivity;
use App\Models\TripSchedule;
use App\Services\TripActivityService;
use Illuminate\Console\Command;

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
        $this->retireAndroidCards($activities);

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
     * เปิดการ์ดให้คนที่ยังไม่มีบนเครื่องไหนเลย — ทุกคนในใบจอง ไม่ใช่แค่เจ้าของ
     *
     * จะเปิดเมื่อไหร่ (ช่วงรอรถมารับ / ขากลับ) และกันยิงซ้ำอย่างไร ตัดสินใน
     * [TripActivityService::pushToStart] ที่เดียว เพราะต้องรู้ state ของแต่ละคน
     */
    private function openMissing(TripSchedule $schedule, TripActivityService $activities): int
    {
        $bookings = Booking::with($activities->relations())
            ->where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->get();

        return $bookings->sum(fn (Booking $booking) => $activities->pushToStart($booking));
    }

    /**
     * การ์ด Android ไม่มีแถวใน live_activities ให้ [closeFinished] กวาด — รอบที่
     * กลับเมื่อวานจึงต้องสั่งเก็บแยก ส่งครั้งเดียวต่อเครื่อง (TripActivityService จำไว้)
     */
    private function retireAndroidCards(TripActivityService $activities): void
    {
        $yesterday = today('Asia/Bangkok')->subDay()->toDateString();

        Booking::with($activities->relations())
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereHas('schedule', fn ($q) => $q->whereDate('return_date', $yesterday))
            ->get()
            ->each(fn (Booking $booking) => $activities->retireAndroid($booking));
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
            ->with(collect($activities->relations())->map(fn ($r) => "booking.{$r}")->all())
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
