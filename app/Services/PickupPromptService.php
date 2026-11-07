<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * "คุณอยู่ไหนแล้ว" ในห้องแชท — แถบบอกสถานะที่โผล่เฉพาะช่วงใกล้เวลารถมารับ
 *
 * ปุ่มกำลังไป/ถึงแล้ว/อาจสายเคยอยู่แค่หน้า "วันเดินทาง" ซึ่งอยู่ลึกสองชั้น ทั้งที่
 * เช้าวันเดินทางลูกค้าเปิดห้องแชทมากกว่าที่ไหน ที่นี่บอกแอปว่าใบจองไหนของคนนี้
 * ควรถูกถาม และ "ตอนนี้" ใช่ช่วงที่ควรถามหรือยัง — นับจากเวลารับของจุดที่เขา
 * ขึ้นจริง ไม่ใช่ทั้งวัน คนขึ้นหมอชิตตีสี่ครึ่งกับคนขึ้นรังสิตตีห้าเห็นไม่พร้อมกัน
 *
 * เวลาทั้งหมดเป็นนาฬิกาไทยแบบไม่มีโซน (departs_at เก็บเวลาไทยในคอลัมน์ UTC)
 * ห้ามเทียบกับ now() ตรง ๆ — ดู {@see TripActivityService}
 */
class PickupPromptService
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** เริ่มถามก่อนเวลารับเท่านี้ — ช่วงที่คนเริ่มออกจากบ้าน */
    public const OPENS_BEFORE_MINUTES = 90;

    /** ถามต่อหลังเวลารับเท่านี้ — รถมักช้ากว่าเวลาบนกระดาษ */
    public const CLOSES_AFTER_MINUTES = 60;

    /** จุดรับที่ไม่ได้ตั้งเวลา: นับจากเวลารถออกของรอบ ถามต่อได้นานกว่า เพราะไม่รู้ว่าจุดนี้ลำดับที่เท่าไหร่ */
    public const DEPARTS_CLOSES_AFTER_MINUTES = 180;

    /**
     * pickup_time ที่เช้ากว่าเวลารถออกเกินนี้ = เป็นของวันถัดไป (รอบที่ออกสี่ทุ่ม
     * แล้วแวะจุดสุดท้ายตีครึ่ง) — ไม่มีจุดไหนรับก่อนรถออกครึ่งวัน
     */
    private const NEXT_DAY_THRESHOLD_HOURS = 6;

    public function __construct(
        private PickupStatusService $status,
        private PickupArrivalService $arrivals,
        private PickupMatesService $mates,
    ) {}

    /**
     * สิ่งที่แถบในห้องแชทของผู้ใช้คนนี้ต้องรู้
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, TripSchedule $schedule): array
    {
        $booking = $this->bookingFor($user, $schedule);

        if (! $booking) {
            return ['visible' => false];
        }

        $point = $this->pointFor($booking, $user);
        $window = $this->window($schedule, $point);
        $now = $this->nowThai();
        $open = $window !== null && $now->gte($window['opens']) && $now->lte($window['closes']);

        // บอกช่วงเวลาไปด้วยแม้ยังไม่ถึง — แอปจะได้รู้ว่ายังมีอะไรรออยู่ข้างหน้า
        $base = $window ? [
            'opens_at' => $window['opens']->format('Y-m-d\TH:i:s'),
            'closes_at' => $window['closes']->format('Y-m-d\TH:i:s'),
        ] : [];

        if (! $open) {
            return ['visible' => false, ...$base];
        }

        $status = $booking->freshPickupStatus();
        $eta = $status === Booking::PICKUP_STATUS_LATE ? $booking->pickup_status_eta_minutes : null;
        [$people, $arrived] = $this->matesAt($booking, $point);

        return [
            'visible' => true,
            ...$base,
            'booking_ref' => $booking->booking_ref,
            'point_id' => $point?->id,
            'point_label' => $point ? $this->arrivals->label($point) : null,
            'pickup_time' => $point && $this->parseTime($point->pickup_time) ? trim((string) $point->pickup_time) : null,
            'status' => $status,
            'status_label' => PickupStatusService::label($status, $eta),
            'eta_minutes' => $eta,
            'mates_people' => $people,
            'mates_arrived' => $arrived,
        ];
    }

    /**
     * ช่วงที่ควรถาม (นาฬิกาไทย) — null เมื่อรอบนี้ไม่มีวันเดินทาง
     *
     * @return array{opens: Carbon, closes: Carbon}|null
     */
    public function window(TripSchedule $schedule, ?SchedulePickupPoint $point): ?array
    {
        $date = $schedule->effectiveDepartureDate();

        if (! $date) {
            return null;
        }

        $date = Carbon::parse($date->format('Y-m-d'));
        $departsAt = $schedule->departs_at ? Carbon::parse($schedule->departs_at->format('Y-m-d H:i:s')) : null;

        if ($point && ($time = $this->parseTime($point->pickup_time))) {
            $pickupAt = $date->copy()->setTime($time[0], $time[1]);

            if ($departsAt && $pickupAt->lt($departsAt->copy()->subHours(self::NEXT_DAY_THRESHOLD_HOURS))) {
                $pickupAt->addDay();
            }

            return [
                'opens' => $pickupAt->copy()->subMinutes(self::OPENS_BEFORE_MINUTES),
                'closes' => $pickupAt->copy()->addMinutes(self::CLOSES_AFTER_MINUTES),
            ];
        }

        if ($departsAt) {
            return [
                'opens' => $departsAt->copy()->subMinutes(self::OPENS_BEFORE_MINUTES),
                'closes' => $departsAt->copy()->addMinutes(self::DEPARTS_CLOSES_AFTER_MINUTES),
            ];
        }

        // ไม่รู้เวลาอะไรเลย — ถามช่วงเช้าของวันเดินทาง (รถส่วนใหญ่รับก่อนเที่ยง)
        return [
            'opens' => $date->copy(),
            'closes' => $date->copy()->setTime(12, 0),
        ];
    }

    /**
     * ใบจองของผู้ใช้คนนี้ในรอบนี้ที่ยังกดสถานะได้ — เจ้าของหรือเพื่อนที่ผูกเข้าใบ
     */
    private function bookingFor(User $user, TripSchedule $schedule): ?Booking
    {
        return Booking::with(['schedule.pickupPoints', 'passengers:'.PickupArrivalService::PASSENGER_PICKUP_COLUMNS])
            ->where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->where(fn ($q) => $q
                ->where('user_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m
                    ->where('user_id', $user->id)
                    ->where('status', BookingMember::STATUS_ACTIVE)))
            ->orderBy('id')
            ->get()
            ->first(fn (Booking $b) => $this->status->canReport($b)
                && $this->arrivals->effectivePickupPointIds($b, $this->validIds($schedule), awaitingOnly: true) !== []);
    }

    /**
     * จุดที่คนนี้ยืนรอจริง: เพื่อนที่ผูกที่นั่งไว้ใช้จุดของที่นั่งตัวเอง ที่เหลือใช้
     * จุดแรกตามเส้นทางที่ใบจองนี้ยังมีคนรอ
     */
    private function pointFor(Booking $booking, User $user): ?SchedulePickupPoint
    {
        $schedule = $booking->schedule;
        $validIds = $this->validIds($schedule);

        $passengerId = BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->value('passenger_id');

        if ($passengerId && ($passenger = $booking->passengers->firstWhere('id', $passengerId)) && $passenger->isAwaitingBoarding()) {
            $own = $this->arrivals->passengerPointId($booking, $passenger, $validIds);

            if ($own) {
                return $schedule->pickupPoints->firstWhere('id', $own);
            }
        }

        $ids = $this->arrivals->effectivePickupPointIds($booking, $validIds, awaitingOnly: true);

        return $schedule->pickupPoints
            ->whereIn('id', $ids)
            ->sortBy('sort_order')
            ->first();
    }

    /** @return array{0: int, 1: int} [เพื่อนที่ขึ้นจุดนี้, ที่ถึงแล้ว] ไม่นับตัวเอง */
    private function matesAt(Booking $booking, ?SchedulePickupPoint $point): array
    {
        if (! $point) {
            return [0, 0];
        }

        $payload = $this->mates->forBooking($booking);

        foreach ($payload['points'] ?? [] as $row) {
            if ((int) $row['id'] === (int) $point->id) {
                return [(int) $row['people'], (int) $row['arrived_people']];
            }
        }

        return [0, 0];
    }

    /** @return array{0: int, 1: int}|null */
    private function parseTime(?string $time): ?array
    {
        if (! preg_match('/^\s*(\d{1,2})[:.](\d{2})\s*$/', (string) $time, $m)) {
            return null;
        }

        $h = (int) $m[1];
        $i = (int) $m[2];

        return $h < 24 && $i < 60 ? [$h, $i] : null;
    }

    /** @return array<int, int> */
    private function validIds(TripSchedule $schedule): array
    {
        return $schedule->pickupPoints->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function nowThai(): Carbon
    {
        return Carbon::parse(now(self::TIMEZONE)->format('Y-m-d H:i:s'));
    }
}
