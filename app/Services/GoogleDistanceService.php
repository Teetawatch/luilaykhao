<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDistanceService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.google_maps.api_key', '');
        $this->baseUrl = config('services.google_maps.distance_matrix_url');
    }

    /**
     * คำนวณระยะทางและเวลาเดินทางจาก origin ไปยัง destination
     *
     * @param  float  $originLat
     * @param  float  $originLng
     * @param  float  $destLat
     * @param  float  $destLng
     * @param  string $mode  driving|walking|bicycling|transit
     * @return array|null
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
     * @param  float  $originLat
     * @param  float  $originLng
     * @param  array  $destinations  [['lat' => float, 'lng' => float, 'id' => mixed], ...]
     * @param  string $mode
     * @return array
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
            fn($d) => "{$d['lat']},{$d['lng']}",
            $destinations
        );

        $destParam = implode('|', $destStrings);
        $origin = "{$originLat},{$originLng}";

        $cacheKey = "distances:" . md5("{$origin}:{$destParam}:{$mode}");

        return Cache::remember($cacheKey, 3600, function () use ($origin, $destParam, $destinations, $mode) {
            return $this->callApiMultiple($origin, $destParam, $destinations, $mode);
        });
    }

    /**
     * คำนวณ ETA ของรถถึงจุดหมาย (สำหรับ vehicle tracking)
     *
     * @param  float  $vehicleLat    ตำแหน่งปัจจุบันของรถ
     * @param  float  $vehicleLng
     * @param  float  $destLat       ปลายทาง (จุดรับผู้โดยสาร / จุดหมาย)
     * @param  float  $destLng
     * @return array|null
     */
    public function getETA(
        float $vehicleLat,
        float $vehicleLng,
        float $destLat,
        float $destLng
    ): ?array {
        // ETA cache สั้นกว่า (5 นาที) เพราะรถเคลื่อนที่ตลอด
        $cacheKey = "eta:" . round($vehicleLat, 3) . "," . round($vehicleLng, 3)
                  . ":" . round($destLat, 3) . "," . round($destLng, 3);

        return Cache::remember($cacheKey, 300, function () use ($vehicleLat, $vehicleLng, $destLat, $destLng) {
            return $this->callApi(
                "{$vehicleLat},{$vehicleLng}",
                "{$destLat},{$destLng}",
                'driving',
                'best_guess'
            );
        });
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

            if (!$response->successful()) {
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

            if (!$element || $element['status'] !== 'OK') {
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

            if (!$response->successful()) {
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
