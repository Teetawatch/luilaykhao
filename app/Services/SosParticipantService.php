<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\TripSchedule;
use Illuminate\Support\Collection;

/**
 * นิยาม "ใครอยู่ในรอบเดินทางนี้" สำหรับระบบ SOS — ที่เดียว
 *
 * ทั้งฝั่งกด (SosController) และฝั่งรับแจ้งเตือน (BroadcastSosAlert) ต้องใช้
 * รายชื่อชุดเดียวกัน เดิมสองฝั่งนิยามไม่ตรงกันจนเกิดช่องโหว่: เพื่อนร่วมใบจอง
 * (BookingMember) เห็นปุ่ม SOS ในแอปแต่กดแล้วได้ 404 และคนขับซึ่งผูกกับรอบ
 * ผ่าน vehicles.driver_user_id ไม่ใช่ pivot สตาฟ ก็ไม่เคยได้รับสัญญาณเลย
 */
class SosParticipantService
{
    /**
     * ทุกคนที่อยู่ในรอบนี้: ผู้เดินทาง (เจ้าของใบจอง + เพื่อนร่วมใบจอง),
     * สตาฟที่ยังรับผิดชอบรอบ และคนขับของรถที่ผูกกับรอบ
     *
     * @return Collection<int, int>
     */
    public function userIds(TripSchedule $schedule): Collection
    {
        return $this->travelerIds($schedule)
            ->merge($this->staffIds($schedule))
            ->merge(array_filter([$this->driverId($schedule)]))
            ->unique()
            ->values();
    }

    public function includes(TripSchedule $schedule, int $userId): bool
    {
        return $this->userIds($schedule)->contains($userId);
    }

    /**
     * ผู้เดินทางในรอบ — เจ้าของใบจองที่ยืนยันแล้ว บวกเพื่อนร่วมใบจองที่ตอบรับ
     * คำเชิญแล้ว (คนกลุ่มหลังเห็นใบจองในแอปเหมือนกัน จึงต้องกด SOS ได้เหมือนกัน)
     *
     * @return Collection<int, int>
     */
    public function travelerIds(TripSchedule $schedule): Collection
    {
        $bookingIds = Booking::where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->pluck('id');

        if ($bookingIds->isEmpty()) {
            return collect();
        }

        $ownerIds = Booking::whereIn('id', $bookingIds)->pluck('user_id');

        $companionIds = BookingMember::whereIn('booking_id', $bookingIds)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return $ownerIds->merge($companionIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * สตาฟที่ยังรับผิดชอบรอบนี้อยู่ — ไม่รวมคนที่ถูกปลดหลังรอบจบแล้ว
     *
     * @return Collection<int, int>
     */
    public function staffIds(TripSchedule $schedule): Collection
    {
        return $schedule->activeStaff()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /** คนขับของรอบ — ผูกผ่านรถ ไม่ใช่ตาราง schedule_staff_assignments */
    public function driverId(TripSchedule $schedule): ?int
    {
        $schedule->loadMissing('vehicle');

        $id = $schedule->vehicle?->driver_user_id;

        return $id ? (int) $id : null;
    }

    /**
     * ทางกลับของ [userIds] — รอบทั้งหมดที่ผู้ใช้คนนี้เกี่ยวข้อง ไม่ว่าจะในฐานะ
     * ผู้เดินทาง เพื่อนร่วมใบจอง สตาฟ หรือคนขับ
     *
     * @return Collection<int, int>
     */
    public function scheduleIdsFor(int $userId): Collection
    {
        $bookingIds = Booking::query()
            ->where('status', 'confirmed')
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhereIn('id', BookingMember::query()
                        ->where('user_id', $userId)
                        ->where('status', BookingMember::STATUS_ACTIVE)
                        ->select('booking_id'));
            })
            ->pluck('schedule_id');

        $staffScheduleIds = TripSchedule::query()
            ->whereHas('activeStaff', fn ($query) => $query->where('users.id', $userId))
            ->pluck('id');

        $drivenScheduleIds = TripSchedule::query()
            ->whereHas('vehicle', fn ($query) => $query->where('driver_user_id', $userId))
            ->pluck('id');

        return $bookingIds
            ->merge($staffScheduleIds)
            ->merge($drivenScheduleIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }
}
