<?php

namespace App\Services;

use App\Models\Place;
use App\Models\TripMedal;
use App\Models\TripTrack;
use App\Support\Countries;
use Illuminate\Support\Collection;

/**
 * ตัวเลขต่อเหรียญ — แหล่งเดียวที่ชาเลนจ์และสรุปทั้งปีใช้นับ ระยะ/ความสูง
 *
 * ใช้ตัวเลขที่เดินจริงจาก GPS ก่อนเสมอ (แบบเดียวกับการ์ดแชร์) ไม่มีค่อยใช้
 * ตัวเลขของเส้นทางทริปที่แอดมินกรอก — คนที่บันทึก GPS จะได้ความคืบหน้าตาม
 * ที่เดินจริง คนที่ไม่ได้บันทึกก็ยังนับได้จากเส้นทางของทริป
 */
class MedalStatsService
{
    public function __construct(private MedalRouteService $routes) {}

    /**
     * เหรียญทั้งหมดของผู้ใช้ พร้อมตัวเลขของแต่ละเหรียญ เรียงจากเก่าไปใหม่
     *
     * @return Collection<int, array{medal: TripMedal, distance_km: float, climb_m: int, max_elevation_m: int|null, days: int, region_key: string|null, region_label: string|null, gps: bool}>
     */
    public function forUser(int $userId): Collection
    {
        $medals = TripMedal::query()
            ->where('user_id', $userId)
            ->with(['trip', 'schedule'])
            ->get()
            ->filter(fn (TripMedal $m) => $m->trip !== null);

        $tracks = TripTrack::where('user_id', $userId)->get()->keyBy('schedule_id');

        return $medals
            ->sortBy(fn (TripMedal $m) => [$m->earned_on->timestamp, $m->id])
            ->map(fn (TripMedal $m) => $this->metrics($m, $tracks->get($m->schedule_id)))
            ->values();
    }

    /**
     * @return array{medal: TripMedal, distance_km: float, climb_m: int, max_elevation_m: int|null, days: int, region_key: string|null, region_label: string|null, gps: bool}
     */
    public function metrics(TripMedal $medal, ?TripTrack $track): array
    {
        $trip = $medal->trip;
        $personal = $this->routes->personal($track);

        [$regionKey, $regionLabel] = $trip->isInternational()
            ? ($trip->country_code
                ? ['country:'.$trip->country_code, Countries::label($trip->country_code)]
                : [null, null])
            : ($trip->region
                ? ['region:'.$trip->region, Place::REGIONS[$trip->region] ?? $trip->region]
                : [null, null]);

        return [
            'medal' => $medal,
            'distance_km' => $personal ? (float) $personal['distance_km'] : (float) ($trip->distance_km ?? 0),
            'climb_m' => $personal ? (int) $personal['elevation_gain_m'] : (int) ($trip->elevation_gain_m ?? 0),
            'max_elevation_m' => $personal['max_elevation_m'] ?? null,
            'days' => max(1, (int) ($trip->duration_days ?? 1)),
            'region_key' => $regionKey,
            'region_label' => $regionLabel,
            'gps' => $personal !== null,
        ];
    }
}
