<?php

namespace App\Services;

use App\Services\Maps\GoogleRoutesClient;
use App\Services\Maps\MapsUsageMeter;
use Illuminate\Support\Facades\Cache;

/**
 * ระยะทาง/เวลา/เส้นทางตามถนนจริง — หน้าบ้านของ [GoogleRoutesClient]
 *
 * รูปคำตอบคงเดิมตั้งแต่สมัย Directions/Distance Matrix (Legacy) ผู้เรียกจึงไม่ต้อง
 * เปลี่ยน ทุกเมธอดแคชก่อนเสมอ และถ้า Google ใช้ไม่ได้ (ไม่มีคีย์/ชนเพดาน/ล่ม)
 * คืน null หรือค่าประมาณจากเส้นตรงตามที่แต่ละเมธอดบอกไว้
 *
 * ETA ของรถไปจุดรับระหว่างวันเดินทางไม่ได้มาทางนี้ — ดู [VehicleEtaService]
 * ซึ่งถามครั้งเดียวต่อรถแล้วแชร์ให้ลูกค้าทุกคน
 */
class GoogleDistanceService
{
    public function __construct(
        private ?GoogleRoutesClient $routes = null,
        private ?MapsUsageMeter $meter = null,
    ) {
        $this->routes ??= app(GoogleRoutesClient::class);
        $this->meter ??= app(MapsUsageMeter::class);
    }

    /**
     * เส้นทางขับรถหลายจุดจอด (จุดแรก → จุดแวะ → จุดสุดท้าย) ของรอบทริป
     *
     * เส้นทางของรอบแทบไม่เปลี่ยน แคช 24 ชม. ผูก key กับพิกัดทุกจุด แก้จุดรับเมื่อไหร่
     * key เปลี่ยนเอง ≤12 จุด (10 intermediates) = Essentials, มากกว่านั้น = Pro
     *
     * @param  array<int, array{lat: float, lng: float}>  $points  เรียงตามลำดับจอด อย่างน้อย 2 จุด
     * @return array{polyline:string, distance:int, duration:int, legs:array<int,array{distance:int,duration:int}>}|null
     */
    public function getMultiStopRoute(array $points): ?array
    {
        if (! $this->routes->configured() || count($points) < 2) {
            return null;
        }

        // Routes API รับจุดแวะได้สูงสุด 25 จุด
        $points = array_values($points);
        if (count($points) > 27) {
            $points = array_merge(array_slice($points, 0, 26), [end($points)]);
        }

        $coords = array_map(fn ($p) => [
            'lat' => round((float) $p['lat'], 5),
            'lng' => round((float) $p['lng'], 5),
        ], $points);
        $cacheKey = 'schedule-route:v2:'.md5(json_encode($coords));

        return $this->remember($cacheKey, 24 * 3600, MapsUsageMeter::ROUTES_ESSENTIALS, function () use ($coords) {
            $route = $this->routes->route($coords[0], end($coords), array_slice($coords, 1, -1));
            if (! $route) {
                return null;
            }

            return [
                'polyline' => $route['polyline'],
                'distance' => $route['distance'],
                'duration' => $route['duration'],
                'legs' => array_map(fn ($leg) => [
                    'distance' => $leg['distance'],
                    'duration' => $leg['duration'],
                ], $route['legs']),
            ];
        });
    }

    /**
     * ระยะทางและเวลาขับรถจากจุดหนึ่งไปอีกจุดหนึ่ง (Routes API รองรับเฉพาะรถยนต์ที่นี่)
     */
    public function getDistance(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        string $mode = 'driving'
    ): ?array {
        if (! $this->routes->configured()) {
            return null;
        }

        $cacheKey = 'distance:v2:'.round($originLat, 4).','.round($originLng, 4)
                  .':'.round($destLat, 4).','.round($destLng, 4);

        return $this->remember($cacheKey, 3600, MapsUsageMeter::ROUTES_ESSENTIALS, function () use ($originLat, $originLng, $destLat, $destLng) {
            $route = $this->routes->route(
                ['lat' => $originLat, 'lng' => $originLng],
                ['lat' => $destLat, 'lng' => $destLng],
            );

            return $route ? $this->shape($route['distance'], $route['duration']) + [
                'origin' => "{$originLat},{$originLng}",
                'destination' => "{$destLat},{$destLng}",
            ] : null;
        });
    }

    /**
     * ระยะจากจุดหนึ่งไปหลายปลายทางพร้อมกัน (Route Matrix, คิดเงินต่อปลายทาง)
     *
     * พิกัดต้นทาง (ตำแหน่งลูกค้า) ปัด ~100 ม. — คนในละแวกเดียวกันได้คำตอบเดียวกัน
     *
     * @param  array  $destinations  [['lat' => float, 'lng' => float, 'id' => mixed], ...]
     */
    public function getDistances(
        float $originLat,
        float $originLng,
        array $destinations,
        string $mode = 'driving'
    ): array {
        if ($destinations === [] || ! $this->routes->configured()) {
            return [];
        }

        $originLat = round($originLat, 3);
        $originLng = round($originLng, 3);
        $destinations = array_values($destinations);
        $cacheKey = 'distances:v2:'.md5(json_encode([$originLat, $originLng, array_map(
            fn ($d) => [round((float) $d['lat'], 5), round((float) $d['lng'], 5), $d['id'] ?? null],
            $destinations,
        )]));

        return $this->remember($cacheKey, 3600, MapsUsageMeter::MATRIX_ESSENTIALS, function () use ($originLat, $originLng, $destinations) {
            $matrix = $this->routes->matrix(
                ['lat' => $originLat, 'lng' => $originLng],
                array_map(fn ($d) => ['lat' => (float) $d['lat'], 'lng' => (float) $d['lng']], $destinations),
            );
            if ($matrix === null) {
                return null;
            }

            $results = [];
            foreach ($destinations as $i => $dest) {
                if (! isset($matrix[$i])) {
                    continue;
                }
                $results[] = ['id' => $dest['id'] ?? $i]
                    + $this->shape($matrix[$i]['distance'], $matrix[$i]['duration'])
                    + ['destination_address' => null];
            }

            return $results;
        }) ?? [];
    }

    /**
     * ETA ของรถถึงจุดหมาย (คิดจราจร = Pro) — ถาม Google ไม่ได้คืนค่าประมาณเส้นตรง
     * (`source` = haversine) ผู้เรียกที่แคร์ความแม่นยำต้องเช็คเอง
     */
    public function getETA(
        float $vehicleLat,
        float $vehicleLng,
        float $destLat,
        float $destLng
    ): ?array {
        if (! $this->routes->configured()) {
            return $this->haversineETA($vehicleLat, $vehicleLng, $destLat, $destLng);
        }

        // ETA แคช 5 นาที ปัดพิกัดรถ ~100 ม.
        $cacheKey = 'eta:v2:'.round($vehicleLat, 3).','.round($vehicleLng, 3)
                  .':'.round($destLat, 3).','.round($destLng, 3);

        $result = $this->remember($cacheKey, 300, MapsUsageMeter::ROUTES_PRO, function () use ($vehicleLat, $vehicleLng, $destLat, $destLng) {
            $route = $this->routes->route(
                ['lat' => $vehicleLat, 'lng' => $vehicleLng],
                ['lat' => $destLat, 'lng' => $destLng],
                trafficAware: true,
            );
            if (! $route) {
                return null;
            }

            // เดิม (Distance Matrix) duration = ไม่รวมจราจร, duration_in_traffic = รวม
            $base = $this->shape($route['distance'], $route['static_duration'] ?: $route['duration']);
            $traffic = $this->shape($route['distance'], $route['duration']);

            return $base + [
                'duration_in_traffic' => $traffic['duration'],
                'origin' => "{$vehicleLat},{$vehicleLng}",
                'destination' => "{$destLat},{$destLng}",
                'source' => 'google',
            ];
        });

        return $result ?? $this->haversineETA($vehicleLat, $vehicleLng, $destLat, $destLng);
    }

    /**
     * Haversine fallback: ETA จากระยะเส้นตรง (สมมติ 40 กม./ชม.)
     */
    public function haversineETA(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng
    ): array {
        $distanceM = self::haversineMeters($originLat, $originLng, $destLat, $destLng);

        // สมมติความเร็วเฉลี่ยในเมือง 40 km/h → ≈ 11.1 m/s
        $durationSec = (int) round($distanceM / (40 * 1000 / 3600));

        return $this->shape((int) round($distanceM), $durationSec) + [
            'origin' => "{$originLat},{$originLng}",
            'destination' => "{$destLat},{$destLng}",
            'source' => 'haversine',
        ];
    }

    public static function haversineMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return 2 * 6371000 * asin(min(1.0, sqrt($a)));
    }

    /**
     * แคชเฉพาะคำตอบที่ใช้ได้ — คำตอบ null (ชนเพดาน/Google ล่ม) ไม่แคช จะได้ลองใหม่
     * ครั้งหน้า แต่ก็ไม่ถี่เกินเพราะเพดาน/อัตราจำกัดของ endpoint คุมอยู่แล้ว
     */
    private function remember(string $key, int $ttl, string $sku, callable $compute): mixed
    {
        $cached = Cache::get($key);
        if ($cached !== null) {
            $this->meter->cacheHit($sku);

            return $cached;
        }

        $value = $compute();
        if ($value !== null) {
            Cache::put($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * @return array{distance: array{text: string, value: int}, duration: array{text: string, value: int}}
     */
    private function shape(int $meters, int $seconds): array
    {
        $distanceText = $meters >= 1000
            ? round($meters / 1000, 1).' กม.'
            : $meters.' ม.';

        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            $durationText = $minutes.' นาที';
        } else {
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;
            $durationText = $m > 0 ? "{$h} ชม. {$m} นาที" : "{$h} ชม.";
        }

        return [
            'distance' => ['text' => $distanceText, 'value' => $meters],
            'duration' => ['text' => $durationText, 'value' => $seconds],
        ];
    }
}
