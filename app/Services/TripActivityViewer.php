<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * การ์ดวันเดินทางใบนี้ "เป็นของใคร" ในใบจอง — จุดรับของเขา และเขาขึ้นรถแล้วหรือยัง
 *
 * ใบจองหนึ่งใบมีหลายคน ขึ้นคนละจุดได้ และเช็คอินเป็นรายคน แต่ `bookings.checked_in`
 * แปลว่า "มีอย่างน้อยหนึ่งคนขึ้นรถแล้ว" การ์ดที่อ่านค่าระดับใบจองจึงบอกเพื่อนที่ยัง
 * ยืนรอที่จุดสามว่า "ขึ้นรถเรียบร้อยแล้ว" ทันทีที่เจ้าของใบขึ้นที่จุดแรก
 *
 * กลุ่มของผู้ดู:
 *   เพื่อนที่ผูกที่นั่ง      → ที่นั่งของตัวเองที่นั่งเดียว
 *   เพื่อนที่ไม่ได้ผูกที่นั่ง → ทั้งใบ (ไม่รู้ว่าเขาคือใคร)
 *   เจ้าของ / ไม่ระบุ        → ทุกที่นั่งที่ไม่ได้เป็นของเพื่อนคนอื่น (ว่าง = ทั้งใบ)
 *
 * คนที่แจ้ง "ไปไม่ได้" ไม่นับ — ทั้งกลุ่มไม่ไปก็ไม่มีการ์ด ([resolve] คืน null)
 */
final class TripActivityViewer
{
    private function __construct(
        public readonly ?SchedulePickupPoint $point,
        /** @var array{lat: float, lng: float, label: string|null}|null */
        public readonly ?array $pin,
        /** ทุกคนในกลุ่ม (ที่ยังไป) ขึ้นรถแล้ว */
        public readonly bool $boarded,
        /** เวลาที่คนสุดท้ายของกลุ่มขึ้นรถ — นับ "เพิ่งขึ้นรถ" และ "ขึ้นมานานพอจะเริ่มขากลับ" */
        public readonly ?Carbon $checkedInAt,
    ) {}

    public static function resolve(Booking $booking, ?int $userId, PickupArrivalService $arrivals): ?self
    {
        $passengers = $booking->passengers;

        // ใบจองเก่าที่ไม่มีรายชื่อผู้โดยสารแยก — มีแต่ข้อมูลระดับใบจอง
        if ($passengers->isEmpty()) {
            return new self(
                $booking->pickupPoint,
                self::pinOf($booking->pickupPin()),
                (bool) $booking->checked_in,
                $booking->checked_in_at,
            );
        }

        $going = self::group($booking, $passengers, $userId)
            ->reject(fn (BookingPassenger $p) => $p->isNotGoing())
            ->values();

        if ($going->isEmpty()) {
            return null;
        }

        $waiting = $going->filter(fn (BookingPassenger $p) => $p->isAwaitingBoarding())->values();
        $checkedInAt = $going->pluck('checked_in_at')->filter()->max();

        [$point, $pin] = self::pickupOf($booking, $waiting->isNotEmpty() ? $waiting : $going, $arrivals);

        return new self($point, $pin, $waiting->isEmpty(), $checkedInAt);
    }

    public function name(): ?string
    {
        if ($this->point?->pickup_location) {
            return $this->point->pickup_location;
        }

        return ($this->pin['label'] ?? null) ?: null;
    }

    /** @return array{lat: float, lng: float}|null */
    public function coords(): ?array
    {
        if ($this->pin) {
            return ['lat' => $this->pin['lat'], 'lng' => $this->pin['lng']];
        }

        if ($this->point && $this->point->latitude !== null && $this->point->longitude !== null) {
            return ['lat' => (float) $this->point->latitude, 'lng' => (float) $this->point->longitude];
        }

        return null;
    }

    /** จุดรับตายตัวที่ VehicleEtaService คิด ETA ให้ — หมุดที่ปักเองไม่อยู่ในเส้นทางนั้น */
    public function routePointId(): ?int
    {
        return $this->pin === null && $this->point ? (int) $this->point->id : null;
    }

    /** กุญแจของ "จุดส่ง" ขากลับ — คนละจุดคือคนละขากลับ */
    public function dropoffKey(): string
    {
        if ($this->pin) {
            return 'pin:'.round($this->pin['lat'], 5).','.round($this->pin['lng'], 5);
        }

        return 'point:'.($this->point?->id ?? 'none');
    }

    /**
     * ที่นั่งของผู้ใช้คนนี้ในใบจอง — สมาชิก: ที่นั่งที่ตัวเองถือ, เจ้าของ: ที่นั่งที่
     * สมาชิกคนอื่นไม่ได้ถือ (ไม่มีให้แยก = ทั้งใบ) ใช้ร่วมกับ
     * ChatService::viewerLikelyForeign ให้สองฟีเจอร์นับ "ที่นั่งของฉัน" เหมือนกัน
     *
     * $fallback = false: แยกไม่ได้ (สมาชิกไม่ได้ถือที่นั่ง / เจ้าของที่ทุกที่นั่งมีคน
     * ถือไปแล้ว) ให้คืนว่าง แทนที่จะคืนทั้งใบอย่างการ์ดหน้าจอล็อก
     *
     * @return Collection<int, BookingPassenger>
     */
    public static function seatsOf(Booking $booking, ?int $userId, bool $fallback = true): Collection
    {
        $passengers = $booking->relationLoaded('passengers') ? $booking->passengers : $booking->passengers()->get();

        return $passengers->isEmpty() ? $passengers : self::group($booking, $passengers, $userId, $fallback);
    }

    /**
     * @param  Collection<int, BookingPassenger>  $passengers
     * @return Collection<int, BookingPassenger>
     */
    private static function group(Booking $booking, Collection $passengers, ?int $userId, bool $fallback = true): Collection
    {
        $members = ($booking->relationLoaded('members')
            ? $booking->members
            : $booking->members()->get())
            ->where('status', BookingMember::STATUS_ACTIVE);

        $ownerId = (int) $booking->user_id;

        if ($userId !== null && $userId !== $ownerId) {
            $seat = $members->firstWhere('user_id', $userId)?->passenger_id;
            $own = $seat ? $passengers->where('id', (int) $seat) : collect();

            return $own->isNotEmpty() || ! $fallback ? $own : $passengers;
        }

        $claimed = $members
            ->filter(fn (BookingMember $m) => $m->passenger_id && (int) $m->user_id !== $ownerId)
            ->pluck('passenger_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $mine = $passengers->reject(fn (BookingPassenger $p) => in_array((int) $p->id, $claimed, true));

        return $mine->isNotEmpty() || ! $fallback ? $mine : $passengers;
    }

    /**
     * จุดที่กลุ่มนี้ต้องไปยืนรอ — กลุ่มที่แยกกันขึ้นหลายจุดใช้จุดแรกตามเส้นทาง
     * (คนที่ขึ้นไปแล้วถูกคัดออกก่อนเรียก การ์ดจึงเลื่อนไปจุดถัดไปเอง)
     *
     * @param  Collection<int, BookingPassenger>  $passengers
     * @return array{0: SchedulePickupPoint|null, 1: array|null}
     */
    private static function pickupOf(Booking $booking, Collection $passengers, PickupArrivalService $arrivals): array
    {
        $points = $booking->schedule?->pickupPoints ?? collect();
        $order = $points->sortBy([['sort_order', 'asc'], ['id', 'asc']])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $validIds = $order;

        $bestPoint = null;
        $bestRank = PHP_INT_MAX;
        $pin = null;

        foreach ($passengers as $passenger) {
            if ($found = $passenger->pickupPin($booking)) {
                $pin ??= self::pinOf($found);

                continue;
            }

            $id = $arrivals->passengerPointId($booking, $passenger, $validIds);
            $rank = $id !== null ? array_search($id, $order, true) : false;

            if ($rank !== false && $rank < $bestRank) {
                $bestRank = $rank;
                $bestPoint = $points->firstWhere('id', $id);
            }
        }

        // มีทั้งจุดตายตัวและหมุด — จุดตายตัวรู้ลำดับในเส้นทาง หมุดไม่รู้
        return $bestPoint ? [$bestPoint, null] : [null, $pin];
    }

    /** @return array{lat: float, lng: float, label: string|null}|null */
    private static function pinOf(?array $pin): ?array
    {
        return $pin === null ? null : [
            'lat' => (float) $pin['lat'],
            'lng' => (float) $pin['lng'],
            'label' => $pin['label'] ?? null,
        ];
    }
}
