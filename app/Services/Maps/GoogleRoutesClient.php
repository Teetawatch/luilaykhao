<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ตัวเรียก Google Routes API ตัวเดียวของระบบ (computeRoutes / computeRouteMatrix)
 *
 * Directions API กับ Distance Matrix API เดิมเป็น "Legacy" แล้ว — เปิดใช้ในโปรเจกต์
 * Cloud ใหม่ไม่ได้ ทุกทางที่ต้องถาม Google เรื่องเส้นทาง/เวลาเดินทางจึงมาผ่านที่นี่
 * และผ่าน [MapsUsageMeter] เสมอ (นับ + เพดาน)
 *
 * SKU ที่ Google คิดเงินขึ้นกับสิ่งที่ขอ (ตรวจจากเอกสาร 2026-11-20):
 * - computeRoutes: Essentials ถ้า ≤10 intermediates และไม่คิดจราจร;
 *   TRAFFIC_AWARE หรือ 11–25 intermediates = Pro
 * - computeRouteMatrix: นับต่อ element, TRAFFIC_AWARE = Pro (เราไม่ใช้)
 * field mask ขอเฉพาะฟิลด์ที่ใช้จริง — ห้ามขอ tollInfo/travelAdvisory (ดัน SKU ขึ้น Enterprise)
 */
class GoogleRoutesClient
{
    /**
     * สาเหตุที่คำขอล่าสุดได้ null — ให้ `maps:check` บอกคนตั้งค่าได้ว่าพังตรงไหน
     *
     * @var array{reason: string, sku?: string, http?: int, status?: ?string, message?: ?string}|null
     */
    private ?array $lastError = null;

    public function __construct(private MapsUsageMeter $meter) {}

    /** @return array{reason: string, sku?: string, http?: int, status?: ?string, message?: ?string}|null */
    public function lastError(): ?array
    {
        return $this->lastError;
    }

    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * เส้นทางขับรถ ต้นทาง → (จุดแวะ) → ปลายทาง
     *
     * @param  array{lat: float, lng: float}  $origin
     * @param  array{lat: float, lng: float}  $destination
     * @param  array<int, array{lat: float, lng: float}>  $intermediates  สูงสุด 25
     * @return array{distance: int, duration: int, static_duration: int, polyline: string, legs: array<int, array{distance: int, duration: int, static_duration: int, polyline: string}>, sku: string}|null
     */
    public function route(
        array $origin,
        array $destination,
        array $intermediates = [],
        bool $trafficAware = false,
        bool $legPolylines = false,
    ): ?array {
        $this->lastError = null;

        if (! $this->configured()) {
            $this->lastError = ['reason' => 'not_configured'];

            return null;
        }

        $intermediates = array_values(array_slice($intermediates, 0, 25));
        $sku = ($trafficAware || count($intermediates) > 10)
            ? MapsUsageMeter::ROUTES_PRO
            : MapsUsageMeter::ROUTES_ESSENTIALS;

        if (! $this->meter->allow($sku)) {
            $this->lastError = ['reason' => 'cap_reached', 'sku' => $sku];

            return null;
        }

        $fields = [
            'routes.distanceMeters',
            'routes.duration',
            'routes.staticDuration',
            'routes.polyline.encodedPolyline',
            'routes.legs.distanceMeters',
            'routes.legs.duration',
            'routes.legs.staticDuration',
        ];
        if ($legPolylines) {
            $fields[] = 'routes.legs.polyline.encodedPolyline';
        }

        $body = [
            'origin' => $this->waypoint($origin),
            'destination' => $this->waypoint($destination),
            'travelMode' => 'DRIVE',
            'routingPreference' => $trafficAware ? 'TRAFFIC_AWARE' : 'TRAFFIC_UNAWARE',
            'polylineQuality' => 'OVERVIEW',
            'languageCode' => 'th',
            'units' => 'METRIC',
        ];
        if ($intermediates !== []) {
            $body['intermediates'] = array_map(fn ($p) => $this->waypoint($p), $intermediates);
        }

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey(),
                    'X-Goog-FieldMask' => implode(',', $fields),
                ])
                ->post((string) config('services.google_maps.routes_url'), $body);
        } catch (\Throwable $e) {
            // ต่อไม่ติด/หมดเวลา — ไม่ถึง Google ก็ไม่ถูกคิดเงิน แต่นับไว้ดูความผิดปกติ
            $this->meter->record($sku, 1, false);
            $this->lastError = ['reason' => 'connection', 'message' => $e->getMessage()];
            Log::error('Google Routes API error', ['error' => $e->getMessage()]);

            return null;
        }

        $route = $response->successful() ? ($response->json('routes.0') ?? null) : null;
        $this->meter->record($sku, 1, is_array($route));

        if (! is_array($route)) {
            if (! $response->successful()) {
                $this->lastError = [
                    'reason' => 'http',
                    'http' => $response->status(),
                    'status' => $response->json('error.status'),
                    'message' => $response->json('error.message'),
                ];
                Log::error('Google Routes API HTTP error', [
                    'status' => $response->status(),
                    'message' => $response->json('error.message'),
                ]);
            } else {
                $this->lastError = ['reason' => 'no_route'];
            }

            return null;
        }

        $legs = array_map(fn ($leg) => [
            'distance' => (int) ($leg['distanceMeters'] ?? 0),
            'duration' => $this->seconds($leg['duration'] ?? null),
            'static_duration' => $this->seconds($leg['staticDuration'] ?? $leg['duration'] ?? null),
            'polyline' => (string) ($leg['polyline']['encodedPolyline'] ?? ''),
        ], $route['legs'] ?? []);

        return [
            'distance' => (int) ($route['distanceMeters'] ?? array_sum(array_column($legs, 'distance'))),
            'duration' => $this->seconds($route['duration'] ?? null) ?: array_sum(array_column($legs, 'duration')),
            'static_duration' => $this->seconds($route['staticDuration'] ?? $route['duration'] ?? null),
            'polyline' => (string) ($route['polyline']['encodedPolyline'] ?? ''),
            'legs' => $legs,
            'sku' => $sku,
        ];
    }

    /**
     * ระยะ/เวลา จากต้นทางเดียวไปหลายปลายทาง (ไม่คิดจราจร = Essentials ต่อ element)
     *
     * @param  array{lat: float, lng: float}  $origin
     * @param  array<int, array{lat: float, lng: float}>  $destinations
     * @return array<int, array{distance: int, duration: int}>|null key = index ของ $destinations
     */
    public function matrix(array $origin, array $destinations): ?array
    {
        if (! $this->configured() || $destinations === []) {
            return null;
        }

        $destinations = array_values(array_slice($destinations, 0, 50));
        $units = count($destinations);
        $sku = MapsUsageMeter::MATRIX_ESSENTIALS;

        if (! $this->meter->allow($sku, $units)) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey(),
                    'X-Goog-FieldMask' => 'originIndex,destinationIndex,condition,distanceMeters,duration',
                ])
                ->post((string) config('services.google_maps.route_matrix_url'), [
                    'origins' => [$this->waypoint($origin)],
                    'destinations' => array_map(fn ($p) => $this->waypoint($p), $destinations),
                    'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_UNAWARE',
                    'languageCode' => 'th',
                    'units' => 'METRIC',
                ]);
        } catch (\Throwable $e) {
            $this->meter->record($sku, $units, false);
            Log::error('Google Route Matrix error', ['error' => $e->getMessage()]);

            return null;
        }

        $this->meter->record($sku, $units, $response->successful());

        if (! $response->successful() || ! is_array($response->json())) {
            Log::error('Google Route Matrix HTTP error', ['status' => $response->status()]);

            return null;
        }

        $results = [];
        foreach ($response->json() as $element) {
            if (($element['condition'] ?? null) !== 'ROUTE_EXISTS') {
                continue;
            }
            $results[(int) ($element['destinationIndex'] ?? 0)] = [
                'distance' => (int) ($element['distanceMeters'] ?? 0),
                'duration' => $this->seconds($element['duration'] ?? null),
            ];
        }

        return $results;
    }

    /** @param  array{lat: float, lng: float}  $point */
    private function waypoint(array $point): array
    {
        return ['location' => ['latLng' => [
            'latitude' => round((float) $point['lat'], 6),
            'longitude' => round((float) $point['lng'], 6),
        ]]];
    }

    /** Routes API ตอบเวลาเป็นสตริง "1234s" */
    private function seconds(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return is_string($value) ? (int) round((float) rtrim($value, 's')) : 0;
    }

    private function apiKey(): string
    {
        return (string) config('services.google_maps.api_key', '');
    }
}
