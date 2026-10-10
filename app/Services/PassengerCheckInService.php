<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * เช็คอินรายคน — ที่เดียวที่เขียน booking_passengers.checked_in_at
 *
 * ใบจองหนึ่งใบมีได้หลายคน และคนในใบเดียวกันไม่ได้มาพร้อมกันเสมอไป (เพื่อนไม่มา
 * ขึ้นคนละจุด คนจองไม่มาแต่เพื่อนมา) ที่นี่จึงเช็คอินเป็นรายคน แล้วค่อยพลิก
 * bookings.checked_in เมื่อมีคนแรกขึ้นรถ — ค่านั้นยังแปลว่า "ใบนี้มีคนขึ้นรถแล้ว"
 * ให้การ์ดวันเดินทาง เหรียญ และโค้ดเดิมทุกจุดอ่านได้เหมือนเดิม
 *
 * ทางเก่าที่ยังเขียน bookings.checked_in ตรง ๆ (แอปสตาฟรุ่นก่อน, แอดมินแก้ใบจอง)
 * ถูก BookingObserver::syncPassengerCheckIn() ตามเก็บให้ ข้อมูลสองระดับจึงไม่ขัดกัน
 */
class PassengerCheckInService
{
    /**
     * หาใบจองจากรหัสที่สแกน — QR ของใบจอง (ทั้งกลุ่ม), QR รายคน หรือเลขที่จอง
     *
     * @return array{booking: Booking, passenger: BookingPassenger|null}|null
     */
    public function resolve(string $code, array $with = []): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $booking = Booking::with($with)
            ->where(fn ($q) => $q->where('qr_code', $code)->orWhere('booking_ref', $code))
            ->first();

        if ($booking) {
            return ['booking' => $booking, 'passenger' => null];
        }

        $passenger = BookingPassenger::where('qr_code', $code)->first();
        $booking = $passenger ? Booking::with($with)->find($passenger->booking_id) : null;

        if (! $booking) {
            return null;
        }

        // ใช้ตัวที่โหลดมากับใบจอง (ถ้ามี) เพื่อให้สถานะตรงกับที่ส่งกลับไปหน้าจอ
        $passenger = $booking->relationLoaded('passengers')
            ? ($booking->passengers->firstWhere('id', $passenger->id) ?? $passenger)
            : $passenger;

        return ['booking' => $booking, 'passenger' => $passenger];
    }

    /**
     * เช็คอินผู้โดยสารตาม id ที่ส่งมา — null = ทุกคนที่ยังรอขึ้นรถ (ไม่รวมคนที่แจ้งไม่ไป)
     *
     * คืน id ของคนที่เพิ่งขึ้นรถรอบนี้ (คนที่ขึ้นไปแล้วก่อนหน้าไม่นับซ้ำ) และใบจอง
     * เพิ่งพลิกเป็นเช็คอินแล้วหรือไม่
     *
     * @param  array<int, int>|null  $passengerIds
     * @return array{checked_in_ids: array<int, int>, booking_flipped: bool}
     *
     * @throws \InvalidArgumentException id ที่ไม่ใช่ผู้โดยสารของใบนี้
     */
    public function checkIn(Booking $booking, ?array $passengerIds, Carbon $at): array
    {
        return DB::transaction(function () use ($booking, $passengerIds, $at) {
            // ล็อกใบจองไว้ — สตาฟสองคนสแกนคนละคนในใบเดียวกันพร้อมกันได้ (QR รายคน)
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();
            $passengers = BookingPassenger::where('booking_id', $booking->id)->orderBy('id')->get();

            if ($passengerIds !== null) {
                $ids = array_values(array_unique(array_map('intval', $passengerIds)));
                $unknown = array_diff($ids, $passengers->pluck('id')->map(fn ($id) => (int) $id)->all());

                if ($unknown !== []) {
                    throw new \InvalidArgumentException('มีผู้เดินทางที่ไม่ได้อยู่ในใบจองนี้');
                }

                $targets = $passengers->whereIn('id', $ids);
            } else {
                $targets = $passengers->filter(fn (BookingPassenger $p) => $p->isAwaitingBoarding());
            }

            $newIds = [];

            foreach ($targets as $passenger) {
                if ($passenger->checked_in_at !== null) {
                    continue;
                }

                // มาจริงแล้ว — ที่เคยแจ้งไว้ว่าไม่ไปก็ไม่จริงอีกต่อไป
                $passenger->forceFill(['checked_in_at' => $at, 'not_going_at' => null])->save();
                $newIds[] = (int) $passenger->id;
            }

            // ใบจองเก่าที่ไม่มีรายชื่อผู้โดยสารแยก — เช็คอินได้แค่ระดับใบจองอย่างเดียว
            $shouldFlip = ! $locked->checked_in && ($newIds !== [] || $passengers->isEmpty());

            if ($shouldFlip) {
                $locked->update(['checked_in' => true, 'checked_in_at' => $at]);
                $booking->setRawAttributes($locked->getAttributes(), true);
            }

            return ['checked_in_ids' => $newIds, 'booking_flipped' => $shouldFlip];
        });
    }

    /**
     * สตาฟติ๊กผิดคน — ถอนเช็คอินของคนนี้ ถ้าไม่เหลือใครขึ้นรถแล้ว ใบจองก็กลับเป็นยังไม่เช็คอิน
     */
    public function undo(Booking $booking, BookingPassenger $passenger): void
    {
        DB::transaction(function () use ($booking, $passenger) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            $passenger->forceFill(['checked_in_at' => null])->save();

            $anyoneAboard = BookingPassenger::where('booking_id', $booking->id)
                ->whereNotNull('checked_in_at')
                ->exists();

            if (! $anyoneAboard && $locked->checked_in) {
                $locked->update(['checked_in' => false, 'checked_in_at' => null]);
                $booking->setRawAttributes($locked->getAttributes(), true);
            }
        });
    }

    /**
     * สรุปรายคนสำหรับหน้าจอสตาฟ
     *
     * @return array<int, array<string, mixed>>
     */
    public function roster(Collection $passengers): array
    {
        return $passengers
            ->sortBy('id')
            ->map(fn (BookingPassenger $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'name' => $p->name,
                'nickname' => $p->nickname,
                'display_name' => $p->displayName(),
                'checked_in' => $p->isCheckedIn(),
                'checked_in_at' => $p->checked_in_at?->toIso8601String(),
                'not_going' => $p->isNotGoing(),
            ])
            ->values()
            ->all();
    }
}
