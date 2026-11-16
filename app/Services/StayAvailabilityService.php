<?php

namespace App\Services;

use App\Models\Stay;
use App\Models\StayBooking;
use App\Models\StayExperience;
use App\Models\StayRoomNight;
use App\Models\StayRoomType;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * ห้องว่างและราคาของที่พัก — แหล่งเดียวที่ตอบว่า "คืนนี้เหลือกี่ห้อง ราคาเท่าไหร่"
 *
 * ห้องว่างของคืนหนึ่ง = units − ห้องที่เจ้าของปิด (stay_room_nights) − ห้องที่ใบจอง
 * ถือไว้ (StayBooking::HOLDING_STATUSES) ใบที่รอจ่ายแต่เลยเวลาแล้วไม่นับ แม้งาน
 * ปิดใบหมดเวลายังไม่ได้วิ่ง ห้องจะได้กลับมาขายทันที
 *
 * "คืน" คือคืนของวันที่นั้น — เข้าพัก 10 ออก 12 = คืนวันที่ 10 และ 11
 */
class StayAvailabilityService
{
    /** ราคาคืนวันศุกร์/เสาร์ ใช้ weekend_price เมื่อมี */
    private const WEEKEND_NIGHTS = [5, 6];

    /**
     * ห้องว่างน้อยที่สุดตลอดช่วงที่เข้าพัก
     */
    public function availableUnits(StayRoomType $room, string $checkIn, string $checkOut, ?int $exceptBookingId = null): int
    {
        $nights = $this->nightsBetween($checkIn, $checkOut);
        if ($nights === []) {
            return 0;
        }

        $perNight = $this->availabilityByNight($room, $nights[0], end($nights), $exceptBookingId);

        return (int) collect($nights)->map(fn (string $night) => $perNight[$night] ?? 0)->min();
    }

    /**
     * ห้องว่างรายคืนในช่วง [from, to] (รวมทั้งสองฝั่ง)
     *
     * @return array<string, int> night(Y-m-d) => ห้องว่าง
     */
    public function availabilityByNight(StayRoomType $room, string $from, string $to, ?int $exceptBookingId = null): array
    {
        $usage = $this->usageByNight($room, $from, $to, $exceptBookingId);
        $closed = $this->overrides($room, $from, $to)->map(fn (StayRoomNight $n) => (int) $n->closed_units);

        $out = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $night = $day->toDateString();
            $out[$night] = max(0, (int) $room->units - (int) ($closed[$night] ?? 0) - (int) ($usage[$night] ?? 0));
        }

        return $out;
    }

    /**
     * ห้องที่ใบจองถือไว้รายคืน
     *
     * @return array<string, int>
     */
    public function usageByNight(StayRoomType $room, string $from, string $to, ?int $exceptBookingId = null): array
    {
        $bookings = StayBooking::query()
            ->where('room_type_id', $room->id)
            ->whereIn('status', StayBooking::HOLDING_STATUSES)
            // ใบที่รอจ่ายแต่เลยเวลาแล้ว ไม่ถือห้องอีกต่อไป
            ->where(fn ($q) => $q->where('status', '!=', StayBooking::STATUS_AWAITING_PAYMENT)
                ->orWhereNull('payment_due_at')
                ->orWhere('payment_due_at', '>', now()))
            ->where('check_in', '<=', $to)
            ->where('check_out', '>', $from)
            ->when($exceptBookingId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->get(['id', 'check_in', 'check_out', 'units']);

        $usage = [];
        foreach ($bookings as $booking) {
            $last = $booking->check_out->copy()->subDay()->toDateString();
            foreach (CarbonPeriod::create($booking->check_in->toDateString(), $last) as $day) {
                $night = $day->toDateString();
                if ($night < $from || $night > $to) {
                    continue;
                }
                $usage[$night] = ($usage[$night] ?? 0) + (int) $booking->units;
            }
        }

        return $usage;
    }

    /**
     * ราคาต่อคืนของห้องหนึ่งห้อง
     *
     * @return array<string, float> night => ราคา
     */
    public function nightlyPrices(StayRoomType $room, string $checkIn, string $checkOut): array
    {
        $nights = $this->nightsBetween($checkIn, $checkOut);
        if ($nights === []) {
            return [];
        }

        $overrides = $this->overrides($room, $nights[0], end($nights));

        $prices = [];
        foreach ($nights as $night) {
            $override = $overrides[$night]->price_override ?? null;
            $isWeekend = in_array(CarbonImmutable::parse($night)->dayOfWeek, self::WEEKEND_NIGHTS, true);

            $prices[$night] = round((float) match (true) {
                $override !== null => $override,
                $isWeekend && $room->weekend_price !== null => $room->weekend_price,
                default => $room->price_per_night,
            }, 2);
        }

        return $prices;
    }

    /**
     * คิดยอดทั้งใบ — ใช้ทั้งตอนแสดงราคาและตอนสร้างใบจอง
     *
     * @param  array<int, array{id: int|string, people?: int|string|null}>  $experiences
     * @return array{nights: int, nightly_prices: array<string, float>, room_amount: float, extra_guests: int, extra_guest_amount: float, experiences: array<int, array<string, mixed>>, experiences_amount: float, total_amount: float, commission_percent: float, commission_amount: float, host_amount: float}
     *
     * @throws \Exception ห้องรับคนไม่พอ / วันไม่ถูกต้อง
     */
    public function quote(Stay $stay, StayRoomType $room, string $checkIn, string $checkOut, int $units, int $guests, array $experiences = []): array
    {
        $nightly = $this->nightlyPrices($room, $checkIn, $checkOut);
        $nights = count($nightly);

        if ($nights === 0) {
            throw new \Exception('วันออกต้องอยู่หลังวันเข้าพัก');
        }
        if ($guests > $room->max_guests * $units) {
            throw new \Exception("ห้อง {$room->name} {$units} ห้อง รับได้สูงสุด ".($room->max_guests * $units).' คน');
        }

        $roomAmount = round(array_sum($nightly) * $units, 2);
        $extraGuests = max(0, $guests - $room->base_guests * $units);
        $extraAmount = round($extraGuests * (float) $room->extra_guest_price * $nights, 2);

        $lines = $this->experienceLines($stay, $experiences, $guests);
        $experiencesAmount = round(array_sum(array_column($lines, 'amount')), 2);

        $total = round($roomAmount + $extraAmount + $experiencesAmount, 2);

        return [
            'nights' => $nights,
            'nightly_prices' => $nightly,
            'room_amount' => $roomAmount,
            'extra_guests' => $extraGuests,
            'extra_guest_amount' => $extraAmount,
            'experiences' => $lines,
            'experiences_amount' => $experiencesAmount,
            'total_amount' => $total,
        ] + self::split($total, (float) $stay->commission_percent);
    }

    /**
     * แบ่งยอดเป็นส่วนของบริษัทกับส่วนของชุมชน
     *
     * @return array{commission_percent: float, commission_amount: float, host_amount: float}
     */
    public static function split(float $total, float $commissionPercent): array
    {
        $commission = round($total * $commissionPercent / 100, 2);

        return [
            'commission_percent' => round($commissionPercent, 2),
            'commission_amount' => $commission,
            'host_amount' => round($total - $commission, 2),
        ];
    }

    /**
     * ปฏิทินรายเดือนของทุกห้อง — ใช้ในหน้าของเจ้าของที่พักและหลังบ้าน
     *
     * @return array<int, array<string, mixed>>
     */
    public function calendar(Stay $stay, string $from, string $to): array
    {
        return $stay->roomTypes()->get()->map(function (StayRoomType $room) use ($from, $to) {
            $usage = $this->usageByNight($room, $from, $to);
            $overrides = $this->overrides($room, $from, $to);
            $prices = $this->nightlyPrices($room, $from, CarbonImmutable::parse($to)->addDay()->toDateString());

            $days = [];
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $night = $day->toDateString();
                $closed = (int) ($overrides[$night]->closed_units ?? 0);
                $booked = (int) ($usage[$night] ?? 0);
                $days[] = [
                    'date' => $night,
                    'units' => (int) $room->units,
                    'closed' => $closed,
                    'booked' => $booked,
                    'available' => max(0, (int) $room->units - $closed - $booked),
                    'price' => $prices[$night] ?? (float) $room->price_per_night,
                    'price_override' => isset($overrides[$night]) && $overrides[$night]->price_override !== null
                        ? (float) $overrides[$night]->price_override : null,
                ];
            }

            return [
                'room_type_id' => $room->id,
                'name' => $room->name,
                'units' => (int) $room->units,
                'is_active' => (bool) $room->is_active,
                'days' => $days,
            ];
        })->values()->all();
    }

    /** @return array<int, string> คืนที่พัก (ไม่รวมวันออก) */
    public function nightsBetween(string $checkIn, string $checkOut): array
    {
        $in = CarbonImmutable::parse($checkIn)->startOfDay();
        $out = CarbonImmutable::parse($checkOut)->startOfDay();
        if ($out <= $in) {
            return [];
        }

        $nights = [];
        for ($day = $in; $day < $out; $day = $day->addDay()) {
            $nights[] = $day->toDateString();
        }

        return $nights;
    }

    /** @return Collection<string, StayRoomNight> keyed by Y-m-d */
    private function overrides(StayRoomType $room, string $from, string $to): Collection
    {
        return StayRoomNight::where('room_type_id', $room->id)
            ->whereBetween('night', [$from, $to])
            ->get()
            ->keyBy(fn (StayRoomNight $n) => $n->night->toDateString());
    }

    /**
     * @param  array<int, array{id: int|string, people?: int|string|null}>  $requested
     * @return array<int, array{id: int, title: string, price_per_person: float, people: int, amount: float}>
     */
    private function experienceLines(Stay $stay, array $requested, int $guests): array
    {
        if ($requested === []) {
            return [];
        }

        $catalog = StayExperience::where('stay_id', $stay->id)->where('is_active', true)->get()->keyBy('id');
        $lines = [];

        foreach ($requested as $item) {
            $experience = $catalog->get((int) ($item['id'] ?? 0));
            if (! $experience) {
                throw new \Exception('กิจกรรมที่เลือกไม่มีแล้ว กรุณาเลือกใหม่');
            }
            $people = (int) ($item['people'] ?? 0) ?: $guests;
            $people = max(1, min($people, 50));

            $lines[] = [
                'id' => $experience->id,
                'title' => $experience->title,
                'price_per_person' => (float) $experience->price_per_person,
                'people' => $people,
                'amount' => round((float) $experience->price_per_person * $people, 2),
            ];
        }

        return $lines;
    }
}
