<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;

/**
 * "ลูกค้าบอกสถานะตัวเองที่จุดนัด" — กำลังไป / ถึงแล้ว / อาจจะสาย
 *
 * เช้าวันเดินทางคือช่วงที่สตาฟทำสองอย่างพร้อมกันไม่ได้: จัดของขึ้นรถ กับไล่โทร
 * หาคนที่ยังไม่โผล่ ปุ่มสามปุ่มนี้ย้ายข้อมูลนั้นมาให้ฝั่งที่รู้คำตอบอยู่แล้ว
 * เป็นคนกด แล้วรายชื่อของสตาฟก็ตอบคำถาม "ครบหรือยัง" ได้เองโดยไม่ต้องโทร
 *
 * เจตนาที่ตั้งใจให้แคบ: นี่ไม่ใช่การเช็คอิน การกด "ถึงแล้ว" ไม่ทำให้ใครขึ้นรถ
 * และไม่แตะ `checked_in` — คนยืนยันว่าใครอยู่บนรถยังเป็นสตาฟเสมอ
 */
class PickupStatusService
{
    public function __construct(private SosParticipantService $participants) {}

    /**
     * บันทึกสถานะที่ลูกค้ากด แล้วแจ้งทีมงานเมื่อเป็นเรื่องที่ต้องตัดสินใจ
     *
     * @throws \Exception ข้อความภาษาไทยที่เอาไปแสดงให้ผู้ใช้ได้ตรง ๆ
     */
    public function report(Booking $booking, string $status, ?int $etaMinutes = null): Booking
    {
        if (! in_array($status, Booking::PICKUP_STATUSES, true)) {
            throw new \Exception('สถานะไม่ถูกต้อง');
        }

        $schedule = $booking->schedule;

        if (! $schedule) {
            throw new \Exception('ไม่พบรอบเดินทางของการจองนี้');
        }

        if ($booking->status !== 'confirmed') {
            throw new \Exception('ใบจองนี้ยังไม่ได้รับการยืนยัน จึงยังแจ้งสถานะไม่ได้');
        }

        if ($booking->checked_in) {
            throw new \Exception('เช็คอินขึ้นรถเรียบร้อยแล้ว ไม่ต้องแจ้งสถานะอีก');
        }

        if (! $this->isWithinWindow($schedule)) {
            throw new \Exception('แจ้งสถานะได้ตั้งแต่หนึ่งวันก่อนเดินทางจนถึงวันเดินทางเท่านั้น');
        }

        // "สายกี่นาที" มีความหมายเฉพาะตอนแจ้งว่าสาย — สถานะอื่นล้างทิ้งเสมอ
        // ไม่งั้นตัวเลขจากครั้งก่อนจะค้างอยู่กับข้อความที่ไม่เกี่ยวกับมันแล้ว
        $eta = $status === Booking::PICKUP_STATUS_LATE ? $etaMinutes : null;

        $previousStatus = $booking->pickup_status;
        $previousEta = $booking->pickup_status_eta_minutes;

        $booking->update([
            'pickup_status' => $status,
            'pickup_status_at' => now(),
            'pickup_status_eta_minutes' => $eta,
        ]);

        // แจ้งทีมงานเฉพาะ "สาย" เพราะเป็นอันเดียวที่ต้องตัดสินใจว่าจะรอหรือไม่
        // — และแจ้งเฉพาะตอนที่เนื้อหาเปลี่ยนจริง กดซ้ำด้วยตัวเลขเดิมไม่ต้องดังอีก
        if ($status === Booking::PICKUP_STATUS_LATE
            && ($previousStatus !== $status || $previousEta !== $eta)) {
            $this->notifyStaffOfDelay($booking, $schedule, $eta);
        }

        return $booking;
    }

    /**
     * ช่วงที่กดได้ — หนึ่งวันก่อนเดินทางถึงวันเดินทาง (เวลาไทย)
     *
     * เปิดตั้งแต่เย็นวันก่อนเพราะรอบที่รถออกเที่ยงคืนกว่า ๆ ลูกค้าออกจากบ้าน
     * ตั้งแต่ยังเป็นวันก่อนหน้า และปิดหลังวันเดินทางเพราะจุดนัดมีแค่ขาออก
     */
    public function isWithinWindow(TripSchedule $schedule): bool
    {
        $departure = $schedule->effectiveDepartureDate();

        if (! $departure) {
            return false;
        }

        $today = now(TripSchedule::REVIEW_AVAILABLE_TIMEZONE)->toDateString();

        return $today >= $departure->copy()->subDay()->toDateString()
            && $today <= $departure->toDateString();
    }

    /** ข้อความสั้นของสถานะ ใช้ทั้งในแจ้งเตือนและในรายชื่อของสตาฟ */
    public static function label(?string $status, ?int $etaMinutes = null): ?string
    {
        return match ($status) {
            Booking::PICKUP_STATUS_ON_THE_WAY => 'กำลังไปจุดนัด',
            Booking::PICKUP_STATUS_ARRIVED => 'ถึงจุดนัดแล้ว',
            Booking::PICKUP_STATUS_LATE => $etaMinutes
                ? "อาจสาย ~{$etaMinutes} นาที"
                : 'อาจมาสาย',
            default => null,
        };
    }

    private function notifyStaffOfDelay(Booking $booking, TripSchedule $schedule, ?int $eta): void
    {
        $name = trim((string) ($booking->passengers->first()?->name ?: $booking->user?->name));
        $who = $name !== '' ? $name : $booking->booking_ref;
        $point = $booking->pickupPoint?->pickup_location
            ?: $booking->pickupPoint?->region_label
            ?: $booking->custom_pickup_label;

        $body = $eta
            ? "{$who} แจ้งว่าอาจมาสายประมาณ {$eta} นาที"
            : "{$who} แจ้งว่าอาจมาสาย";

        if ($point) {
            $body .= " ที่จุด {$point}";
        }

        $body .= ' ('.$booking->booking_ref.')';

        $recipients = $this->participants->staffIds($schedule)
            ->push($this->participants->driverId($schedule))
            ->filter()
            ->unique();

        foreach ($recipients as $userId) {
            SmartNotification::send(
                (int) $userId,
                'pickup_late',
                'ลูกค้าแจ้งว่าอาจมาสาย',
                $body,
                [
                    'booking_ref' => $booking->booking_ref,
                    'schedule_id' => $schedule->id,
                    'trip_title' => $schedule->trip?->title,
                    'route' => 'staff_manifest',
                ],
            );
        }
    }
}
