<?php

namespace App\Services;

use App\Events\VehicleLocationUpdated;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * ทางเข้าออกทางเดียวของ "ตอนนี้รถอยู่ไหน"
 *
 * เดิมตรรกะนี้อยู่ในคอนโทรลเลอร์ของ endpoint สาธารณะที่ออกแบบไว้ให้กล่อง GPS /
 * แอปคนขับยิงเข้ามา แต่จริง ๆ แล้วคนขับไม่ได้ใช้แอป คนที่นั่งไปกับรถทุกรอบคือ
 * สตาฟ มือถือของสตาฟจึงเป็นแหล่งพิกัดของรถ และต้องเข้าทางที่ยืนยันตัวตนได้
 * (ดู StaffController::updateVehicleLocation) ไม่ใช่ทางสาธารณะเดิม
 *
 * ทุกเส้นทางที่บันทึกพิกัดต้องผ่าน [record] เพื่อให้สามอย่างนี้เกิดพร้อมกันเสมอ:
 * เก็บประวัติลงฐานข้อมูล, ปักตำแหน่งล่าสุดไว้ใน Redis (คนอ่านมากกว่าคนเขียนมาก)
 * และกระจาย event ให้แผนที่ของลูกค้าขยับเดี๋ยวนั้น
 */
class VehicleLocationService
{
    /** departs_at/departure_date เก็บเป็นเวลาไทย ส่วน app tz เป็น UTC */
    public const TIMEZONE = 'Asia/Bangkok';

    /** ตำแหน่งล่าสุดใน Redis อยู่ได้นานแค่ไหน (วินาที) */
    public const CACHE_TTL = 3600;

    /**
     * บันทึกพิกัดหนึ่งจุด + แคช + กระจาย event
     *
     * @param  array<string, mixed>  $data  latitude/longitude และของแถมที่มีบ้างไม่มีบ้าง
     */
    public function record(Vehicle $vehicle, array $data, ?int $userId = null): VehicleLocation
    {
        $location = VehicleLocation::create([
            'vehicle_id' => $vehicle->id,
            'user_id' => $userId,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'recorded_at' => $data['recorded_at'] ?? now(),
        ]);

        $this->broadcastLatest($vehicle, $location);

        return $location;
    }

    /** แคช + ยิง event ของพิกัดล่าสุด — แยกไว้เพื่อให้ batch ยิงแค่จุดท้ายสุดของแต่ละคัน */
    public function broadcastLatest(Vehicle $vehicle, VehicleLocation $location): void
    {
        $schedule = $this->activeScheduleFor($vehicle->id);

        $this->cache($vehicle, $location, $schedule);

        broadcast(new VehicleLocationUpdated(
            vehicleId: $vehicle->id,
            latitude: (float) $location->latitude,
            longitude: (float) $location->longitude,
            speed: $location->speed !== null ? (float) $location->speed : null,
            heading: $location->heading !== null ? (float) $location->heading : null,
            vehicleName: $vehicle->name,
            licensePlate: $vehicle->license_plate ?? '',
            type: $vehicle->type,
            recordedAt: $location->recorded_at->toIso8601String(),
            driverName: $vehicle->driver_name,
            driverPhone: $vehicle->driver_phone,
            destLat: $schedule?->trip?->latitude,
            destLng: $schedule?->trip?->longitude,
            tripTitle: $schedule?->trip?->title,
        ));
    }

    /**
     * รอบที่รถคันนี้กำลังวิ่งอยู่ตอนนี้ — ใช้ทั้งตอน broadcast, cache และหน้า dashboard
     * เพื่อให้ทุกทางเห็น "รอบที่กำลังเดินทาง" ตรงกัน (ทริปหลายวันก็ยังนับ)
     */
    public function activeScheduleFor(int $vehicleId): ?TripSchedule
    {
        return TripSchedule::with('trip')
            ->where('vehicle_id', $vehicleId)
            ->where('status', '!=', 'cancelled')
            ->inProgressOn(now(self::TIMEZONE))
            ->orderBy('departure_date')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(
        Vehicle $vehicle,
        ?VehicleLocation $latest,
        ?TripSchedule $schedule = null,
        ?\DateTimeInterface $trackingSince = null,
    ): array {
        return [
            'vehicle_id' => $vehicle->id,
            'vehicle_name' => $vehicle->name,
            'license_plate' => $vehicle->license_plate,
            'type' => $vehicle->type,
            'driver_phone' => $vehicle->driver_phone,
            'driver_name' => $vehicle->driver_name,
            'latitude' => $latest?->latitude,
            'longitude' => $latest?->longitude,
            'speed' => $latest?->speed,
            'heading' => $latest?->heading,
            'recorded_at' => $latest?->recorded_at->toIso8601String(),
            'dest_lat' => $schedule?->trip?->latitude,
            'dest_lng' => $schedule?->trip?->longitude,
            'trip_title' => $schedule?->trip?->title,
            'schedule_id' => $schedule?->id,
            'departure_date' => $schedule?->departure_date?->toDateString(),
            'return_date' => $schedule?->return_date?->toDateString(),
            'departs_at' => $schedule?->departs_at?->toIso8601String(),
            'tracking_since' => $trackingSince?->format('c'),
        ];
    }

    public function cache(Vehicle $vehicle, VehicleLocation $location, ?TripSchedule $schedule = null): void
    {
        $data = $this->format($vehicle, $location, $schedule ?? $this->activeScheduleFor($vehicle->id));

        try {
            Redis::setex("vehicle:location:{$vehicle->id}", self::CACHE_TTL, json_encode($data));
        } catch (\Exception $e) {
            // Redis ล่ม — ยังอ่านจากฐานข้อมูลได้ ไม่ต้องทำให้การบันทึกพัง
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cached(int $vehicleId): ?array
    {
        try {
            $data = Redis::get("vehicle:location:{$vehicleId}");

            return $data ? json_decode($data, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * ช่วงเวลาที่ยอมให้สตาฟแชร์ตำแหน่งรถของรอบนี้ (เวลาไทย)
     *
     * เปิดก่อนรถออก 12 ชม. เพราะหลายรอบสตาฟไปถึงจุดรวมพลตั้งแต่กลางดึก และปิด
     * หลังวันกลับ 6 ชม. — ตำแหน่งของคนที่นั่งรถกลับบ้านไปแล้วไม่ใช่ตำแหน่งของรถ
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function sharingWindow(TripSchedule $schedule): array
    {
        $departureDay = $schedule->departure_date
            ? $schedule->departure_date->copy()->startOfDay()
            : now(self::TIMEZONE)->startOfDay();

        $start = ($schedule->departs_at ? $schedule->departs_at->copy()->min($departureDay) : $departureDay)
            ->subHours(12);

        $end = ($schedule->return_date ?: $schedule->departure_date)
            ? ($schedule->return_date ?: $schedule->departure_date)->copy()->endOfDay()->addHours(6)
            : $departureDay->copy()->endOfDay()->addHours(6);

        return [$start, $end];
    }

    /** อยู่ในช่วงที่รถของรอบนี้ควรมีตำแหน่งให้ติดตามไหม */
    public function withinSharingWindow(TripSchedule $schedule, ?Carbon $now = null): bool
    {
        [$start, $end] = $this->sharingWindow($schedule);
        $now ??= now(self::TIMEZONE);

        return $now->betweenIncluded($start, $end);
    }
}
