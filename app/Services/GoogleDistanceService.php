<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDistanceService
{
    private string $apiKey;

    private string $baseUrl;

    private string $directionsUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.google_maps.api_key', '');
        $this->baseUrl = (string) config('services.google_maps.distance_matrix_url');
        $this->directionsUrl = (string) config('services.google_maps.directions_url', 'https://maps.googleapis.com/maps/api/directions/json');
    }

    /**
     * เส้นทางขับรถตามถนนจริงจากต้นทางไปปลายทาง (สำหรับวาดเส้นบนแผนที่แบบ Grab)
     * คืน overview_polyline (encoded) + ระยะทาง/เวลา; คืน null เมื่อไม่มีคีย์/หาเส้นทางไม่ได้
     * เพื่อให้แอป fallback ไปเส้นตรงเอง
     *
     * @return array{polyline:string, distance:int, duration:int}|null
     */
    public function getRoute(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng
    ): ?array {
        if (empty($this->apiKey)) {
            return null;
        }

        // cache สั้น (60 วิ) เพราะรถวิ่งตลอด แต่ปัดพิกัดกันยิงซ้ำถี่เกิน
        $cacheKey = 'route:'.round($originLat, 3).','.round($originLng, 3)
                  .':'.round($destLat, 3).','.round($destLng, 3);

        return Cache::remember($cacheKey, 60, function () use ($originLat, $originLng, $destLat, $destLng) {
            try {
                $response = Http::timeout(10)->get($this->directionsUrl, [
                    'origin' => "{$originLat},{$originLng}",
                    'destination' => "{$destLat},{$destLng}",
                    'mode' => 'driving',
                    'departure_time' => 'now',
                    'language' => 'th',
                    'key' => $this->apiKey,
                ]);

                if (! $response->successful()) {
                    Log::error('Google Directions API HTTP error', ['status' => $response->status()]);

                    return null;
                }

                $data = $response->json();
                if (($data['status'] ?? '') !== 'OK' || empty($data['routes'])) {
                    return null;
                }

                $route = $data['routes'][0];
                $leg = $route['legs'][0] ?? [];

                return [
                    'polyline' => $route['overview_polyline']['points'] ?? '',
                    'distance' => (int) ($leg['distance']['value'] ?? 0),
                    'duration' => (int) ($leg['duration']['value'] ?? 0),
                ];
            } catch (\Throwable $e) {
                Log::error('Google Directions API error', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * เส้นทางขับรถหลายจุดจอด (จุดแรก → waypoints → จุดสุดท้าย) สำหรับวาด
     * "เส้นทางเดินรถ" ของรอบทริปผ่านจุดรับทุกจุดจนถึงปลายทาง
     *
     * เส้นทางของรอบแทบไม่เปลี่ยน จึง cache ยาว (6 ชม.) โดยผูก key กับพิกัด
     * ทุกจุด — แก้จุดรับเมื่อไหร่ key เปลี่ยนและดึงใหม่เอง
     *
     * @param  array<int, array{lat: float, lng: float}>  $points  เรียงตามลำดับจอด อย่างน้อย 2 จุด
     * @return array{polyline:string, distance:int, duration:int, legs:array<int,array{distance:int,duration:int}>}|null
     */
    public function getMultiStopRoute(array $points): ?array
    {
        if (empty($this->apiKey) || count($points) < 2) {
            return null;
        }

        // Directions API รับ waypoints ได้สูงสุด 25 จุด
        $points = array_values($points);
        if (count($points) > 25) {
            $points = array_merge(
                array_slice($points, 0, 24),
                [end($points)]
            );
        }

        $coords = array_map(
            fn ($p) => round((float) $p['lat'], 5).','.round((float) $p['lng'], 5),
            $points
        );
        $cacheKey = 'schedule-route:'.md5(implode('|', $coords));

        return Cache::remember($cacheKey, 6 * 3600, function () use ($coords) {
            try {
                $waypoints = array_slice($coords, 1, -1);
                $params = [
                    'origin' => $coords[0],
                    'destination' => end($coords),
                    'mode' => 'driving',
                    'language' => 'th',
                    'key' => $this->apiKey,
                ];
                if (! empty($waypoints)) {
                    $params['waypoints'] = implode('|', $waypoints);
                }

                $response = Http::timeout(15)->get($this->directionsUrl, $params);

                if (! $response->successful()) {
                    Log::error('Google Directions API HTTP error (multi-stop)', ['status' => $response->status()]);

                    return null;
                }

                $data = $response->json();
                if (($data['status'] ?? '') !== 'OK' || empty($data['routes'])) {
                    return null;
                }

                $route = $data['routes'][0];
                $legs = array_map(fn ($leg) => [
                    'distance' => (int) ($leg['distance']['value'] ?? 0),
                    'duration' => (int) ($leg['duration']['value'] ?? 0),
                ], $route['legs'] ?? []);

                return [
                    'polyline' => $route['overview_polyline']['points'] ?? '',
                    'distance' => array_sum(array_column($legs, 'distance')),
                    'duration' => array_sum(array_column($legs, 'duration')),
                    'legs' => $legs,
                ];
            } catch (\Throwable $e) {
                Log::error('Google Directions API error (multi-stop)', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * คำนวณระยะทางและเวลาเดินทางจาก origin ไปยัง destination
     *
     * @param  string  $mode  driving|walking|bicycling|transit
     */
    public function getDistance(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        string $mode = 'driving'
    ): ?array {
        $cacheKey = "distance:{$originLat},{$originLng}:{$destLat},{$destLng}:{$mode}";

        return Cache::remember($cacheKey, 3600, function () use ($originLat, $originLng, $destLat, $destLng, $mode) {
            return $this->callApi(
                "{$originLat},{$originLng}",
                "{$destLat},{$destLng}",
                $mode
            );
        });
    }

    /**
     * คำนวณระยะทางจาก origin ไปยังหลาย destinations พร้อมกัน
     *
     * @param  array  $destinations  [['lat' => float, 'lng' => float, 'id' => mixed], ...]
     */
    public function getDistances(
        float $originLat,
        float $originLng,
        array $destinations,
        string $mode = 'driving'
    ): array {
        if (empty($destinations)) {
            return [];
        }

        $destStrings = array_map(
            fn ($d) => "{$d['lat']},{$d['lng']}",
            $destinations
        );

        $destParam = implode('|', $destStrings);
        $origin = "{$originLat},{$originLng}";

        $cacheKey = 'distances:'.md5("{$origin}:{$destParam}:{$mode}");

        return Cache::remember($cacheKey, 3600, function () use ($origin, $destParam, $destinations, $mode) {
            return $this->callApiMultiple($origin, $destParam, $destinations, $mode);
        });
    }

    /**
     * คำนวณ ETA ของรถถึงจุดหมาย (สำหรับ vehicle tracking)
     *
     * @param  float  $vehicleLat  ตำแหน่งปัจจุบันของรถ
     * @param  float  $destLat  ปลายทาง (จุดรับผู้โดยสาร / จุดหมาย)
     */
    public function getETA(
        float $vehicleLat,
        float $vehicleLng,
        float $destLat,
        float $destLng
    ): ?array {
        if (empty($this->apiKey)) {
            return $this->haversineETA($vehicleLat, $vehicleLng, $destLat, $destLng);
        }

        // ETA cache สั้นกว่า (5 นาที) เพราะรถเคลื่อนที่ตลอด
        $cacheKey = 'eta:'.round($vehicleLat, 3).','.round($vehicleLng, 3)
                  .':'.round($destLat, 3).','.round($destLng, 3);

        $result = Cache::remember($cacheKey, 300, function () use ($vehicleLat, $vehicleLng, $destLat, $destLng) {
            return $this->callApi(
                "{$vehicleLat},{$vehicleLng}",
                "{$destLat},{$destLng}",
                'driving',
                'best_guess'
            );
        });

        // If Google API call failed, fall back to haversine
        return $result ?? $this->haversineETA($vehicleLat, $vehicleLng, $destLat, $destLng);
    }

    /**
     * Haversine fallback: คำนวณ ETA จากระยะทางเส้นตรง
     * ใช้เมื่อไม่มี Google API key หรือ API ไม่ตอบสนอง
     */
    private function haversineETA(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng
    ): array {
        $R = 6371000; // รัศมีโลก (เมตร)
        $dLat = deg2rad($destLat - $originLat);
        $dLng = deg2rad($destLng - $originLng);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($originLat)) * cos(deg2rad($destLat)) * sin($dLng / 2) ** 2;
        $distanceM = 2 * $R * asin(sqrt($a));

        // สมมติความเร็วเฉลี่ยในเมือง 40 km/h → ≈ 11.1 m/s
        $avgSpeedMs = 40 * 1000 / 3600;
        $durationSec = (int) round($distanceM / $avgSpeedMs);

        $distanceKm = $distanceM / 1000;
        $distanceText = $distanceKm >= 1
            ? round($distanceKm, 1).' กม.'
            : round($distanceM).' ม.';

        $durationMin = (int) round($durationSec / 60);
        if ($durationMin < 60) {
            $durationText = $durationMin.' นาที';
        } else {
            $h = intdiv($durationMin, 60);
            $m = $durationMin % 60;
            $durationText = $m > 0 ? "{$h} ชม. {$m} นาที" : "{$h} ชม.";
        }

        return [
            'distance' => [
                'text' => $distanceText,
                'value' => (int) round($distanceM),
            ],
            'duration' => [
                'text' => $durationText,
                'value' => $durationSec,
            ],
            'origin' => "{$originLat},{$originLng}",
            'destination' => "{$destLat},{$destLng}",
            'source' => 'haversine',
        ];
    }

    /**
     * เรียก Distance Matrix API (single origin → single destination)
     */
    private function callApi(
        string $origin,
        string $destination,
        string $mode = 'driving',
        string $trafficModel = ''
    ): ?array {
        if (empty($this->apiKey)) {
            Log::warning('Google Maps API Key is not configured.');

            return null;
        }

        try {
            $params = [
                'origins' => $origin,
                'destinations' => $destination,
                'mode' => $mode,
                'language' => 'th',
                'key' => $this->apiKey,
            ];

            if ($trafficModel && $mode === 'driving') {
                $params['departure_time'] = 'now';
                $params['traffic_model'] = $trafficModel;
            }

            $response = Http::timeout(10)->get($this->baseUrl, $params);

            if (! $response->successful()) {
                Log::error('Google Distance Matrix API HTTP error', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            if ($data['status'] !== 'OK') {
                Log::error('Google Distance Matrix API error', [
                    'status' => $data['status'],
                    'error_message' => $data['error_message'] ?? null,
                ]);

                return null;
            }

            $element = $data['rows'][0]['elements'][0] ?? null;

            if (! $element || $element['status'] !== 'OK') {
                return null;
            }

            $result = [
                'distance' => [
                    'text' => $element['distance']['text'],
                    'value' => $element['distance']['value'], // เมตร
                ],
                'duration' => [
                    'text' => $element['duration']['text'],
                    'value' => $element['duration']['value'], // วินาที
                ],
                'origin' => $data['origin_addresses'][0] ?? null,
                'destination' => $data['destination_addresses'][0] ?? null,
            ];

            // ถ้ามี duration_in_traffic ให้เพิ่มเข้าไป
            if (isset($element['duration_in_traffic'])) {
                $result['duration_in_traffic'] = [
                    'text' => $element['duration_in_traffic']['text'],
                    'value' => $element['duration_in_traffic']['value'],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Google Distance Matrix API exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * เรียก Distance Matrix API (single origin → multiple destinations)
     */
    private function callApiMultiple(
        string $origin,
        string $destinations,
        array $destMeta,
        string $mode = 'driving'
    ): array {
        if (empty($this->apiKey)) {
            Log::warning('Google Maps API Key is not configured.');

            return [];
        }

        try {
            $response = Http::timeout(15)->get($this->baseUrl, [
                'origins' => $origin,
                'destinations' => $destinations,
                'mode' => $mode,
                'language' => 'th',
                'key' => $this->apiKey,
            ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();

            if ($data['status'] !== 'OK') {
                Log::error('Google Distance Matrix API error (multiple)', [
                    'status' => $data['status'],
                ]);

                return [];
            }

            $elements = $data['rows'][0]['elements'] ?? [];
            $results = [];

            foreach ($elements as $i => $element) {
                if ($element['status'] !== 'OK') {
                    continue;
                }

                $results[] = [
                    'id' => $destMeta[$i]['id'] ?? $i,
                    'distance' => [
                        'text' => $element['distance']['text'],
                        'value' => $element['distance']['value'],
                    ],
                    'duration' => [
                        'text' => $element['duration']['text'],
                        'value' => $element['duration']['value'],
                    ],
                    'destination_address' => $data['destination_addresses'][$i] ?? null,
                ];
            }

            return $results;
        } catch (\Exception $e) {
            Log::error('Google Distance Matrix API exception (multiple)', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
