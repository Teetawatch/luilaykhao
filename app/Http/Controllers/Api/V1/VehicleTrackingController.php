<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\VehicleLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
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

        // Get active schedule for auto-ETA info in broadcast
        $schedule = TripSchedule::with('trip')
            ->where('vehicle_id', $vehicle->id)
            ->whereDate('departure_date', today())
            ->whereNotIn('status', ['cancelled'])
            ->first();

        // Broadcast real-time event ผ่าน Laravel Reverb
        broadcast(new VehicleLocationUpdated(
            vehicleId: $vehicle->id,
            latitude: (float) $request->latitude,
            longitude: (float) $request->longitude,
            speed: $request->speed ? (float) $request->speed : null,
            heading: $request->heading ? (float) $request->heading : null,
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
            if (! isset($latestByVehicle[$vid]) || $location->recorded_at > $latestByVehicle[$vid]->recorded_at) {
                $latestByVehicle[$vid] = $location;
            }
        }

        // Broadcast เฉพาะตำแหน่งล่าสุดของแต่ละคัน
        foreach ($latestByVehicle as $vid => $location) {
            $vehicle = Vehicle::find($vid);
            if (! $vehicle) {
                continue;
            }

            $this->cacheCurrentLocation($vehicle, $location);

            // Get active schedule for auto-ETA info in broadcast
            $schedule = TripSchedule::with('trip')
                ->where('vehicle_id', $vehicle->id)
                ->whereDate('departure_date', today())
                ->whereNotIn('status', ['cancelled'])
                ->first();

            broadcast(new VehicleLocationUpdated(
                vehicleId: $vehicle->id,
                latitude: (float) $location->latitude,
                longitude: (float) $location->longitude,
                speed: $location->speed ? (float) $location->speed : null,
                heading: $location->heading ? (float) $location->heading : null,
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
        if (! empty($cached)) {
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
                // Get active schedule for auto-ETA
                $schedule = TripSchedule::with('trip')
                    ->where('vehicle_id', $vehicle->id)
                    ->whereDate('departure_date', today())
                    ->whereNotIn('status', ['cancelled'])
                    ->first();

                $data = [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->name,
                    'license_plate' => $vehicle->license_plate,
                    'type' => $vehicle->type,
                    'driver_phone' => $vehicle->driver_phone,
                    'driver_name' => $vehicle->driver_name,
                    'latitude' => $latest->latitude,
                    'longitude' => $latest->longitude,
                    'speed' => $latest->speed,
                    'heading' => $latest->heading,
                    'recorded_at' => $latest->recorded_at->toIso8601String(),
                    'dest_lat' => $schedule?->trip?->latitude,
                    'dest_lng' => $schedule?->trip?->longitude,
                    'trip_title' => $schedule?->trip?->title,
                ];
                $locations[] = $data;

                // Cache it
                $this->cacheCurrentLocationInternal($data);
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

        if (! $latest) {
            return $this->error('ไม่พบข้อมูลตำแหน่ง', 404);
        }

        // Get active schedule for auto-ETA
        $schedule = TripSchedule::with('trip')
            ->where('vehicle_id', $vehicleId)
            ->whereDate('departure_date', today())
            ->whereNotIn('status', ['cancelled'])
            ->first();

        $data = [
            'vehicle_id' => $vehicle->id,
            'vehicle_name' => $vehicle->name,
            'license_plate' => $vehicle->license_plate,
            'type' => $vehicle->type,
            'driver_phone' => $vehicle->driver_phone,
            'driver_name' => $vehicle->driver_name,
            'latitude' => $latest->latitude,
            'longitude' => $latest->longitude,
            'speed' => $latest->speed,
            'heading' => $latest->heading,
            'recorded_at' => $latest->recorded_at->toIso8601String(),
            'dest_lat' => $schedule?->trip?->latitude,
            'dest_lng' => $schedule?->trip?->longitude,
            'trip_title' => $schedule?->trip?->title,
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
        // Get active schedule for auto-ETA info in cache
        $schedule = TripSchedule::with('trip')
            ->where('vehicle_id', $vehicle->id)
            ->whereDate('departure_date', today())
            ->whereNotIn('status', ['cancelled'])
            ->first();

        $data = [
            'vehicle_id' => $vehicle->id,
            'vehicle_name' => $vehicle->name,
            'license_plate' => $vehicle->license_plate,
            'type' => $vehicle->type,
            'driver_phone' => $vehicle->driver_phone,
            'driver_name' => $vehicle->driver_name,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'speed' => $location->speed,
            'heading' => $location->heading,
            'recorded_at' => $location->recorded_at->toIso8601String(),
            'dest_lat' => $schedule?->trip?->latitude,
            'dest_lng' => $schedule?->trip?->longitude,
            'trip_title' => $schedule?->trip?->title,
        ];

        $this->cacheCurrentLocationInternal($data);
    }

    private function cacheCurrentLocationInternal(array $data): void
    {
        try {
            $json = json_encode($data);
            Redis::setex("vehicle:location:{$data['vehicle_id']}", 3600, $json);
            Redis::sadd('vehicle:active_ids', $data['vehicle_id']);
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
            if (empty($ids)) {
                return [];
            }

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
     * Guest lookup: ค้นหาการจองโดยไม่ต้องล็อกอิน ใช้ booking_ref + เบอร์โทร 4 หลักท้าย
     * POST /api/v1/bookings/guest-lookup  (public, no auth required)
     */
    public function guestLookup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_ref' => 'required|string',
            'phone' => 'required|string|min:4',
        ]);

        if ($validator->fails()) {
            return $this->error('กรุณากรอกรหัสการจองและเบอร์โทร', 422, $validator->errors());
        }

        $booking = Booking::with(['schedule.trip', 'schedule.vehicle', 'passengers', 'pickupPoint'])
            ->where('booking_ref', strtoupper(trim($request->booking_ref)))
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบข้อมูลการจอง กรุณาตรวจสอบรหัสอีกครั้ง', 404);
        }

        // ตรวจสอบเบอร์โทรด้วย 4 หลักท้าย
        $inputDigits = preg_replace('/\D/', '', $request->phone);
        $last4 = substr($inputDigits, -4);

        $matched = $booking->passengers->first(function ($p) use ($last4) {
            $digits = preg_replace('/\D/', '', $p->phone ?? '');

            return str_ends_with($digits, $last4);
        });

        if (! $matched) {
            return $this->error('เบอร์โทรไม่ตรงกับข้อมูลการจอง กรุณาตรวจสอบอีกครั้ง', 403);
        }

        $schedule = $booking->schedule;
        $trip = $schedule?->trip;
        $vehicle = $schedule?->vehicle;

        [$pickupLat, $pickupLng] = $this->resolvePickupCoords($booking);

        return $this->success([
            'booking_ref' => $booking->booking_ref,
            'status' => $booking->status,
            'qr_code' => $booking->qr_code,
            'trip_title' => $trip?->title ?? '',
            'departure_point' => $trip?->departure_point ?? '',
            'departure_date' => $schedule?->departure_date?->toDateString() ?? '',
            'schedule_id' => $booking->schedule_id,
            'vehicle_id' => $schedule?->vehicle_id,
            'driver_name' => $vehicle?->driver_name,
            'driver_phone' => $vehicle?->driver_phone,
            'license_plate' => $vehicle?->license_plate,
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'destination_lat' => $trip?->latitude,
            'destination_lng' => $trip?->longitude,
            'share_url' => $booking->shareUrl(),
        ], 'พบข้อมูลการจอง');
    }

    /**
     * Guest lookup by name: ค้นหาการจองด้วยชื่อผู้เดินทาง + เบอร์โทรเต็ม
     * ไม่เปิดเผย booking_ref / qr_code / share_url เพื่อความปลอดภัย
     * POST /api/v1/bookings/guest-lookup-by-name  (public, no auth required)
     */
    public function guestLookupByName(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2',
            'phone' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return $this->error('กรุณากรอกชื่อและเบอร์โทรให้ครบถ้วน', 422, $validator->errors());
        }

        $inputName = trim($request->name);
        $inputDigits = preg_replace('/\D/', '', $request->phone);

        $passengers = BookingPassenger::with([
            'booking.schedule.trip',
            'booking.schedule.vehicle',
            'booking.pickupPoint',
        ])
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($inputName)])
            ->get()
            ->filter(function ($p) use ($inputDigits) {
                $stored = preg_replace('/\D/', '', $p->phone ?? '');

                return $stored !== '' && str_ends_with($stored, substr($inputDigits, -8));
            });

        if ($passengers->isEmpty()) {
            return $this->error('ไม่พบข้อมูลการจองที่ตรงกับชื่อและเบอร์โทรนี้', 404);
        }

        $results = $passengers->map(function ($p) {
            $booking = $p->booking;
            $schedule = $booking?->schedule;
            $trip = $schedule?->trip;
            $vehicle = $schedule?->vehicle;

            [$pickupLat, $pickupLng] = $this->resolvePickupCoords($booking);

            return [
                'status' => $booking?->status,
                'trip_title' => $trip?->title ?? '',
                'departure_point' => $trip?->departure_point ?? '',
                'departure_date' => $schedule?->departure_date?->toDateString() ?? '',
                'schedule_id' => $booking?->schedule_id,
                'vehicle_id' => $schedule?->vehicle_id,
                'driver_name' => $vehicle?->driver_name,
                'driver_phone' => $vehicle?->driver_phone,
                'license_plate' => $vehicle?->license_plate,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'destination_lat' => $trip?->latitude,
                'destination_lng' => $trip?->longitude,
            ];
        })->values()->all();

        return $this->success($results, 'พบข้อมูลการจอง');
    }

    /**
     * ดึงข้อมูลการจอง + ข้อมูลรถ สำหรับ Customer Tracking App
     * GET /api/v1/bookings/{ref}/tracking  (ref = booking_ref เช่น LLK-20250409-0001)
     */
    public function bookingTracking(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::with(['schedule.trip', 'schedule.vehicle', 'pickupPoint'])
            ->where('booking_ref', $ref)
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบข้อมูลการจอง กรุณาตรวจสอบรหัสการจอง', 404);
        }

        $user = $request->user();
        $canViewAnyBooking = $user?->hasAnyRole(['admin', 'operator', 'staff']) ?? false;
        // เจ้าของ, เพื่อนที่ถูกเชิญ (companion) หรือสตาฟ/แอดมิน ดูได้
        $canView = $user && ($canViewAnyBooking || $booking->isAccessibleByUser($user->id));
        if (! $canView) {
            return $this->error('คุณไม่มีสิทธิ์ดูข้อมูลการจองนี้', 403);
        }

        $schedule = $booking->schedule;
        $trip = $schedule?->trip;
        $vehicle = $schedule?->vehicle;

        [$pickupLat, $pickupLng] = $this->resolvePickupCoords($booking);

        $data = [
            'id' => $booking->id,
            'booking_ref' => $booking->booking_ref,
            'schedule_id' => $booking->schedule_id,
            'vehicle_id' => $schedule?->vehicle_id,
            'trip_title' => $trip?->title ?? '',
            'departure_point' => $trip?->departure_point ?? '',
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'destination_lat' => $trip?->latitude,
            'destination_lng' => $trip?->longitude,
            'departure_date' => $schedule?->departure_date?->toDateString() ?? '',
            'status' => $booking->status,
            // Vehicle info for driver call button
            'driver_name' => $vehicle?->driver_name,
            'driver_phone' => $vehicle?->driver_phone,
            'license_plate' => $vehicle?->license_plate,
            'share_url' => $booking->shareUrl(),
        ];

        return $this->success($data, 'ข้อมูลการจองสำหรับติดตาม');
    }

    /**
     * Live Share Link: ติดตามรถแบบสาธารณะผ่าน share token (ไม่ต้องล็อกอิน)
     * GET /api/v1/track/{token}
     */
    public function sharedTracking(string $token): JsonResponse
    {
        $booking = Booking::with(['schedule.trip', 'schedule.vehicle', 'pickupPoint'])
            ->where('share_token', strtolower(trim($token)))
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบลิงก์ติดตามรถนี้ อาจหมดอายุหรือถูกยกเลิก', 404);
        }

        $schedule = $booking->schedule;
        $trip = $schedule?->trip;
        $vehicle = $schedule?->vehicle;

        [$pickupLat, $pickupLng] = $this->resolvePickupCoords($booking);
        $pickupName = $booking->pickupPoint?->pickup_location
            ?? $trip?->departure_point
            ?? '';

        $status = $booking->status;
        $payload = [
            'trip_title' => $trip?->title ?? 'ทริปของคุณ',
            'status' => $status,
            'departure_date' => $schedule?->departure_date?->toDateString() ?? '',
            'pickup' => [
                'name' => $pickupName,
                'lat' => $pickupLat,
                'lng' => $pickupLng,
            ],
            'vehicle' => null,
            'eta' => null,
            'trackable' => false,
            'message' => '',
        ];

        if (in_array($status, ['cancelled', 'refunded'], true)) {
            $payload['message'] = 'การจองนี้ถูกยกเลิกแล้ว';

            return $this->success($payload, 'ข้อมูลการติดตาม');
        }

        $tripDate = $schedule?->departure_date;
        if ($tripDate && ! $tripDate->isToday()) {
            $payload['message'] = $tripDate->isFuture()
                ? 'จะติดตามรถได้ในวันเดินทาง'
                : 'ทริปนี้สิ้นสุดแล้ว';

            return $this->success($payload, 'ข้อมูลการติดตาม');
        }

        $vehicleId = $schedule?->vehicle_id;
        $location = $vehicleId ? $this->resolveVehicleLocation($vehicleId) : null;

        if (! $vehicleId || ! $location) {
            $payload['message'] = 'รถยังไม่เริ่มส่งตำแหน่ง โปรดติดตามอีกครั้ง';

            return $this->success($payload, 'ข้อมูลการติดตาม');
        }

        $payload['vehicle'] = [
            'lat' => (float) $location['latitude'],
            'lng' => (float) $location['longitude'],
            'speed' => isset($location['speed']) ? (float) $location['speed'] : null,
            'heading' => isset($location['heading']) ? (float) $location['heading'] : null,
            'license_plate' => $vehicle?->license_plate,
            'driver_name' => $vehicle?->driver_name,
            'updated_at' => $location['recorded_at'] ?? null,
        ];

        if ($pickupLat !== null && $pickupLng !== null) {
            $eta = $this->haversineEta(
                (float) $location['latitude'],
                (float) $location['longitude'],
                (float) $pickupLat,
                (float) $pickupLng,
                isset($location['speed']) ? (float) $location['speed'] : null,
            );
            $payload['eta'] = $eta;
        }

        $payload['trackable'] = true;

        return $this->success($payload, 'ข้อมูลการติดตาม');
    }

    /**
     * ดึงตำแหน่งล่าสุดของรถ — อ่านจาก Redis ก่อน ถ้าไม่มี fallback ไป DB
     */
    private function resolveVehicleLocation(int $vehicleId): ?array
    {
        $cached = $this->getCachedLocation($vehicleId);
        if ($cached && isset($cached['latitude'], $cached['longitude'])) {
            return $cached;
        }

        $latest = VehicleLocation::where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at')
            ->first();

        if (! $latest) {
            return null;
        }

        return [
            'latitude' => $latest->latitude,
            'longitude' => $latest->longitude,
            'speed' => $latest->speed,
            'heading' => $latest->heading,
            'recorded_at' => $latest->recorded_at?->toIso8601String(),
        ];
    }

    /**
     * คืนพิกัดจุดรับของการจอง — ใช้ pickup point ของการจองก่อน ถ้าไม่มีค่อย fallback เป็นพิกัดทริป
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function resolvePickupCoords(Booking $booking): array
    {
        $point = $booking->pickupPoint;
        if ($point && $point->latitude !== null && $point->longitude !== null) {
            return [(float) $point->latitude, (float) $point->longitude];
        }

        $trip = $booking->schedule?->trip;

        return [$trip?->latitude, $trip?->longitude];
    }

    /**
     * ประมาณ ETA จากระยะ haversine — เร็ว ฟรี ไม่เรียก Google API
     *
     * @return array{minutes: int, distance_km: float}
     */
    private function haversineEta(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?float $speedKmh,
    ): array {
        $earthRadius = 6371.0; // km
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;
        $distanceKm = $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));

        // ใช้ความเร็วจริงถ้าเชื่อถือได้ ไม่งั้นสมมติ 35 km/h (รถวิ่งในเมือง/ต่างจังหวัด)
        $effectiveSpeed = ($speedKmh !== null && $speedKmh >= 8.0) ? $speedKmh : 35.0;
        $minutes = (int) round(($distanceKm / $effectiveSpeed) * 60);

        return [
            'minutes' => max($minutes, 0),
            'distance_km' => round($distanceKm, 2),
        ];
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
