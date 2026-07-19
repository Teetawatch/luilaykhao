<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\GoogleDistanceService;
use App\Support\Polyline;
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

        if (! $result) {
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

        $destinations = $pickupPoints->map(fn ($pt) => [
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

        if (! $currentLocation) {
            return $this->error('ไม่พบตำแหน่งปัจจุบันของรถ', 404);
        }

        $result = $this->distanceService->getETA(
            (float) $currentLocation['latitude'],
            (float) $currentLocation['longitude'],
            (float) $request->dest_lat,
            (float) $request->dest_lng
        );

        if (! $result) {
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

        if (! $currentLocation) {
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

        $destinations = $pickupPoints->map(fn ($pt) => [
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
     * เส้นทางขับรถตามถนนจริง จากตำแหน่งรถไปยังจุดของลูกค้า (วาดเส้นบนแผนที่)
     * GET /api/v1/tracking/route?from_lat=&from_lng=&to_lat=&to_lng=
     */
    public function route(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_lat' => ['required', 'numeric', 'between:-90,90'],
            'from_lng' => ['required', 'numeric', 'between:-180,180'],
            'to_lat' => ['required', 'numeric', 'between:-90,90'],
            'to_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return $this->error('ข้อมูลไม่ถูกต้อง', 422, $validator->errors());
        }

        $result = $this->distanceService->getRoute(
            (float) $request->from_lat,
            (float) $request->from_lng,
            (float) $request->to_lat,
            (float) $request->to_lng,
        );

        // ไม่มีคีย์/หาเส้นทางไม่ได้ → คืน 200 พร้อม polyline ว่าง เพื่อให้แอป fallback เส้นตรง
        return $this->success($result ?? ['polyline' => '', 'distance' => 0, 'duration' => 0]);
    }

    /**
     * เส้นทางเดินรถของรอบทริป: จุดรับทุกจุด (เรียงลำดับจอด) → ปลายทางทริป
     * พร้อม polyline ตามถนนจริงสำหรับวาดบนแผนที่ + timeline จุดจอด
     * GET /api/v1/schedules/{id}/route
     */
    public function scheduleRoute(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['trip', 'pickupPoints'])->findOrFail($scheduleId);
        $trip = $schedule->trip;

        $stops = $schedule->pickupPoints->map(fn ($pt) => [
            'type' => 'pickup',
            'id' => $pt->id,
            'name' => $pt->pickup_location,
            'region_label' => $pt->region_label,
            'pickup_time' => $pt->pickup_time,
            'latitude' => $pt->latitude !== null ? (float) $pt->latitude : null,
            'longitude' => $pt->longitude !== null ? (float) $pt->longitude : null,
            'completed' => $pt->completed_at !== null,
        ])->values();

        $destLat = $trip?->latitude !== null ? (float) $trip->latitude : null;
        $destLng = $trip?->longitude !== null ? (float) $trip->longitude : null;
        if ($destLat !== null && $destLng !== null) {
            $stops->push([
                'type' => 'destination',
                'id' => null,
                'name' => $trip->location ?: $trip->title,
                'region_label' => null,
                'pickup_time' => null,
                'latitude' => $destLat,
                'longitude' => $destLng,
                'completed' => false,
            ]);
        }

        // เส้นทางที่แอดมินวาดเอง override เส้นจาก Google ทั้งหมด (ไม่เสียค่า API)
        $customPoints = $schedule->customRoutePoints();
        if (count($customPoints) >= 2) {
            return $this->success([
                'schedule_id' => $schedule->id,
                'trip_title' => $trip?->title,
                'stops' => $stops,
                'polyline' => Polyline::encode($customPoints),
                'distance' => Polyline::pathDistanceMeters($customPoints),
                'duration' => 0,
                'legs' => [],
                'source' => 'custom',
            ], 'ดึงเส้นทางเดินรถสำเร็จ');
        }

        // วาดเส้นเฉพาะจุดที่มีพิกัด (จุดรับที่ยังไม่ได้ปักหมุดยังโชว์ใน timeline ได้)
        $routedPoints = $stops
            ->filter(fn ($s) => $s['latitude'] !== null && $s['longitude'] !== null)
            ->map(fn ($s) => ['lat' => $s['latitude'], 'lng' => $s['longitude']])
            ->values()
            ->all();

        $route = count($routedPoints) >= 2
            ? $this->distanceService->getMultiStopRoute($routedPoints)
            : null;

        // ไม่มีคีย์/หาเส้นทางไม่ได้ → polyline ว่าง ให้แอปลากเส้นตรงระหว่างจุดเอง
        return $this->success([
            'schedule_id' => $schedule->id,
            'trip_title' => $trip?->title,
            'stops' => $stops,
            'polyline' => $route['polyline'] ?? '',
            'distance' => $route['distance'] ?? 0,
            'duration' => $route['duration'] ?? 0,
            'legs' => $route['legs'] ?? [],
            'source' => $route ? 'google' : 'none',
        ], 'ดึงเส้นทางเดินรถสำเร็จ');
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

        if (! $latest) {
            return null;
        }

        return [
            'latitude' => $latest->latitude,
            'longitude' => $latest->longitude,
        ];
    }
}
