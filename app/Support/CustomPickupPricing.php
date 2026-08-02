<?php

namespace App\Support;

use App\Models\SchedulePickupPoint;

/**
 * ราคาต่อคนของจุดรับที่ลูกค้าปักหมุดเอง
 *
 * จุดรับที่กำหนดไว้ถือ "ราคาเต็มของโซนนั้น" (ไม่ใช่ค่าบวกเพิ่ม) การปักหมุดเอง
 * ล้างจุดรับตายตัวทิ้ง ราคาจึงเคยร่วงกลับไปเป็นราคาฐานของรอบ ลูกค้าโซนไกลเลยได้
 * ราคาถูกลงเพียงเพราะปักหมุดเอง กติกาที่ใช้ตอนนี้: คิดเท่าราคาจุดรับที่ใกล้หมุด
 * ที่สุด และไม่ต่ำกว่าราคารอบเดินทาง
 *
 * กติกาเดียวกันถูกคัดลอกไว้ฝั่ง client เพื่อพรีวิวราคาให้ตรงกับที่เก็บจริง —
 * luilaykhao-app/lib/screens/booking_flow_helpers.part.dart และ
 * resources/js/pages/BookingPage.vue แก้ที่นี่แล้วต้องตามไปแก้ทั้งสองที่
 */
class CustomPickupPricing
{
    /**
     * @param  iterable<SchedulePickupPoint>  $points  จุดรับทั้งหมดของรอบนั้น
     */
    public static function resolvePrice(float $basePrice, iterable $points, float $lat, float $lng): float
    {
        $nearest = self::nearestPoint($points, $lat, $lng);

        return max($basePrice, (float) ($nearest?->price ?? 0));
    }

    /**
     * จุดรับที่ใกล้หมุดที่สุด — ข้ามจุดที่ยังไม่มีพิกัด (คำนวณระยะไม่ได้)
     *
     * @param  iterable<SchedulePickupPoint>  $points
     */
    public static function nearestPoint(iterable $points, float $lat, float $lng): ?SchedulePickupPoint
    {
        $nearest = null;
        $nearestDistance = null;

        foreach ($points as $point) {
            if ($point->latitude === null || $point->longitude === null) {
                continue;
            }

            $distance = self::distanceKm(
                $lat,
                $lng,
                (float) $point->latitude,
                (float) $point->longitude,
            );

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = $point;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    /** ระยะทางเส้นตรง (กิโลเมตร) — ใช้แค่จัดอันดับว่าจุดไหนใกล้กว่ากัน */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }
}
