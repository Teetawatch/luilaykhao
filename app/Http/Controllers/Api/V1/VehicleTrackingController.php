<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\VehicleLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class VehicleTrackingController extends Controller
{
    use ApiResponse;

    /**
     * รับข้อมูล GPS จากมือถือคนขับ (single update)
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|exists:vehicles,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'speed' => 'nullable|numeric|min:0',
            'heading' => 'nullable|numeric|between:0,360',
            'accuracy' => 'nullable|numeric|min:0',
            'recorded_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->error('ข้อมูลไม่ถูกต้อง', 422, $validator->errors());
        }

        $vehicle = Vehicle::findOrFail($request->vehicle_id);
        $recordedAt = $request->recorded_at ?? now();

        // บันทึกลง MySQL (Geo-history)
        $location = VehicleLocation::create([
            'vehicle_id' => $vehicle->id,
            'user_id' => $request->user()?->id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'speed' => $request->speed,
            'heading' => $request->heading,
            'accuracy' => $request->accuracy,
            'recorded_at' => $recordedAt,
        ]);

        // เก็บตำแหน่งล่าสุดใน Redis (Current Location)
        $this->cacheCurrentLocation($vehicle, $location);

        // Broadcast real-time event ผ่าน Laravel Reverb
        broadcast(new VehicleLocationUpdated(
            vehicleId: $vehicle->id,
            latitude: (float) $request->latitude,
            longitude: (float) $request->longitude,
            speed: $request->speed ? (float) $request->speed : null,
            heading: $request->heading ? (float) $request->heading : null,
            vehicleName: $vehicle->name,
            licensePlate: $vehicle->license_plate ?? '',
            recordedAt: $location->recorded_at->toIso8601String(),
        ));

        return $this->success([
            'location_id' => $location->id,
            'recorded_at' => $location->recorded_at,
        ], 'อัปเดตตำแหน่งสำเร็จ');
    }

    /**
     * รับข้อมูล GPS แบบ batch (Offline Sync)
     */
    public function batchUpdateLocation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'locations' => 'required|array|min:1|max:100',
            'locations.*.vehicle_id' => 'required|exists:vehicles,id',
            'locations.*.latitude' => 'required|numeric|between:-90,90',
            'locations.*.longitude' => 'required|numeric|between:-180,180',
            'locations.*.speed' => 'nullable|numeric|min:0',
            'locations.*.heading' => 'nullable|numeric|between:0,360',
            'locations.*.accuracy' => 'nullable|numeric|min:0',
            'locations.*.recorded_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->error('ข้อมูลไม่ถูกต้อง', 422, $validator->errors());
        }

        $userId = $request->user()?->id;
        $inserted = 0;
        $latestByVehicle = [];

        foreach ($request->locations as $loc) {
            $location = VehicleLocation::create([
                'vehicle_id' => $loc['vehicle_id'],
                'user_id' => $userId,
                'latitude' => $loc['latitude'],
                'longitude' => $loc['longitude'],
                'speed' => $loc['speed'] ?? null,
                'heading' => $loc['heading'] ?? null,
                'accuracy' => $loc['accuracy'] ?? null,
                'recorded_at' => $loc['recorded_at'] ?? now(),
            ]);
            $inserted++;

            // เก็บเฉพาะตัวล่าสุดของแต่ละรถ
            $vid = $loc['vehicle_id'];
            if (!isset($latestByVehicle[$vid]) || $location->recorded_at > $latestByVehicle[$vid]->recorded_at) {
                $latestByVehicle[$vid] = $location;
            }
        }

        // Broadcast เฉพาะตำแหน่งล่าสุดของแต่ละคัน
        foreach ($latestByVehicle as $vid => $location) {
            $vehicle = Vehicle::find($vid);
            if (!$vehicle) continue;

            $this->cacheCurrentLocation($vehicle, $location);

            broadcast(new VehicleLocationUpdated(
                vehicleId: $vehicle->id,
                latitude: (float) $location->latitude,
                longitude: (float) $location->longitude,
                speed: $location->speed ? (float) $location->speed : null,
                heading: $location->heading ? (float) $location->heading : null,
                vehicleName: $vehicle->name,
                licensePlate: $vehicle->license_plate ?? '',
                recordedAt: $location->recorded_at->toIso8601String(),
            ));
        }

        return $this->success([
            'inserted' => $inserted,
        ], "บันทึก {$inserted} ตำแหน่งสำเร็จ");
    }

    /**
     * ดึงตำแหน่งล่าสุดของรถทุกคัน (สำหรับ Dashboard)
     */
    public function currentLocations(): JsonResponse
    {
        // ลองอ่านจาก Redis ก่อน
        $cached = $this->getAllCachedLocations();
        if (!empty($cached)) {
            return $this->success($cached, 'ตำแหน่งล่าสุดของรถทั้งหมด');
        }

        // Fallback: ดึงจาก DB
        $vehicles = Vehicle::all();
        $locations = [];

        foreach ($vehicles as $vehicle) {
            $latest = VehicleLocation::where('vehicle_id', $vehicle->id)
                ->orderByDesc('recorded_at')
                ->first();

            if ($latest) {
                $data = [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->name,
                    'license_plate' => $vehicle->license_plate,
                    'type' => $vehicle->type,
                    'latitude' => $latest->latitude,
                    'longitude' => $latest->longitude,
                    'speed' => $latest->speed,
                    'heading' => $latest->heading,
                    'recorded_at' => $latest->recorded_at->toIso8601String(),
                ];
                $locations[] = $data;

                // Cache it
                $this->cacheCurrentLocation($vehicle, $latest);
            }
        }

        return $this->success($locations, 'ตำแหน่งล่าสุดของรถทั้งหมด');
    }

    /**
     * ดึงตำแหน่งล่าสุดของรถคันเดียว
     */
    public function currentLocation(int $vehicleId): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($vehicleId);

        // ลองอ่านจาก Redis
        $cached = $this->getCachedLocation($vehicleId);
        if ($cached) {
            return $this->success($cached, 'ตำแหน่งล่าสุด');
        }

        $latest = VehicleLocation::where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at')
            ->first();

        if (!$latest) {
            return $this->error('ไม่พบข้อมูลตำแหน่ง', 404);
        }

        $data = [
            'vehicle_id' => $vehicle->id,
            'vehicle_name' => $vehicle->name,
            'license_plate' => $vehicle->license_plate,
            'type' => $vehicle->type,
            'latitude' => $latest->latitude,
            'longitude' => $latest->longitude,
            'speed' => $latest->speed,
            'heading' => $latest->heading,
            'recorded_at' => $latest->recorded_at->toIso8601String(),
        ];

        return $this->success($data, 'ตำแหน่งล่าสุด');
    }

    /**
     * ดึงประวัติการเดินทาง (Geo-history)
     */
    public function locationHistory(Request $request, int $vehicleId): JsonResponse
    {
        Vehicle::findOrFail($vehicleId);

        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('ข้อมูลไม่ถูกต้อง', 422, $validator->errors());
        }

        $query = VehicleLocation::where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at');

        if ($request->from) {
            $query->where('recorded_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('recorded_at', '<=', $request->to);
        }

        $limit = $request->limit ?? 200;
        $locations = $query->limit($limit)->get();

        return $this->success($locations, 'ประวัติการเดินทาง');
    }

    // ─── Redis Cache Helpers ──────────────────────────────────

    private function cacheCurrentLocation(Vehicle $vehicle, VehicleLocation $location): void
    {
        try {
            $data = json_encode([
                'vehicle_id' => $vehicle->id,
                'vehicle_name' => $vehicle->name,
                'license_plate' => $vehicle->license_plate,
                'type' => $vehicle->type,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'speed' => $location->speed,
                'heading' => $location->heading,
                'recorded_at' => $location->recorded_at->toIso8601String(),
            ]);

            Redis::setex("vehicle:location:{$vehicle->id}", 3600, $data);
            Redis::sadd('vehicle:active_ids', $vehicle->id);
        } catch (\Exception $e) {
            // Redis unavailable — continue without cache
        }
    }

    private function getCachedLocation(int $vehicleId): ?array
    {
        try {
            $data = Redis::get("vehicle:location:{$vehicleId}");
            return $data ? json_decode($data, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getAllCachedLocations(): array
    {
        try {
            $ids = Redis::smembers('vehicle:active_ids');
            if (empty($ids)) return [];

            $locations = [];
            foreach ($ids as $id) {
                $data = Redis::get("vehicle:location:{$id}");
                if ($data) {
                    $locations[] = json_decode($data, true);
                }
            }
            return $locations;
        } catch (\Exception $e) {
            return [];
        }
    }

    // ─── Customer App Endpoints ───────────────────────────────

    /**
     * ดึงข้อมูลการจอง + ข้อมูลรถ สำหรับ Customer Tracking App
     * GET /api/v1/bookings/{ref}/tracking  (ref = booking_ref เช่น LLK-20250409-0001)
     */
    public function bookingTracking(string $ref): JsonResponse
    {
        $booking = \App\Models\Booking::with(['schedule.trip', 'schedule.vehicle'])
            ->where('booking_ref', $ref)
            ->first();

        if (!$booking) {
            return $this->error('ไม่พบข้อมูลการจอง กรุณาตรวจสอบรหัสการจอง', 404);
        }

        $schedule = $booking->schedule;
        $trip     = $schedule?->trip;
        $vehicle  = $schedule?->vehicle;

        $data = [
            'id'              => $booking->id,
            'booking_ref'     => $booking->booking_ref,
            'schedule_id'     => $booking->schedule_id,
            'vehicle_id'      => $schedule?->vehicle_id,
            'trip_title'      => $trip?->title ?? '',
            'departure_point' => $trip?->departure_point ?? '',
            'pickup_lat'      => $trip?->latitude,
            'pickup_lng'      => $trip?->longitude,
            'departure_date'  => $schedule?->departure_date?->toDateString() ?? '',
            'status'          => $booking->status,
            // Vehicle info for driver call button
            'driver_name'     => $vehicle?->driver_name,
            'driver_phone'    => $vehicle?->driver_phone,
            'license_plate'   => $vehicle?->license_plate,
        ];

        return $this->success($data, 'ข้อมูลการจองสำหรับติดตาม');
    }

    // ─── Public Driver App Endpoints ─────────────────────────

    /**
     * รายการรถทั้งหมด (Public - สำหรับ Driver App)
     */
    public function vehicles(): JsonResponse
    {
        $vehicles = Vehicle::with('latestLocation')->orderBy('name')->get();

        $data = $vehicles->map(function ($v) {
            return [
                'id' => $v->id,
                'name' => $v->name,
                'type' => $v->type,
                'capacity' => $v->capacity,
                'license_plate' => $v->license_plate,
                'color' => $v->color,
                'driver_name' => $v->driver_name,
                'driver_phone' => $v->driver_phone,
                'driver_photo' => $v->driver_photo,
                'images' => $v->images ?? [],
            ];
        });

        return $this->success($data, 'รายการรถทั้งหมด');
    }

    /**
     * ดึง Schedule วันนี้ของรถคันนั้น (Public)
     */
    public function vehicleTodaySchedules(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        $schedules = TripSchedule::with('trip')
            ->where('vehicle_id', $id)
            ->whereDate('departure_date', today())
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('departure_date')
            ->get();

        $data = $schedules->map(function ($s) {
            return [
                'id' => $s->id,
                'trip_title' => $s->trip->title ?? '',
                'trip_location' => $s->trip->location ?? '',
                'departure_point' => $s->trip->departure_point ?? '',
                'destination_lat' => $s->trip->latitude,
                'destination_lng' => $s->trip->longitude,
                'departure_date' => $s->departure_date->toDateString(),
                'total_seats' => $s->total_seats,
                'booked_seats' => $s->booked_seats,
                'available_seats' => $s->available_seats,
                'status' => $s->status,
            ];
        });

        return $this->success($data, 'รอบเดินทางวันนี้');
    }
}
