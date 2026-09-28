<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripTrack;
use Illuminate\Support\Collection;

/**
 * เส้นทาง + ตัวเลขส่วนตัวของเหรียญ — ส่วนที่ทำให้การ์ดแชร์เหรียญเป็น "ของคนนี้"
 * ไม่ใช่ของทริป (แบบที่ Strava วาดเส้นทางที่วิ่งจริงลงการ์ด)
 *
 * เส้นทางมาจากสองแหล่ง เรียงตามความเป็นของส่วนตัว:
 *  1. แทร็ก GPS ที่ผู้ใช้บันทึกเองในรอบนั้น (trip_tracks) — "recorded"
 *  2. เส้นทาง GPX ของทริปที่แอดมินอัปโหลด (trips.route_track) — "planned"
 *
 * **ไม่มีพิกัดจริงหลุดออกไป** — ส่งแค่ "รูปร่าง" ที่ย่อลงกรอบ 0–1 แล้ว เพราะ
 * การ์ดนี้ถูกแชร์สาธารณะ และแทร็กที่บันทึกเองอาจเริ่มจากที่พักของคนนั้น
 */
class MedalRouteService
{
    /** แทร็กสั้นกว่านี้ถือว่าเป็นการกดบันทึกเล่น ๆ ไม่ใช่เส้นทางที่เดิน */
    public const MIN_TRACK_KM = 0.3;

    public const MIN_POINTS = 5;

    /** จำนวนแท่งของกราฟความสูงบนการ์ด */
    public const PROFILE_SAMPLES = 48;

    /**
     * @param  array<int, array<string, mixed>>|null  $points  จุดแบบที่ RouteTrackService::build() คืน
     * @return array{aspect: float, points: array<int, array{0: float, 1: float}>, elevations: array<int, int>|null}|null
     */
    public function shape(?array $points): ?array
    {
        $valid = array_values(array_filter(
            $points ?? [],
            fn ($p) => is_array($p) && is_numeric($p['lat'] ?? null) && is_numeric($p['lng'] ?? null),
        ));

        if (count($valid) < 2) {
            return null;
        }

        // ฉายลงระนาบแบบ equirectangular — พอสำหรับเส้นทางไม่กี่สิบกิโลเมตร
        // คูณ cos(ละติจูดกลาง) ไม่งั้นเส้นทางทางเหนือจะถูกยืดแนวนอน
        $meanLat = array_sum(array_map(fn ($p) => (float) $p['lat'], $valid)) / count($valid);
        $k = cos(deg2rad($meanLat));
        $xs = array_map(fn ($p) => (float) $p['lng'] * $k, $valid);
        $ys = array_map(fn ($p) => -(float) $p['lat'], $valid);

        $minX = min($xs);
        $minY = min($ys);
        $w = max($xs) - $minX;
        $h = max($ys) - $minY;
        $span = max($w, $h);

        // ทุกจุดซ้อนกันที่เดียว (ยืนนิ่งแล้วกดบันทึก) — วาดเป็นเส้นไม่ได้
        if ($span <= 0) {
            return null;
        }

        $shape = [];
        foreach ($xs as $i => $x) {
            // ย่อลงกรอบที่ด้านยาวเท่ากับ 1 แล้วจัดกึ่งกลางด้านสั้น — ผู้วาดแค่คูณ
            // ขนาดกล่องสี่เหลี่ยมจัตุรัส ไม่ต้องรู้สัดส่วนเดิม
            $shape[] = [
                round(($x - $minX) / $span + (1 - $w / $span) / 2, 4),
                round(($ys[$i] - $minY) / $span + (1 - $h / $span) / 2, 4),
            ];
        }

        return [
            'aspect' => round($h > 0 ? $w / $h : 99.0, 3),
            'points' => $shape,
            'elevations' => $this->profile($valid),
        ];
    }

    /**
     * ความสูงตามระยะทาง สุ่มเท่า ๆ กันตามกิโลเมตร (ไม่ใช่ตามลำดับจุด) — ช่วงที่
     * GPS เก็บถี่ไม่ถูกขยายเกินจริง คืน null เมื่อแทร็กไม่มีข้อมูลความสูง
     *
     * @param  array<int, array<string, mixed>>  $points
     * @return array<int, int>|null
     */
    private function profile(array $points): ?array
    {
        $withEle = array_values(array_filter(
            $points,
            fn ($p) => is_numeric($p['ele'] ?? null) && is_numeric($p['km'] ?? null),
        ));

        if (count($withEle) < 2) {
            return null;
        }

        $total = (float) end($withEle)['km'];

        if ($total <= 0) {
            return null;
        }

        $out = [];
        $j = 0;
        $n = count($withEle);

        for ($i = 0; $i < self::PROFILE_SAMPLES; $i++) {
            $at = $total * $i / (self::PROFILE_SAMPLES - 1);

            while ($j < $n - 2 && (float) $withEle[$j + 1]['km'] < $at) {
                $j++;
            }

            $a = $withEle[$j];
            $b = $withEle[$j + 1];
            $spanKm = (float) $b['km'] - (float) $a['km'];
            $t = $spanKm > 0 ? max(0, min(1, ($at - (float) $a['km']) / $spanKm)) : 0;
            $out[] = (int) round((float) $a['ele'] + ((float) $b['ele'] - (float) $a['ele']) * $t);
        }

        return $out;
    }

    /** แทร็กนี้ใช้เป็น "เส้นทางที่เดินจริง" ได้ไหม */
    public function isUsableTrack(?TripTrack $track): bool
    {
        return $track !== null
            && (float) $track->distance_km >= self::MIN_TRACK_KM
            && count($track->points ?? []) >= self::MIN_POINTS;
    }

    /**
     * เส้นทางของเหรียญ — แทร็กของตัวเองก่อน ไม่มีค่อยใช้เส้นทางของทริป
     *
     * @return array<string, mixed>|null
     */
    public function routeFor(?TripTrack $track, Trip $trip): ?array
    {
        if ($this->isUsableTrack($track)) {
            $shape = $this->shape($track->points);

            if ($shape !== null) {
                return ['source' => 'recorded', ...$shape];
            }
        }

        $planned = $this->shape($trip->route_track['points'] ?? null);

        return $planned === null ? null : ['source' => 'planned', ...$planned];
    }

    /**
     * ตัวเลขที่เดินจริงจาก GPS ของคนนี้ — null เมื่อไม่ได้บันทึก
     *
     * @return array{distance_km: float, elevation_gain_m: int, moving_seconds: int, avg_speed_kmh: float|null, max_elevation_m: int|null}|null
     */
    public function personal(?TripTrack $track): ?array
    {
        if (! $this->isUsableTrack($track)) {
            return null;
        }

        $distance = round((float) $track->distance_km, 1);
        $moving = (int) $track->moving_seconds;

        return [
            'distance_km' => $distance,
            'elevation_gain_m' => (int) $track->elevation_gain_m,
            'moving_seconds' => $moving,
            'avg_speed_kmh' => $moving >= 60 ? round($distance / ($moving / 3600), 1) : null,
            'max_elevation_m' => $track->max_elevation_m,
        ];
    }

    /**
     * สถิติส่วนตัวสูงสุด — แทร็กไหนถือสถิติอะไร (เดินไกลสุด/ไต่สูงสุด/ขึ้นสูงสุด)
     *
     * นับเฉพาะเมื่อมีแทร็กอย่างน้อยสองแทร็ก เพราะแทร็กแรกชนะทุกอย่างอยู่แล้วโดย
     * ไม่มีความหมาย สถิติที่เสมอกันเป็นของแทร็กที่ทำได้ก่อน (แบบเดียวกับการ
     * ทำลายสถิติ — ต้องมากกว่า ไม่ใช่เท่า)
     *
     * @param  Collection<int, TripTrack>  $tracks  แทร็กที่ใช้ได้ทั้งหมดของผู้ใช้
     * @return array<int, array<int, string>> track id => ['distance', 'climb', 'altitude']
     */
    public function records(Collection $tracks): array
    {
        $usable = $tracks
            ->filter(fn (TripTrack $t) => $this->isUsableTrack($t))
            ->sortBy(fn (TripTrack $t) => [$t->started_at?->timestamp ?? PHP_INT_MAX, $t->id])
            ->values();

        if ($usable->count() < 2) {
            return [];
        }

        $metrics = [
            'distance' => fn (TripTrack $t) => (float) $t->distance_km,
            'climb' => fn (TripTrack $t) => (float) $t->elevation_gain_m,
            'altitude' => fn (TripTrack $t) => (float) ($t->max_elevation_m ?? 0),
        ];

        $holders = [];

        foreach ($metrics as $key => $value) {
            $best = null;

            foreach ($usable as $track) {
                $v = $value($track);

                if ($v > 0 && ($best === null || $v > $value($best))) {
                    $best = $track;
                }
            }

            if ($best !== null) {
                $holders[$best->id][] = $key;
            }
        }

        return $holders;
    }
}
