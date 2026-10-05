<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\TripSchedule;
use Illuminate\Support\Collection;

/**
 * รายชื่อ "คนที่ไปจริง" ของรอบ — ระดับผู้โดยสาร ไม่ใช่ระดับบัญชี
 *
 * เช็คชื่อขึ้นรถและจัดห้องพักต้องนับคนที่นั่งอยู่บนรถจริง ซึ่งหลายคนไม่มีแอป
 * (เจ้าของใบจองจองให้ทั้งกลุ่ม) จึงอิง booking_passengers แล้วบอกว่าแต่ละคน
 * "ใครในแอปเป็นคนดูแล":
 * - ผู้โดยสารที่ผูกกับเพื่อนร่วมใบจอง (booking_members.passenger_id) → บัญชีนั้น
 * - ที่เหลือ → เจ้าของใบจอง (คนที่จองให้ทั้งกลุ่ม)
 */
class TripRosterService
{
    /** สถานะใบจองที่ถือว่า "ไปทริปนี้" — เหมือน manifest ของสตาฟ */
    public const STATUSES = ['confirmed', 'completed'];

    /**
     * @return Collection<int, array{passenger_id: int, booking_id: int, name: string, full_name: string, title: ?string, phone: ?string, user_id: ?int, is_join_trip: bool}>
     */
    public function passengers(TripSchedule $schedule, bool $includeJoinTrip = true): Collection
    {
        $bookings = Booking::where('schedule_id', $schedule->id)
            ->whereIn('status', self::STATUSES)
            ->when(! $includeJoinTrip, fn ($q) => $q->where(fn ($w) => $w->where('is_join_trip', false)->orWhereNull('is_join_trip')))
            ->with([
                'passengers:id,booking_id,title,name,nickname,phone',
                'user:id,phone',
            ])
            ->orderBy('id')
            ->get(['id', 'user_id', 'is_join_trip']);

        if ($bookings->isEmpty()) {
            return collect();
        }

        $linked = BookingMember::whereIn('booking_id', $bookings->pluck('id'))
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->whereNotNull('passenger_id')
            ->pluck('user_id', 'passenger_id');

        return $bookings->flatMap(fn (Booking $b) => $b->passengers->sortBy('id')->map(function ($p) use ($b, $linked) {
            $userId = $linked[$p->id] ?? $b->user_id;

            return [
                'passenger_id' => (int) $p->id,
                'booking_id' => (int) $b->id,
                'name' => $this->shortName($p->nickname, $p->name),
                'full_name' => trim((string) $p->name) ?: 'ผู้โดยสาร',
                'title' => $p->title,
                // เบอร์ของคนนั้นเอง ไม่มีก็โทรหาคนที่จองให้
                'phone' => $p->phone ?: $b->user?->phone,
                'user_id' => $userId ? (int) $userId : null,
                'is_join_trip' => (bool) $b->is_join_trip,
            ];
        }))->values();
    }

    /**
     * ผู้โดยสารที่บัญชีนี้ดูแล (ตัวเอง + คนในกลุ่มที่ตัวเองจองให้)
     *
     * @return Collection<int, int>
     */
    public function passengerIdsFor(TripSchedule $schedule, int $userId): Collection
    {
        return $this->passengers($schedule)
            ->where('user_id', $userId)
            ->pluck('passenger_id')
            ->values();
    }

    /**
     * ชื่อสั้นสำหรับเรียกกันในกลุ่ม — ชื่อเล่น ไม่มีก็ชื่อต้น (ไม่โชว์นามสกุลในห้องรวม)
     */
    public function shortName(?string $nickname, ?string $name): string
    {
        $nick = trim((string) $nickname);
        if ($nick !== '') {
            return $nick;
        }

        $first = preg_split('/\s+/u', trim((string) $name))[0] ?? '';

        return $first !== '' ? $first : 'ผู้โดยสาร';
    }

    /**
     * เพศจากคำนำหน้า สำหรับจัดห้องอัตโนมัติไม่ให้คนแปลกหน้าต่างเพศอยู่ห้องเดียวกัน
     */
    public function genderOf(?string $title): string
    {
        $t = mb_strtolower(trim((string) $title));
        $t = rtrim($t, '.');

        return match (true) {
            in_array($t, ['นาย', 'mr', 'ด.ช.', 'ด.ช', 'เด็กชาย', 'master'], true) => 'male',
            in_array($t, ['นาง', 'นางสาว', 'น.ส.', 'น.ส', 'mrs', 'ms', 'miss', 'ด.ญ.', 'ด.ญ', 'เด็กหญิง'], true) => 'female',
            default => 'unknown',
        };
    }
}
