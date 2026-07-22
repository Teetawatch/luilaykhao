<?php

namespace App\Services;

/**
 * อ่านไฟล์ GPX ของเส้นทางเดินแล้วแปลงเป็น "โปรไฟล์ความชัน" ที่แอปเอาไปวาดกราฟได้
 * ทันที — คนเดินป่าตัดสินใจจากรูปร่างของเส้นทาง (ชันตรงไหน ยาวแค่ไหน พักตรงไหนได้)
 * ไม่ใช่จากตัวเลขระยะทางตัวเดียว.
 *
 * ทุกอย่างคำนวณตอนอัปโหลดครั้งเดียวแล้วเก็บลง `trips.route_track` เพราะไฟล์ GPX
 * จริงมีได้หลักหมื่นจุด ถ้าคำนวณทุก request จะช้าและส่งข้อมูลใหญ่เกินจำเป็น.
 */
class RouteTrackService
{
    /** จำนวนจุดสูงสุดที่เก็บ — พอสำหรับกราฟกว้างระดับมือถือโดยไม่ทำ payload บวม. */
    public const MAX_POINTS = 200;

    /**
     * ไม่นับความสูงที่ขยับน้อยกว่านี้เป็น "การไต่" — GPS มีสัญญาณรบกวนแนวดิ่ง
     * สูงมาก ถ้าบวกทุกความเปลี่ยนแปลงจะได้ตัวเลขไต่สะสมที่เว่อร์เกินจริงหลายเท่า.
     */
    private const ELEVATION_NOISE_M = 3.0;

    /**
     * แปลงเนื้อไฟล์ GPX เป็นโครงสร้างที่พร้อมเก็บลงฐานข้อมูล.
     *
     * @throws \InvalidArgumentException เมื่อไฟล์ไม่ใช่ GPX หรือไม่มีจุดพิกัด
     */
    public function fromGpx(string $xml): array
    {
        $points = $this->parsePoints($xml);

        if (count($points) < 2) {
            throw new \InvalidArgumentException('ไฟล์ GPX นี้ไม่มีจุดพิกัดเพียงพอที่จะสร้างเส้นทางได้');
        }

        return $this->build($points);
    }

    /**
     * สร้างโปรไฟล์จากชุดพิกัดที่มีอยู่แล้ว (ใช้ซ้ำกับแทร็กที่ผู้ใช้บันทึกเอง).
     *
     * @param  array<int, array{lat: float, lng: float, ele: float|null}>  $points
     */
    public function build(array $points): array
    {
        $points = array_values($points);

        $cumulative = [0.0];
        $totalMeters = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $totalMeters += $this->haversineMeters(
                $points[$i - 1]['lat'],
                $points[$i - 1]['lng'],
                $points[$i]['lat'],
                $points[$i]['lng'],
            );
            $cumulative[] = $totalMeters;
        }

        [$gain, $loss] = $this->elevationChange($points);

        $elevations = array_values(array_filter(
            array_map(fn (array $p) => $p['ele'], $points),
            fn ($e) => $e !== null,
        ));

        $sampled = $this->sample($points, $cumulative);

        return [
            'points' => $sampled,
            'distance_km' => round($totalMeters / 1000, 2),
            'elevation_gain_m' => (int) round($gain),
            'elevation_loss_m' => (int) round($loss),
            'max_elevation_m' => $elevations === [] ? null : (int) round(max($elevations)),
            'min_elevation_m' => $elevations === [] ? null : (int) round(min($elevations)),
            'has_elevation' => $elevations !== [],
            'steepest' => $this->steepestSegment($points, $cumulative),
            'point_count' => count($points),
        ];
    }

    /**
     * ดึงจุดพิกัดจาก GPX — รองรับทั้ง <trkpt> (แทร็กที่บันทึกจริง) และ <rtept>
     * (เส้นทางที่วางแผนไว้) เพราะไฟล์จากเว็บวางแผนเส้นทางมักใช้อย่างหลัง.
     *
     * @return array<int, array{lat: float, lng: float, ele: float|null}>
     */
    private function parsePoints(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $doc = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($doc === false) {
            throw new \InvalidArgumentException('อ่านไฟล์ GPX ไม่สำเร็จ — ไฟล์อาจเสียหายหรือไม่ใช่ไฟล์ GPX');
        }

        $doc->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');

        // ลอง namespace มาตรฐานก่อน แล้วค่อย fallback เป็นแบบไม่มี namespace
        $nodes = $doc->xpath('//gpx:trkpt') ?: [];
        if ($nodes === []) {
            $nodes = $doc->xpath('//trkpt') ?: [];
        }
        if ($nodes === []) {
            $nodes = $doc->xpath('//gpx:rtept') ?: $doc->xpath('//rtept') ?: [];
        }

        $points = [];

        foreach ($nodes as $node) {
            $lat = isset($node['lat']) ? (float) $node['lat'] : null;
            $lng = isset($node['lon']) ? (float) $node['lon'] : null;

            if ($lat === null || $lng === null) {
                continue;
            }
            // พิกัด (0,0) คือค่า default ที่อุปกรณ์คายออกมาตอนยังจับสัญญาณไม่ได้
            if ($lat === 0.0 && $lng === 0.0) {
                continue;
            }

            $ele = isset($node->ele) ? (float) $node->ele : null;

            $points[] = ['lat' => $lat, 'lng' => $lng, 'ele' => $ele];
        }

        return $points;
    }

    /**
     * ไต่สะสม/ลงสะสม โดยกรองสัญญาณรบกวนแนวดิ่งออก.
     *
     * @param  array<int, array{lat: float, lng: float, ele: float|null}>  $points
     * @return array{0: float, 1: float}
     */
    private function elevationChange(array $points): array
    {
        $gain = 0.0;
        $loss = 0.0;
        $reference = null;

        foreach ($points as $point) {
            $ele = $point['ele'];
            if ($ele === null) {
                continue;
            }
            if ($reference === null) {
                $reference = $ele;

                continue;
            }

            $delta = $ele - $reference;

            if (abs($delta) < self::ELEVATION_NOISE_M) {
                continue;
            }

            if ($delta > 0) {
                $gain += $delta;
            } else {
                $loss += abs($delta);
            }

            $reference = $ele;
        }

        return [$gain, $loss];
    }

    /**
     * ลดจำนวนจุดลงเหลือไม่เกิน MAX_POINTS โดยเก็บจุดแรก/จุดสุดท้ายไว้เสมอ
     * และคืนระยะสะสม (กม.) มาด้วย เพื่อให้แกน X ของกราฟเป็นระยะทางจริง
     * ไม่ใช่ลำดับของจุด (ซึ่งจะบิดเบือนช่วงที่ GPS เก็บถี่กว่าปกติ).
     *
     * @param  array<int, array{lat: float, lng: float, ele: float|null}>  $points
     * @param  array<int, float>  $cumulative
     */
    private function sample(array $points, array $cumulative): array
    {
        $count = count($points);
        $step = $count <= self::MAX_POINTS ? 1 : (int) ceil($count / self::MAX_POINTS);

        $out = [];

        for ($i = 0; $i < $count; $i += $step) {
            $out[] = $this->formatPoint($points[$i], $cumulative[$i]);
        }

        $lastIndex = $count - 1;
        if (($lastIndex % $step) !== 0) {
            $out[] = $this->formatPoint($points[$lastIndex], $cumulative[$lastIndex]);
        }

        return $out;
    }

    private function formatPoint(array $point, float $metersFromStart): array
    {
        return [
            'lat' => round($point['lat'], 5),
            'lng' => round($point['lng'], 5),
            'ele' => $point['ele'] === null ? null : (int) round($point['ele']),
            'km' => round($metersFromStart / 1000, 3),
        ];
    }

    /**
     * ช่วงที่ชันที่สุดของเส้นทาง (คิดบนหน้าต่างระยะ ~500 ม. เพื่อไม่ให้จุดเดียว
     * ที่ GPS เพี้ยนกลายเป็น "ช่วงชันที่สุด") — เป็นข้อมูลที่คนเตรียมตัวเดินอยากรู้
     * มากกว่าความชันเฉลี่ยทั้งเส้น.
     *
     * @param  array<int, array{lat: float, lng: float, ele: float|null}>  $points
     * @param  array<int, float>  $cumulative
     */
    private function steepestSegment(array $points, array $cumulative): ?array
    {
        $windowMeters = 500.0;
        $count = count($points);

        $best = null;
        $start = 0;

        for ($end = 1; $end < $count; $end++) {
            while ($windowMeters < $cumulative[$end] - $cumulative[$start] && $start < $end - 1) {
                $start++;
            }

            $span = $cumulative[$end] - $cumulative[$start];
            if ($span < 100.0) {
                continue;
            }

            $from = $points[$start]['ele'];
            $to = $points[$end]['ele'];
            if ($from === null || $to === null) {
                continue;
            }

            $rise = $to - $from;
            if ($rise <= 0) {
                continue;
            }

            $grade = $rise / $span * 100;

            if ($best === null || $grade > $best['grade_percent']) {
                $best = [
                    'from_km' => round($cumulative[$start] / 1000, 2),
                    'to_km' => round($cumulative[$end] / 1000, 2),
                    'rise_m' => (int) round($rise),
                    'grade_percent' => round($grade, 1),
                ];
            }
        }

        return $best;
    }

    private function haversineMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
