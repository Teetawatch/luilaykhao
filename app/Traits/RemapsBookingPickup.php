<?php

namespace App\Traits;

use App\Models\Booking;
use App\Models\TripSchedule;

/**
 * ย้ายจุดรับของการจองให้เข้ากับรอบเดินทางปลายทาง — ใช้ร่วมกันทุกทางที่เปลี่ยน
 * schedule_id ของการจอง (ย้ายผู้โดยสาร, แก้ไขการจองจากแอดมิน, เปลี่ยนวันเดินทาง)
 *
 * จุดรับเป็นของ "รอบ" ไม่ใช่ของการจอง ถ้าปล่อย FK เดิมค้างไว้ การจองจะยังชี้จุดรับ
 * ของรอบเก่า ทำให้เวลารับ (pickup_time) และรายละเอียดจุดที่แสดงในแอปสตาฟ/คนขับ
 * เป็นเวลาของทริปเดิม ไม่ตรงกับรอบที่ลูกค้าเดินทางจริง
 */
trait RemapsBookingPickup
{
    /**
     * ตารางจับคู่จุดรับต้นทาง → ปลายทาง โดยดูจากชื่อจุดรับ (จุดที่ปลายทางไม่มี = ไม่มีในตาราง)
     *
     * @return array<int, int>
     */
    private function pickupPointMap(TripSchedule $source, TripSchedule $target): array
    {
        $source->loadMissing('pickupPoints');
        $target->loadMissing('pickupPoints');

        $map = [];
        foreach ($source->pickupPoints as $sourcePoint) {
            $targetPoint = $target->pickupPoints->firstWhere('pickup_location', $sourcePoint->pickup_location);
            if ($targetPoint) {
                $map[$sourcePoint->id] = $targetPoint->id;
            }
        }

        return $map;
    }

    /**
     * แปลงจุดรับระดับการจองให้เข้ากับรอบปลายทาง (รองรับย้ายข้ามทริป):
     * - จับคู่ได้ → ใช้จุดรับปลายทาง
     * - จับคู่ไม่ได้ → ล้าง FK ที่จะค้าง (ไม่ให้ชี้จุดรับของอีกรอบ) แต่คงชื่อภูมิภาคไว้เป็นข้อความ
     * - จุดรับปักหมุดเอง/ไม่มีจุดรับ → คงเดิม (ไม่ผูกกับรอบ)
     *
     * @param  array<int, int>  $pickupMap
     * @return array<string, mixed>
     */
    private function resolveMovedPickup(Booking $booking, TripSchedule $source, TripSchedule $target, array $pickupMap): array
    {
        if (! $booking->pickup_point_id) {
            return [];
        }

        if (isset($pickupMap[$booking->pickup_point_id])) {
            $target->loadMissing('pickupPoints');
            $targetPoint = $target->pickupPoints->firstWhere('id', $pickupMap[$booking->pickup_point_id]);

            return [
                'pickup_point_id' => $pickupMap[$booking->pickup_point_id],
                'pickup_region' => $targetPoint?->region ?: $booking->pickup_region,
            ];
        }

        $source->loadMissing('pickupPoints');
        $sourcePoint = $source->pickupPoints->firstWhere('id', $booking->pickup_point_id);

        return [
            'pickup_point_id' => null,
            'pickup_region' => $booking->pickup_region ?: $sourcePoint?->region,
        ];
    }

    /**
     * ผู้โดยสารแต่ละคนเลือกจุดรับของตัวเองได้ (booking_passengers.pickup_point_id)
     * ต้องย้ายตามการจองด้วย ไม่งั้นหน้าสตาฟจะจัดกลุ่มคนเหล่านี้ไปจุดรับของรอบเดิม
     * และแสดงเวลารับของทริปเดิม
     *
     * @param  array<int, int>  $pickupMap
     */
    private function remapPassengerPickupPoints(Booking $booking, array $pickupMap): void
    {
        foreach ($booking->passengers()->whereNotNull('pickup_point_id')->get() as $passenger) {
            $passenger->update([
                'pickup_point_id' => $pickupMap[$passenger->pickup_point_id] ?? null,
            ]);
        }
    }

    /**
     * ย้ายจุดรับทั้งระดับการจองและรายผู้โดยสารไปรอบปลายทางในครั้งเดียว
     * (การจองต้องอยู่บนรอบปลายทางแล้ว หรือกำลังจะถูกอัปเดตพร้อมกัน)
     *
     * @return array<string, mixed> ฟิลด์จุดรับระดับการจองที่ควรอัปเดต
     */
    private function remapBookingPickup(Booking $booking, TripSchedule $source, TripSchedule $target): array
    {
        $pickupMap = $this->pickupPointMap($source, $target);
        $this->remapPassengerPickupPoints($booking, $pickupMap);

        return $this->resolveMovedPickup($booking, $source, $target, $pickupMap);
    }
}
