<?php

namespace App\Services;

use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * แจ้งเตือนเมื่อจำนวนที่นั่งของรอบเดินทางข้ามเกณฑ์สำคัญ — ทั้งขาขึ้น
 * (เต็ม / เหลือน้อย / ข้ามแถบสถานะการันตีออกเดินทาง) และขาลง (ที่นั่งว่างคืนมา)
 *
 * เดิมตรรกะนี้อยู่ใน BookingService และยิงเฉพาะตอนลูกค้าจองเอง ทำให้การที่แอดมิน
 * ย้ายผู้โดยสารเข้ามาจนรอบเต็มไม่มีการแจ้งเตือนใด ๆ เลย — แยกออกมาเพื่อให้ทุกทาง
 * ที่ทำให้ที่นั่งเพิ่มเรียกใช้ได้เหมือนกัน ทุก method self-guard และ dedupe ในตัว
 * จึงเรียกซ้ำได้ปลอดภัย และเป็น best-effort ทั้งหมด (ห้าม error กระทบงานหลัก)
 */
class ScheduleSeatNotifier
{
    public function __construct(
        private BroadcastNotificationService $broadcast,
        private TripAlertService $tripAlerts,
        private WaitlistService $waitlist,
        private ChatRoomEventService $chatEvents,
    ) {}

    /**
     * เรียกหลัง commit เมื่อที่นั่งของรอบเปลี่ยน — ยิงเฉพาะตอนที่นั่ง "เพิ่มขึ้น"
     * เท่านั้น (การยกเลิก/ย้ายออกทำให้ที่นั่งว่างขึ้น ไม่ต้องแจ้งเตือนกลุ่มนี้)
     *
     * @param  int|null  $bookedBefore  booked_seats ก่อนเปลี่ยน
     * @param  int|null  $bookedAfter  booked_seats หลังเปลี่ยน
     * @param  string|null  $bookingRef  เลขที่จองที่ทำให้เต็ม (ถ้ามี) — ใส่ในแจ้งเตือนแอดมิน
     */
    public function seatsIncreased(int $scheduleId, ?int $bookedBefore, ?int $bookedAfter, ?string $bookingRef = null): void
    {
        if ($bookedBefore === null || $bookedAfter === null || $bookedAfter <= $bookedBefore) {
            return;
        }

        $schedule = TripSchedule::with('trip')->find($scheduleId);
        if (! $schedule) {
            return;
        }

        if ($schedule->available_seats <= 0) {
            $this->notifyStaffScheduleFull($schedule, $bookingRef);
            $this->notifySoldOut($schedule);
        } elseif ($schedule->available_seats <= BroadcastNotificationService::lowSeatThreshold()) {
            $this->notifyLowSeats($schedule);
        }

        $this->notifyDepartureStatusCrossing($schedule, $bookedBefore, $bookedAfter);
    }

    /**
     * เรียกหลัง commit เมื่อที่นั่งของรอบ "ว่างคืนมา" (ยกเลิก / ลบการจอง /
     * ย้ายผู้โดยสารออก / หมดเวลาชำระเงิน) — ประกาศให้คนที่พลาดรอบนี้ไปรู้ว่า
     * มีที่ว่างแล้ว ยิงเฉพาะรอบที่ยังตึงอยู่ (เหลือไม่เกินเกณฑ์ "ใกล้เต็ม")
     * เพราะรอบที่ว่างเยอะอยู่แล้วการยกเลิกไม่ใช่ข่าว
     *
     * @param  int|null  $bookedBefore  booked_seats ก่อนคืนที่นั่ง
     * @param  int|null  $bookedAfter  booked_seats หลังคืนที่นั่ง
     */
    public function seatsFreed(int $scheduleId, ?int $bookedBefore, ?int $bookedAfter): void
    {
        if ($bookedBefore === null || $bookedAfter === null || $bookedAfter >= $bookedBefore) {
            return;
        }

        $schedule = TripSchedule::with('trip')->find($scheduleId);
        if (! $schedule) {
            return;
        }

        // คนในคิวรอมีสิทธิ์ก่อน — ProcessWaitlistJob จองที่ให้เขา 15 นาที
        // ถ้าประกาศให้ทุกคนตอนนี้ คิวที่รอมาก่อนจะโดนแซง จึงเงียบไว้
        // (คิวว่างเมื่อไหร่ offer หมดอายุ ที่นั่งค่อยกลับสู่สาธารณะเอง)
        if ($this->waitlist->scheduleWaitlistCount($scheduleId) > 0) {
            return;
        }

        try {
            $this->broadcast->broadcastSeatsFreed($schedule);
            $this->tripAlerts->notifySeatsFreed($schedule);
        } catch (\Throwable $e) {
            Log::warning('ScheduleSeatNotifier: seats-freed notification failed — '.$e->getMessage());
        }
    }

    /**
     * แจ้งเตือนลูกค้าเมื่อรอบเดินทางเหลือที่นั่งน้อย (3-2-1 ที่นั่ง):
     * ยิงทั้ง marketing broadcast (ทุกคน) และ trip alert (คนที่กดติดตามทริปนี้)
     */
    private function notifyLowSeats(TripSchedule $schedule): void
    {
        try {
            $this->broadcast->broadcastLowSeats($schedule);
            $this->tripAlerts->notifyLowSeats($schedule);
        } catch (\Throwable $e) {
            Log::warning('ScheduleSeatNotifier: low-seat notification failed — '.$e->getMessage());
        }
    }

    /**
     * แจ้งเตือนลูกค้าเมื่อรอบเดินทางเพิ่งเต็มทุกที่นั่ง เพื่อชวนเข้าคิว waitlist
     */
    private function notifySoldOut(TripSchedule $schedule): void
    {
        try {
            $this->broadcast->broadcastSoldOut($schedule);
            $this->tripAlerts->notifySoldOut($schedule);
            // คนที่จองไว้แล้วไม่ได้รับ broadcast ข้างบน — บอกกันในห้องแทน
            $this->chatEvents->soldOut($schedule);
        } catch (\Throwable $e) {
            Log::warning('ScheduleSeatNotifier: sold-out notification failed — '.$e->getMessage());
        }
    }

    /**
     * ยิง push เมื่อรอบข้ามแถบสถานะการันตีขึ้น (→ Almost Ready / → Guaranteed)
     * ถ้ากระโดดข้าม almost ไป guaranteed เลย ก็ยิงแค่ guaranteed
     */
    private function notifyDepartureStatusCrossing(TripSchedule $schedule, int $before, int $after): void
    {
        try {
            $guarantee = TripSchedule::guaranteeMinSeats();
            $almost = TripSchedule::ALMOST_READY_MIN_SEATS;

            if ($before < $guarantee && $after >= $guarantee) {
                $this->broadcast->broadcastGuaranteed($schedule);
                // ข่าวดีที่สุดของคนที่จองไปแล้ว = รอบนี้ออกแน่ ประกาศในห้องด้วย
                $this->chatEvents->departureGuaranteed($schedule);
            } elseif ($before < $almost && $after >= $almost && $after < $guarantee) {
                $this->broadcast->broadcastAlmostReady($schedule);
            }
        } catch (\Throwable $e) {
            Log::warning('ScheduleSeatNotifier: departure-status notification failed — '.$e->getMessage());
        }
    }

    /**
     * แจ้งเตือนแอดมิน/ออปเปอเรเตอร์ผ่าน FCM ว่ารอบนี้เต็มแล้ว
     */
    private function notifyStaffScheduleFull(TripSchedule $schedule, ?string $bookingRef): void
    {
        try {
            $tripTitle = $schedule->trip?->title ?? 'ทริป';
            $departure = $schedule->departureLabelThai();
            $title = 'รอบเดินทางเต็มแล้ว 🎉';
            $body = "{$tripTitle} รอบ {$departure} ถูกจองเต็มทุกที่นั่งแล้ว ({$schedule->total_seats} ที่นั่ง)";

            User::role(['admin', 'operator'])->each(function (User $staff) use ($title, $body, $schedule, $bookingRef) {
                SmartNotification::send(
                    $staff->id,
                    'schedule_full',
                    $title,
                    $body,
                    array_filter([
                        'schedule_id' => (string) $schedule->id,
                        'booking_ref' => $bookingRef,
                        'route' => 'admin.bookings',
                    ]),
                );
            });
        } catch (\Throwable $e) {
            Log::warning('ScheduleSeatNotifier: could not send schedule-full notification — '.$e->getMessage());
        }
    }
}
