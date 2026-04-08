<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\GoogleDistanceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class DistanceController extends Controller
{
    use ApiResponse;

    private GoogleDistanceService $distanceService;

    public function __construct(GoogleDistanceService $distanceService)
    {
        $this->distanceService = $distanceService;
    }

    /**
     * คำนวณระยะทางจากจุดหนึ่งไปอีกจุดหนึ่ง
     * POST /api/v1/distance
     */
    public function calculate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'dest_lat' => ['required', 'numeric', 'between:-90,90'],
            'dest_lng' => ['required', 'numeric', 'between:-180,180'],
            'mode' => ['nullable', 'in:driving,walking,bicycling,transit'],
        ]);

        if ($validator->fails()) {
            return $this->error('ข้อมูลไม่ถูกต้อง', 422, $validator->errors());
        }

        $result = $this->distanceService->getDistance(
            $request->origin_lat,
            $request->origin_lng,
            $request->dest_lat,
            $request->dest_lng,
            $request->mode ?? 'driving'
        );

        if (!$result) {
            return $this->error('ไม่สามารถคำนวณระยะทางได้', 500);
        }

        return $this->success($result, 'คำนวณระยะทางสำเร็จ');
    }

    /**
     * คำนวณระยะทางจากตำแหน่งผู้ใช้ไปยังจุดรับทุกจุดของ schedule
     * GET /api/v1/schedules/{id}/pickup-distances?lat=xxx&lng=xxx
     */
    public function pickupDistances(Request $request, int $scheduleId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return $this->error('ข้อมูลไม่ถูกต้อง', 422, $validator->errors());
        }

        $schedule = TripSchedule::findOrFail($scheduleId);
        $pickupPoints = $schedule->pickupPoints()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('sort_order')
            ->get();

        if ($pickupPoints->isEmpty()) {
            return $this->success([], 'ไม่มีจุดรับที่มีพิกัด');
        }

        $destinations = $pickupPoints->map(fn($pt) => [
            'id' => $pt->id,
            'lat' => (float) $pt->latitude,
            'lng' => (float) $pt->longitude,
        ])->toArray();

        $distances = $this->distanceService->getDistances(
            (float) $request->lat,
            (float) $request->lng,
            $destinations
        );

        // merge ข้อมูล pickup point เข้ากับ distance
        $result = $pickupPoints->map(function ($pt) use ($distances) {
            $distInfo = collect($distances)->firstWhere('id', $pt->id);
            return [
                'pickup_point_id' => $pt->id,
                'region' => $pt->region,
                'region_label' => $pt->region_label,
                'pickup_location' => $pt->pickup_location,
                'price' => $pt->price,
                'latitude' => $pt->latitude,
                'longitude' => $pt->longitude,
                'distance' => $distInfo['distance'] ?? null,
                'duration' => $distInfo['duration'] ?? null,
            ];
        });

        return $this->success($result, 'คำนวณระยะทางถึงจุดรับสำเร็จ');
    }

    /**
     * คำนวณ ETA ของรถถึงจุดหมาย
     * GET /api/v1/tracking/{vehicleId}/eta?dest_lat=xxx&dest_lng=xxx
     */
    public function vehicleETA(Request $request, int $vehicleId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dest_lat' => ['required', 'numeric', 'between:-90,90'],
            'dest_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return $this->error('ข้อมูลไม่ถูกต้อง', 422, $validator->errors());
        }

        $vehicle = Vehicle::findOrFail($vehicleId);

        // ดึงตำแหน่งล่าสุดจาก Redis ก่อน
        $currentLocation = $this->getVehicleCurrentLocation($vehicleId);

        if (!$currentLocation) {
            return $this->error('ไม่พบตำแหน่งปัจจุบันของรถ', 404);
        }

        $result = $this->distanceService->getETA(
            (float) $currentLocation['latitude'],
            (float) $currentLocation['longitude'],
            (float) $request->dest_lat,
            (float) $request->dest_lng
        );

        if (!$result) {
            return $this->error('ไม่สามารถคำนวณ ETA ได้', 500);
        }

        $result['vehicle_id'] = $vehicle->id;
        $result['vehicle_name'] = $vehicle->name;
        $result['current_location'] = [
            'latitude' => (float) $currentLocation['latitude'],
            'longitude' => (float) $currentLocation['longitude'],
        ];

        return $this->success($result, 'คำนวณ ETA สำเร็จ');
    }

    /**
     * คำนวณ ETA ของรถไปยังจุดรับทุกจุดของ schedule
     * GET /api/v1/tracking/{vehicleId}/eta/schedule/{scheduleId}
     */
    public function vehicleETAToPickups(int $vehicleId, int $scheduleId): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($vehicleId);
        $schedule = TripSchedule::findOrFail($scheduleId);

        $currentLocation = $this->getVehicleCurrentLocation($vehicleId);

        if (!$currentLocation) {
            return $this->error('ไม่พบตำแหน่งปัจจุบันของรถ', 404);
        }

        $pickupPoints = $schedule->pickupPoints()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('sort_order')
            ->get();

        if ($pickupPoints->isEmpty()) {
            return $this->success([], 'ไม่มีจุดรับที่มีพิกัด');
        }

        $destinations = $pickupPoints->map(fn($pt) => [
            'id' => $pt->id,
            'lat' => (float) $pt->latitude,
            'lng' => (float) $pt->longitude,
        ])->toArray();

        $distances = $this->distanceService->getDistances(
            (float) $currentLocation['latitude'],
            (float) $currentLocation['longitude'],
            $destinations
        );

        $result = $pickupPoints->map(function ($pt) use ($distances) {
            $distInfo = collect($distances)->firstWhere('id', $pt->id);
            return [
                'pickup_point_id' => $pt->id,
                'region_label' => $pt->region_label,
                'pickup_location' => $pt->pickup_location,
                'latitude' => $pt->latitude,
                'longitude' => $pt->longitude,
                'distance' => $distInfo['distance'] ?? null,
                'duration' => $distInfo['duration'] ?? null,
            ];
        });

        return $this->success([
            'vehicle_id' => $vehicle->id,
            'vehicle_name' => $vehicle->name,
            'current_location' => [
                'latitude' => (float) $currentLocation['latitude'],
                'longitude' => (float) $currentLocation['longitude'],
            ],
            'pickup_etas' => $result,
        ], 'คำนวณ ETA ถึงจุดรับสำเร็จ');
    }

    /**
     * ดึงตำแหน่งปัจจุบันจาก Redis หรือ DB
     */
    private function getVehicleCurrentLocation(int $vehicleId): ?array
    {
        try {
            $cached = Redis::get("vehicle:location:{$vehicleId}");
            if ($cached) {
                return json_decode($cached, true);
            }
        } catch (\Exception $e) {
            // Redis unavailable
        }

        // Fallback to DB
        $latest = VehicleLocation::where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at')
            ->first();

        if (!$latest) {
            return null;
        }

        return [
            'latitude' => $latest->latitude,
            'longitude' => $latest->longitude,
        ];
    }
}
