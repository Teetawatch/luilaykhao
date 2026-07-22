<?php

namespace App\Services;

use App\Models\Trip;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * แผนที่พิชิต — เอาทริปที่ผู้ใช้เดินจบแล้วมาวางบนแผนที่ประเทศไทย พร้อมสรุป
 * "ความลึก" ของแต่ละภาคที่เคยไป (กี่ทริป ระยะทางรวม ยอดที่สูงที่สุด ไปครั้งแรก
 * /ครั้งล่าสุดเมื่อไหร่).
 *
 * ตั้งใจให้เป็นข้อมูลของ "ผู้ใช้คนนี้" ล้วน ๆ ไม่ใช่หน้าขายทริป — ทริปที่ยังไม่เคย
 * ไปจึงคืนมาแยกก้อน (`frontier`) เป็นข้อมูลว่ายังเหลือภาคไหนที่ยังไม่เคยแตะ
 * ไม่ใช่รายการสินค้าแนะนำ.
 */
class ConquestMapService
{
    /** ภาคทั้งหมดตามที่ตัวเลือกในแอดมินกำหนดไว้ (เรียงจากเหนือลงใต้). */
    public const REGIONS = [
        'north' => 'ภาคเหนือ',
        'northeast' => 'ภาคอีสาน',
        'central' => 'ภาคกลาง',
        'bangkok' => 'กรุงเทพมหานคร',
        'east' => 'ภาคตะวันออก',
        'west' => 'ภาคตะวันตก',
        'south' => 'ภาคใต้',
    ];

    public function __construct(private PassportService $passport) {}

    public function forUser(int $userId): array
    {
        $completed = $this->passport->completedTripsFor($userId);

        // รวมหลายรอบของทริปเดียวกันเป็นหมุดเดียว แต่จำไว้ว่าไปมากี่ครั้ง
        $byTrip = [];
        foreach ($completed as $entry) {
            /** @var Trip $trip */
            $trip = $entry['trip'];
            $departure = $entry['departure'];

            if (! isset($byTrip[$trip->id])) {
                $byTrip[$trip->id] = [
                    'trip' => $trip,
                    'visits' => 0,
                    'first' => $departure,
                    'last' => $departure,
                ];
            }

            $byTrip[$trip->id]['visits']++;
            if ($departure->lt($byTrip[$trip->id]['first'])) {
                $byTrip[$trip->id]['first'] = $departure;
            }
            if ($departure->gt($byTrip[$trip->id]['last'])) {
                $byTrip[$trip->id]['last'] = $departure;
            }
        }

        $visited = collect(array_values($byTrip));

        return [
            'summary' => $this->summary($visited),
            'regions' => $this->regions($visited),
            'pins' => $this->pins($visited),
            'frontier' => $this->frontier($visited),
        ];
    }

    /**
     * @param  Collection<int, array{trip: Trip, visits: int, first: Carbon, last: Carbon}>  $visited
     */
    private function summary(Collection $visited): array
    {
        $regionsVisited = $visited
            ->map(fn (array $v) => $v['trip']->region)
            ->filter()
            ->unique();

        $highest = $visited
            ->sortByDesc(fn (array $v) => (int) ($v['trip']->elevation_gain_m ?? 0))
            ->first();

        return [
            'regions_visited' => $regionsVisited->count(),
            'regions_total' => count(self::REGIONS),
            'trips_visited' => $visited->count(),
            // ไปซ้ำนับด้วย — คนที่กลับไปที่เดิมบ่อย ๆ คือสัญญาณว่าชอบจริง
            'departures_count' => (int) $visited->sum(fn (array $v) => $v['visits']),
            'total_distance_km' => round($visited->sum(fn (array $v) => (float) ($v['trip']->distance_km ?? 0) * $v['visits']), 1),
            'total_elevation_gain_m' => (int) $visited->sum(fn (array $v) => (int) ($v['trip']->elevation_gain_m ?? 0) * $v['visits']),
            'toughest' => $highest ? [
                'title' => $highest['trip']->title,
                'slug' => $highest['trip']->slug,
                'elevation_gain_m' => (int) ($highest['trip']->elevation_gain_m ?? 0),
            ] : null,
        ];
    }

    /**
     * สรุปรายภาค — คืนครบทุกภาคเสมอ ภาคที่ยังไม่เคยไปคืน visited=false
     * เพื่อให้ฝั่งแอปวาด "ที่ยังไม่ได้พิชิต" ได้โดยไม่ต้องรู้รายชื่อภาคเอง.
     *
     * @param  Collection<int, array{trip: Trip, visits: int, first: Carbon, last: Carbon}>  $visited
     */
    private function regions(Collection $visited): array
    {
        $grouped = $visited->groupBy(fn (array $v) => $v['trip']->region ?? 'other');

        $out = [];

        foreach (self::REGIONS as $key => $label) {
            $items = $grouped->get($key, collect());

            if ($items->isEmpty()) {
                $out[] = [
                    'key' => $key,
                    'label' => $label,
                    'visited' => false,
                    'trips_count' => 0,
                    'departures_count' => 0,
                    'distance_km' => 0.0,
                    'elevation_gain_m' => 0,
                    'first_visit' => null,
                    'first_visit_label' => null,
                    'last_visit' => null,
                    'last_visit_label' => null,
                    'highest_trip' => null,
                ];

                continue;
            }

            $first = $items->min(fn (array $v) => $v['first']->timestamp);
            $last = $items->max(fn (array $v) => $v['last']->timestamp);
            $highest = $items->sortByDesc(fn (array $v) => (int) ($v['trip']->elevation_gain_m ?? 0))->first();

            $out[] = [
                'key' => $key,
                'label' => $label,
                'visited' => true,
                'trips_count' => $items->count(),
                'departures_count' => (int) $items->sum(fn (array $v) => $v['visits']),
                'distance_km' => round($items->sum(fn (array $v) => (float) ($v['trip']->distance_km ?? 0) * $v['visits']), 1),
                'elevation_gain_m' => (int) $items->sum(fn (array $v) => (int) ($v['trip']->elevation_gain_m ?? 0) * $v['visits']),
                'first_visit' => date('Y-m-d', $first),
                'first_visit_label' => ThaiDate::short(Carbon::createFromTimestamp($first)),
                'last_visit' => date('Y-m-d', $last),
                'last_visit_label' => ThaiDate::short(Carbon::createFromTimestamp($last)),
                'highest_trip' => $highest ? [
                    'title' => $highest['trip']->title,
                    'elevation_gain_m' => (int) ($highest['trip']->elevation_gain_m ?? 0),
                ] : null,
            ];
        }

        return $out;
    }

    /**
     * หมุดของทริปที่เคยไป — เฉพาะทริปที่มีพิกัด.
     *
     * @param  Collection<int, array{trip: Trip, visits: int, first: Carbon, last: Carbon}>  $visited
     */
    private function pins(Collection $visited): array
    {
        return $visited
            ->filter(fn (array $v) => $v['trip']->latitude !== null && $v['trip']->longitude !== null)
            ->sortBy(fn (array $v) => $v['first']->timestamp)
            ->values()
            ->map(fn (array $v) => [
                'trip_id' => $v['trip']->id,
                'title' => $v['trip']->title,
                'slug' => $v['trip']->slug,
                'region' => $v['trip']->region,
                'location' => $v['trip']->location,
                'latitude' => (float) $v['trip']->latitude,
                'longitude' => (float) $v['trip']->longitude,
                'thumbnail' => MediaDisk::url($v['trip']->thumbnail_image ?: $v['trip']->cover_image),
                'visits' => $v['visits'],
                'distance_km' => (float) ($v['trip']->distance_km ?? 0),
                'elevation_gain_m' => (int) ($v['trip']->elevation_gain_m ?? 0),
                'first_visit' => $v['first']->toDateString(),
                'first_visit_label' => ThaiDate::short($v['first']),
                'last_visit' => $v['last']->toDateString(),
                'last_visit_label' => ThaiDate::short($v['last']),
            ])
            ->all();
    }

    /**
     * ภาคที่ยังไม่เคยไป พร้อมจำนวนทริปที่เปิดอยู่ในภาคนั้น — เป็นข้อมูลว่ายังเหลือ
     * ที่ไหนให้ไป ไม่ได้คัดมาเชียร์ขาย จึงคืนแค่จำนวนกับพิกัดกลางของภาค.
     *
     * @param  Collection<int, array{trip: Trip, visits: int, first: Carbon, last: Carbon}>  $visited
     */
    private function frontier(Collection $visited): array
    {
        $visitedRegions = $visited
            ->map(fn (array $v) => $v['trip']->region)
            ->filter()
            ->unique()
            ->all();

        $openByRegion = Trip::query()
            ->where('status', 'active')
            ->whereNotNull('region')
            ->whereNotIn('region', $visitedRegions ?: ['__none__'])
            ->selectRaw('region, COUNT(*) as trips_count')
            ->groupBy('region')
            ->pluck('trips_count', 'region');

        $out = [];

        foreach (self::REGIONS as $key => $label) {
            if (in_array($key, $visitedRegions, true)) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $label,
                'open_trips_count' => (int) ($openByRegion[$key] ?? 0),
            ];
        }

        return $out;
    }
}
