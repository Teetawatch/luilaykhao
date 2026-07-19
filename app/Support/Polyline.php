<?php

namespace App\Support;

/**
 * เครื่องมือจัดการเส้นทางพิกัด — เข้ารหัสเป็น Google encoded polyline
 * (ฟอร์แมตเดียวกับที่ Directions API คืน แอปฝั่งลูกค้าถอดรหัสได้อยู่แล้ว)
 * และวัดความยาวเส้นทางรวม ใช้กับเส้นทางเดินรถที่แอดมินวาดเอง
 */
class Polyline
{
    /**
     * @param  array<int, array{lat: float, lng: float}>  $points
     */
    public static function encode(array $points): string
    {
        $encoded = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($points as $point) {
            $lat = (int) round(((float) $point['lat']) * 1e5);
            $lng = (int) round(((float) $point['lng']) * 1e5);
            $encoded .= self::encodeValue($lat - $prevLat).self::encodeValue($lng - $prevLng);
            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $encoded;
    }

    /**
     * ความยาวเส้นทางรวม (เมตร) จากผลรวม haversine ทีละช่วง
     *
     * @param  array<int, array{lat: float, lng: float}>  $points
     */
    public static function pathDistanceMeters(array $points): int
    {
        $points = array_values($points);
        $total = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $total += self::haversine(
                (float) $points[$i - 1]['lat'], (float) $points[$i - 1]['lng'],
                (float) $points[$i]['lat'], (float) $points[$i]['lng'],
            );
        }

        return (int) round($total);
    }

    private static function encodeValue(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : ($value << 1);
        $chunk = '';
        while ($value >= 0x20) {
            $chunk .= chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $chunk.chr($value + 63);
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(sqrt($a));
    }
}
