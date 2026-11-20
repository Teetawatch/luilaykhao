<?php

namespace App\Services\Maps;

use App\Events\VehicleEtaUpdated;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Services\GoogleDistanceService;
use App\Services\PickupArrivalService;
use App\Services\VehicleLocationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ETA ร่วมของรถไปยังจุดรับที่ยังมีคนรอ — คิดที่เซิร์ฟเวอร์ครั้งเดียวต่อรถ แล้วแชร์
 *
 * เดิมแอปลูกค้าแต่ละคนขอเส้นทางจากรถไปหาตัวเองทุก ~25 วิ = ลูกค้า 10 คนที่เปิดจอ
 * ทิ้งไว้ชั่วโมงเดียวคือคำขอ Google เกินพันครั้ง ตอนนี้:
 *
 * - หนึ่งคำขอ computeRoutes ต่อรถ: รถ → จุดรับที่ยังรอ (เรียงลำดับจอด) ได้ทั้งเส้นทาง
 *   ตามถนนจริงและเวลาถึงทุกจุดในคราวเดียว
 * - ถามใหม่ทุก `maps.eta.refresh_seconds` (3–5 นาที) และเฉพาะเมื่อรถขยับจริง —
 *   รถจอดนิ่งแค่เลื่อนเวลาถึงตามนาฬิกา (ฟรี); จุดรับเปลี่ยน (รับครบหนึ่งจุด) ถามทันที
 * - แชร์ผ่าน Reverb (`eta.updated` บนช่องของรถ) + GET tracking/schedules/{id}/eta
 * - หยุดเมื่อทริปจบ/ยกเลิก, รับครบทุกจุด, สตาฟปิดแชร์ตำแหน่ง, นอกกรอบเวลาเดินทาง
 *   หรือพิกัดรถเงียบเกิน 20 นาที
 *
 * ไม่มีคีย์/ชนเพดาน/Google ล่ม = ค่าประมาณจากเส้นตรง (`source` = estimate) แอปยังมี ETA
 */
class VehicleEtaService
{
    private const CACHE_PREFIX = 'vehicle_eta:';

    private const CACHE_TTL = 6 * 3600;

    /** ห่างกันไม่ถึงนี้ห้ามถาม Google ซ้ำ แม้จุดรับจะเปลี่ยนรัว ๆ (วินาที) */
    private const MIN_GAP_SECONDS = 60;

    /** ETA เก่ากว่านี้ไม่นับว่าสด (นาที) — ผู้ใช้ภายนอกถอยไปคิดเอง */
    public const FRESH_MINUTES = 10;

    public function __construct(
        private GoogleRoutesClient $routes,
        private VehicleLocationService $locations,
        private PickupArrivalService $pickups,
    ) {}

    /**
     * รอบที่ควรมี ETA ตอนนี้ — วิ่งจาก `tracking:refresh-eta` ทุกนาที
     *
     * @return array{google: int, reanchored: int, estimated: int, stopped: int, unchanged: int}
     */
    public function refreshAll(): array
    {
        $counts = ['google' => 0, 'reanchored' => 0, 'estimated' => 0, 'stopped' => 0, 'unchanged' => 0];
        $today = now(VehicleLocationService::TIMEZONE);

        $schedules = TripSchedule::with(['trip', 'vehicle', 'pickupPoints'])
            ->whereNotNull('vehicle_id')
            ->where('transport_type', '!=', TripSchedule::TRANSPORT_FLIGHT)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where(fn ($q) => $q
                ->inProgressOn($today)
                ->orWhere(fn ($q2) => $q2->departingOn($today->toDateString())))
            ->get()
            ->filter(fn (TripSchedule $s) => $s->vehicle !== null);

        foreach ($schedules as $schedule) {
            try {
                $outcome = $this->refresh($schedule)['outcome'];
            } catch (\Throwable $e) {
                Log::error('Vehicle ETA refresh failed', ['schedule_id' => $schedule->id, 'error' => $e->getMessage()]);

                continue;
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * คิด/ต่ออายุ ETA ของรอบเดียว
     *
     * @return array{outcome: string, snapshot: array<string, mixed>}
     */
    public function refresh(TripSchedule $schedule, bool $force = false): array
    {
        $prev = $this->snapshot($schedule->id);
        $vehicle = $schedule->vehicle ?? Vehicle::find($schedule->vehicle_id);
        $location = $vehicle ? $this->locations->latestForSchedule($vehicle, $schedule) : null;
        $pending = $this->pendingStops($schedule);

        if ($reason = $this->stopReason($schedule, $location, $pending)) {
            $snapshot = [
                'schedule_id' => $schedule->id,
                'vehicle_id' => (int) $schedule->vehicle_id,
                'active' => false,
                'reason' => $reason,
                'updated_at' => now()->toIso8601String(),
            ];

            // ประกาศเฉพาะตอนเปลี่ยนสถานะ ไม่ใช่ทุกนาที
            if (! $prev || ($prev['active'] ?? false) || ($prev['reason'] ?? null) !== $reason) {
                $this->store($snapshot);

                return ['outcome' => 'stopped', 'snapshot' => $snapshot];
            }

            return ['outcome' => 'unchanged', 'snapshot' => $prev];
        }

        $origin = ['lat' => (float) $location['latitude'], 'lng' => (float) $location['longitude']];
        $stopsKey = $pending->pluck('pickup_point_id')->implode(',');

        $age = $prev && isset($prev['computed_at'])
            ? now()->diffInSeconds(Carbon::parse($prev['computed_at']), true)
            : PHP_INT_MAX;
        $moved = $prev && isset($prev['origin'])
            ? GoogleDistanceService::haversineMeters($prev['origin']['lat'], $prev['origin']['lng'], $origin['lat'], $origin['lng'])
            : PHP_FLOAT_MAX;

        $refresh = $this->refreshSeconds();
        $sameStops = $prev && ($prev['active'] ?? false) && ($prev['stops_key'] ?? null) === $stopsKey;

        $wantsGoogle = $force
            || ! $sameStops
            || ($age >= $refresh && $moved >= (int) config('maps.eta.moved_meters', 150))
            || $age >= (int) config('maps.eta.idle_refresh_seconds', 900)
            // เคยถอยไปใช้ค่าประมาณ (ชนเพดาน/ล่ม) — ลองใหม่ตามรอบปกติ
            || (($prev['source'] ?? null) !== 'google' && $age >= $refresh);

        if ($wantsGoogle && $age < self::MIN_GAP_SECONDS && ! $force) {
            $wantsGoogle = false;
        }

        if ($wantsGoogle) {
            $snapshot = $this->fromGoogle($schedule, $origin, $pending);
            if ($snapshot) {
                $this->store($snapshot);

                return ['outcome' => 'google', 'snapshot' => $snapshot];
            }

            // Google ใช้ไม่ได้: มีของเดิมจุดชุดเดียวกันก็เลื่อนเวลาต่อ ดีกว่าเส้นตรง
            if ($sameStops && ($prev['source'] ?? null) === 'google' && $age < 2 * (int) config('maps.eta.idle_refresh_seconds', 900)) {
                return $this->reanchor($prev);
            }

            $snapshot = $this->estimate($schedule, $origin, $pending, $location);
            $this->store($snapshot);

            return ['outcome' => 'estimated', 'snapshot' => $snapshot];
        }

        // รถยังไม่ขยับพอ: เวลาเดินทางเท่าเดิม แค่นับจากตอนนี้ (ไม่เสียค่า API)
        if ($sameStops && now()->diffInSeconds(Carbon::parse($prev['updated_at']), true) >= $refresh) {
            return $this->reanchor($prev);
        }

        return ['outcome' => 'unchanged', 'snapshot' => $prev];
    }

    /**
     * ETA ที่เก็บไว้ของรอบนี้ — สำหรับ API ฝั่งแอป
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(int $scheduleId): ?array
    {
        try {
            $value = Cache::get(self::CACHE_PREFIX.$scheduleId);
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * รูปที่ส่งออก — นาทีคิดสดจาก arrive_at ทุกครั้ง เลยไม่ต้องยิงใหม่ทุกนาที
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function present(array $snapshot, bool $withPolylines = true): array
    {
        $out = [
            'schedule_id' => $snapshot['schedule_id'],
            'vehicle_id' => $snapshot['vehicle_id'],
            'active' => (bool) ($snapshot['active'] ?? false),
            'reason' => $snapshot['reason'] ?? null,
            'source' => $snapshot['source'] ?? null,
            'traffic_aware' => (bool) ($snapshot['traffic_aware'] ?? false),
            'computed_at' => $snapshot['computed_at'] ?? null,
            'updated_at' => $snapshot['updated_at'] ?? null,
            'refresh_seconds' => $this->refreshSeconds(),
            'route_id' => $snapshot['route_id'] ?? null,
            'origin' => $snapshot['origin'] ?? null,
            'stops' => [],
        ];

        foreach ($snapshot['stops'] ?? [] as $stop) {
            $arrive = Carbon::parse($stop['arrive_at']);
            $row = [
                'pickup_point_id' => $stop['pickup_point_id'],
                'name' => $stop['name'],
                'latitude' => $stop['lat'],
                'longitude' => $stop['lng'],
                'distance_m' => $stop['distance_m'],
                'arrive_at' => $stop['arrive_at'],
                'eta_minutes' => max(0, (int) ceil(now()->diffInSeconds($arrive, false) / 60)),
            ];
            if ($withPolylines) {
                $row['polyline'] = $stop['polyline'] ?? '';
            }
            $out['stops'][] = $row;
        }

        return $out;
    }

    /**
     * นาทีที่รถจะถึงจุดรับนี้ตามคำตอบของ Google — null ถ้าไม่มี/ไม่สด/เป็นค่าประมาณ
     * (ผู้เรียกคิดเองจากเส้นตรงต่อ) ใช้ร่วมกับ push "รถใกล้ถึง" และการ์ดหน้าจอล็อก
     */
    public function minutesFor(int $scheduleId, ?int $pickupPointId): ?int
    {
        if (! $pickupPointId) {
            return null;
        }

        $snapshot = $this->snapshot($scheduleId);
        if (! $snapshot || ! ($snapshot['active'] ?? false) || ($snapshot['source'] ?? null) !== 'google') {
            return null;
        }
        if (Carbon::parse($snapshot['computed_at'])->lt(now()->subMinutes(self::FRESH_MINUTES + 5))) {
            return null;
        }

        foreach ($this->present($snapshot, false)['stops'] as $stop) {
            if ((int) $stop['pickup_point_id'] === $pickupPointId) {
                return (int) $stop['eta_minutes'];
            }
        }

        return null;
    }

    /**
     * รอบที่กำลังมี ETA อยู่ (หน้าแอดมิน)
     *
     * @return list<array<string, mixed>>
     */
    public function activeSessions(): array
    {
        $today = now(VehicleLocationService::TIMEZONE);

        return TripSchedule::with(['trip:id,title', 'vehicle:id,name,license_plate'])
            ->whereNotNull('vehicle_id')
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q
                ->inProgressOn($today)
                ->orWhere(fn ($q2) => $q2->departingOn($today->toDateString())))
            ->get()
            ->map(function (TripSchedule $s) {
                $snapshot = $this->snapshot($s->id);

                return [
                    'schedule_id' => $s->id,
                    'trip_title' => $s->trip?->title,
                    'vehicle' => trim(($s->vehicle?->license_plate ?? '').' '.($s->vehicle?->name ?? '')),
                    'active' => (bool) ($snapshot['active'] ?? false),
                    'reason' => $snapshot['reason'] ?? ($snapshot ? null : 'not_started'),
                    'source' => $snapshot['source'] ?? null,
                    'computed_at' => $snapshot['computed_at'] ?? null,
                    'stops' => count($snapshot['stops'] ?? []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * จุดรับที่ยังมีคนรอ เรียงตามลำดับจอด (จำกัดจำนวนให้อยู่ใน SKU ที่ตั้งใจ)
     *
     * @return Collection<int, array{pickup_point_id: int, name: string, lat: float, lng: float}>
     */
    public function pendingStops(TripSchedule $schedule): Collection
    {
        $waiting = $this->pickups->waitingCounts($schedule);

        return $schedule->pickupPoints
            ->sortBy('sort_order')
            ->filter(fn ($p) => $p->latitude !== null && $p->longitude !== null
                && $p->completed_at === null
                && ($waiting[(int) $p->id] ?? 0) > 0)
            ->take(max(1, (int) config('maps.eta.max_stops', 11)))
            ->map(fn ($p) => [
                'pickup_point_id' => (int) $p->id,
                'name' => (string) $p->pickup_location,
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
            ])
            ->values();
    }

    /**
     * ทำไมรอบนี้ไม่ต้องมี ETA — null = ต้องคิด
     *
     * @param  array<string, mixed>|null  $location
     */
    private function stopReason(TripSchedule $schedule, ?array $location, Collection $pending): ?string
    {
        if (in_array($schedule->status, ['cancelled', 'completed'], true)) {
            return 'trip_ended';
        }
        if ($schedule->isFlight()) {
            return 'not_applicable';
        }
        if (! $this->locations->withinSharingWindow($schedule)) {
            return 'outside_window';
        }
        if ($pending->isEmpty()) {
            return 'all_picked_up';
        }
        if ($this->locations->stopInEffect($schedule)) {
            return 'sharing_stopped';
        }
        if (! $location || ! isset($location['latitude'], $location['longitude'], $location['recorded_at'])) {
            return 'no_signal';
        }
        $staleAfter = (int) config('maps.eta.stale_location_minutes', 20);
        if (Carbon::parse($location['recorded_at'])->lt(now()->subMinutes($staleAfter))) {
            return 'no_signal';
        }

        return null;
    }

    /**
     * @param  array{lat: float, lng: float}  $origin
     * @return array<string, mixed>|null
     */
    private function fromGoogle(TripSchedule $schedule, array $origin, Collection $pending): ?array
    {
        $traffic = (bool) config('maps.eta.traffic_aware', true);
        $points = $pending->map(fn ($s) => ['lat' => $s['lat'], 'lng' => $s['lng']])->all();

        $route = $this->routes->route(
            $origin,
            end($points),
            array_slice($points, 0, -1),
            trafficAware: $traffic,
            legPolylines: true,
        );

        if (! $route || count($route['legs']) !== $pending->count()) {
            return null;
        }

        $dwell = (int) config('maps.eta.stop_dwell_seconds', 180);
        $now = now();
        $seconds = 0;
        $meters = 0;
        $stops = [];

        foreach ($pending->values() as $i => $stop) {
            $leg = $route['legs'][$i];
            $seconds += $leg['duration'] + ($i > 0 ? $dwell : 0);
            $meters += $leg['distance'];
            $stops[] = $stop + [
                'distance_m' => $meters,
                'duration_s' => $seconds,
                'arrive_at' => $now->copy()->addSeconds($seconds)->toIso8601String(),
                'polyline' => $leg['polyline'],
            ];
        }

        return $this->build($schedule, $origin, $pending, $stops, 'google', $traffic);
    }

    /**
     * ค่าประมาณจากเส้นตรง — ถนนจริงอ้อมกว่าราวหนึ่งในสาม ใช้ความเร็วรถถ้ารู้
     *
     * @param  array{lat: float, lng: float}  $origin
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function estimate(TripSchedule $schedule, array $origin, Collection $pending, array $location): array
    {
        $speed = isset($location['speed']) && (float) $location['speed'] >= 8 ? (float) $location['speed'] : 35.0;
        $dwell = (int) config('maps.eta.stop_dwell_seconds', 180);
        $now = now();
        $from = $origin;
        $seconds = 0;
        $meters = 0;
        $stops = [];

        foreach ($pending->values() as $i => $stop) {
            $leg = GoogleDistanceService::haversineMeters($from['lat'], $from['lng'], $stop['lat'], $stop['lng']) * 1.3;
            $meters += (int) round($leg);
            $seconds += (int) round($leg / 1000 / $speed * 3600) + ($i > 0 ? $dwell : 0);
            $stops[] = $stop + [
                'distance_m' => $meters,
                'duration_s' => $seconds,
                'arrive_at' => $now->copy()->addSeconds($seconds)->toIso8601String(),
                'polyline' => '',
            ];
            $from = ['lat' => $stop['lat'], 'lng' => $stop['lng']];
        }

        return $this->build($schedule, $origin, $pending, $stops, 'estimate', false);
    }

    /**
     * @param  array{lat: float, lng: float}  $origin
     * @param  list<array<string, mixed>>  $stops
     * @return array<string, mixed>
     */
    private function build(TripSchedule $schedule, array $origin, Collection $pending, array $stops, string $source, bool $traffic): array
    {
        $now = now()->toIso8601String();

        return [
            'schedule_id' => $schedule->id,
            'vehicle_id' => (int) $schedule->vehicle_id,
            'active' => true,
            'reason' => null,
            'source' => $source,
            'traffic_aware' => $traffic,
            'computed_at' => $now,
            'updated_at' => $now,
            'origin' => $origin,
            'stops_key' => $pending->pluck('pickup_point_id')->implode(','),
            'route_id' => substr(md5($source.$now.implode('|', array_column($stops, 'polyline'))), 0, 12),
            'stops' => $stops,
        ];
    }

    /**
     * รถจอดนิ่ง — เวลาเดินทางที่เหลือเท่าเดิม เลื่อนเวลาถึงไปตามนาฬิกา
     *
     * @param  array<string, mixed>  $prev
     * @return array{outcome: string, snapshot: array<string, mixed>}
     */
    private function reanchor(array $prev): array
    {
        $now = now();
        $snapshot = $prev;
        $snapshot['updated_at'] = $now->toIso8601String();
        foreach ($snapshot['stops'] as $i => $stop) {
            $snapshot['stops'][$i]['arrive_at'] = $now->copy()->addSeconds((int) $stop['duration_s'])->toIso8601String();
        }

        $this->store($snapshot);

        return ['outcome' => 'reanchored', 'snapshot' => $snapshot];
    }

    /** @param  array<string, mixed>  $snapshot */
    private function store(array $snapshot): void
    {
        try {
            Cache::put(self::CACHE_PREFIX.$snapshot['schedule_id'], $snapshot, self::CACHE_TTL);
        } catch (\Throwable $e) {
            Log::warning('Could not cache vehicle ETA', ['error' => $e->getMessage()]);
        }

        try {
            broadcast(new VehicleEtaUpdated((int) $snapshot['vehicle_id'], $this->present($snapshot, false)));
        } catch (\Throwable $e) {
            Log::warning('Could not broadcast vehicle ETA', ['error' => $e->getMessage()]);
        }
    }

    private function refreshSeconds(): int
    {
        return max(180, min(300, (int) config('maps.eta.refresh_seconds', 240)));
    }
}
