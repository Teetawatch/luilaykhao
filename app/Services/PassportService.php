<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Trip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Passport / สมุดสะสมการเดินทาง — รวมสถิติการเดินทางตลอดชีพของผู้ใช้จาก
 * ทริปที่ "จบแล้ว" (วันเดินทางผ่านไปแล้ว) และคำนวณตราสะสม (badges) ที่ปลดล็อก.
 *
 * นับเฉพาะการจองที่ผู้ใช้เป็นเจ้าของหรือเพื่อนร่วมเดินทาง (active member) และ
 * สถานะ confirmed/completed เท่านั้น. นับ 1 ครั้งต่อ 1 รอบ (schedule) เพื่อไม่ให้
 * เจ้าของ+เพื่อนหรือหลายการจองในรอบเดียวถูกนับซ้ำ.
 */
class PassportService
{
    /** ดอยอินทนนท์ ยอดสูงสุดของไทย ~2,565 ม. — ใช้เทียบความสูงสะสมแบบสนุก ๆ. */
    public const DOI_INTHANON_M = 2565;

    /** เดือนหน้าฝนของไทย (มิ.ย.–ต.ค.). */
    private const RAINY_MONTHS = [6, 7, 8, 9, 10];

    /** เดือนหน้าหนาวของไทย (พ.ย.–ก.พ.). */
    private const WINTER_MONTHS = [11, 12, 1, 2];

    public function forUser(int $userId): array
    {
        $trips = $this->completedTrips($userId);

        $tripsCount = $trips->count();
        $totalDistance = round($trips->sum(fn (array $t) => (float) ($t['trip']->distance_km ?? 0)), 1);
        $totalElevation = (int) $trips->sum(fn (array $t) => (int) ($t['trip']->elevation_gain_m ?? 0));
        $totalDays = (int) $trips->sum(fn (array $t) => (int) ($t['trip']->duration_days ?? 0));

        $regions = $trips
            ->map(fn (array $t) => $t['trip']->region)
            ->filter()
            ->unique()
            ->values();

        return [
            'stats' => [
                'trips_count' => $tripsCount,
                'total_distance_km' => $totalDistance,
                'total_elevation_gain_m' => $totalElevation,
                'total_days' => $totalDays,
                'regions_count' => $regions->count(),
                'regions' => $regions->all(),
            ],
            'highlights' => [
                'doi_inthanon_m' => self::DOI_INTHANON_M,
                // ความสูงสะสมเป็นกี่เท่าของดอยอินทนนท์ (ปัดทศนิยม 1 ตำแหน่ง)
                'inthanon_multiple' => $totalElevation > 0
                    ? round($totalElevation / self::DOI_INTHANON_M, 1)
                    : 0.0,
            ],
            'badges' => $this->badges($trips),
        ];
    }

    /**
     * ทริปที่จบแล้วของผู้ใช้ — เปิดให้บริการอื่นใช้ได้ (เช่น TripReadinessService
     * ที่ต้องหาทริปหนักที่สุดที่เคยเดินจบ).
     *
     * @return Collection<int, array{trip: Trip, departure: Carbon, month: int}>
     */
    public function completedTripsFor(int $userId): Collection
    {
        return $this->completedTrips($userId);
    }

    /**
     * ทริปที่จบแล้วของผู้ใช้ (ไม่ซ้ำรอบ) พร้อมข้อมูลที่ใช้คำนวณ badge.
     *
     * @return Collection<int, array{trip: Trip, departure: Carbon, month: int}>
     */
    private function completedTrips(int $userId): Collection
    {
        $memberBookingIds = BookingMember::where('user_id', $userId)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->pluck('booking_id');

        $bookings = Booking::where(function ($q) use ($userId, $memberBookingIds) {
            $q->where('user_id', $userId)
                ->orWhereIn('id', $memberBookingIds);
        })
            ->whereIn('status', ['confirmed', 'completed'])
            ->with('schedule.trip')
            ->get();

        // นับ 1 ครั้งต่อรอบ (schedule) — เก็บ trip + วันออกเดินทางไว้คำนวณ badge ตามฤดู
        $bySchedule = [];

        foreach ($bookings as $booking) {
            $schedule = $booking->schedule;
            $trip = $schedule?->trip;

            if (! $schedule || ! $trip) {
                continue;
            }

            $endDate = $schedule->return_date ?? $schedule->departure_date;
            $departure = $schedule->departure_date;

            if (! $endDate || ! $departure) {
                continue;
            }

            // ถือว่าจบทริปเมื่อวันสุดท้ายผ่านไปแล้ว (สอดคล้องกับ Trip Recap)
            if (! $endDate->copy()->endOfDay()->isPast()) {
                continue;
            }

            $bySchedule[$schedule->id] = [
                'trip' => $trip,
                'departure' => $departure,
                'month' => (int) $departure->format('n'),
            ];
        }

        return collect(array_values($bySchedule));
    }

    /**
     * คำนวณตราสะสมทั้งหมด — earned/locked, ความคืบหน้า, และ "ปลดล็อกเมื่อ"
     * (earned_at = วันออกเดินทางของทริปที่ทำให้ปลดตรานั้น).
     *
     * @param  Collection<int, array{trip: Trip, departure: Carbon, month: int}>  $trips
     */
    private function badges(Collection $trips): array
    {
        // เรียงตามวันออกเดินทางจากเก่าไปใหม่ เพื่อไล่หา "ทริปที่ทำให้ปลดล็อก"
        $chron = $trips->sortBy(fn (array $t) => $t['departure']->timestamp)->values();

        $tripsCount = $chron->count();
        $totalDistance = $chron->sum(fn (array $t) => (float) ($t['trip']->distance_km ?? 0));
        $totalElevation = $chron->sum(fn (array $t) => (int) ($t['trip']->elevation_gain_m ?? 0));
        $regionsCount = $chron
            ->map(fn (array $t) => $t['trip']->region)
            ->filter()
            ->unique()
            ->count();

        // ตราแบบสะสมยอด: ไล่บวกทีละทริป คืนวันที่ยอดสะสมแตะเป้าครั้งแรก
        $reach = function (callable $metric, float $target) use ($chron): ?string {
            if ($target <= 0) {
                return null;
            }
            $running = 0.0;
            foreach ($chron as $t) {
                $running += $metric($t);
                if ($running >= $target) {
                    return $t['departure']->toDateString();
                }
            }

            return null;
        };

        // ตราภูมิภาค: คืนวันที่จำนวนภูมิภาคไม่ซ้ำแตะเป้าครั้งแรก
        $reachRegions = function (int $target) use ($chron): ?string {
            $seen = [];
            foreach ($chron as $t) {
                $region = $t['trip']->region;
                if ($region) {
                    $seen[$region] = true;
                }
                if (count($seen) >= $target) {
                    return $t['departure']->toDateString();
                }
            }

            return null;
        };

        // ตราพิเศษ: คืนวันของทริปแรกสุดที่เข้าเงื่อนไข
        $firstMatch = function (callable $matches) use ($chron): ?string {
            foreach ($chron as $t) {
                if ($matches($t)) {
                    return $t['departure']->toDateString();
                }
            }

            return null;
        };

        $tripMetric = fn (array $t) => 1.0;
        $distanceMetric = fn (array $t) => (float) ($t['trip']->distance_km ?? 0);
        $elevationMetric = fn (array $t) => (float) ($t['trip']->elevation_gain_m ?? 0);

        $threshold = fn (string $key, string $title, string $desc, string $emoji, float $current, float $target, ?string $earnedAt): array => [
            'key' => $key,
            'title' => $title,
            'description' => $desc,
            'emoji' => $emoji,
            'earned' => $earnedAt !== null,
            'earned_at' => $earnedAt,
            'progress' => [
                'current' => round($current, 1),
                'target' => round($target, 1),
            ],
        ];

        $flag = fn (string $key, string $title, string $desc, string $emoji, ?string $earnedAt): array => [
            'key' => $key,
            'title' => $title,
            'description' => $desc,
            'emoji' => $emoji,
            'earned' => $earnedAt !== null,
            'earned_at' => $earnedAt,
            'progress' => null,
        ];

        $badges = [
            // จำนวนทริป
            $threshold('first_summit', 'ก้าวแรก', 'จบทริปแรกกับเรา', '🥾', $tripsCount, 1, $reach($tripMetric, 1)),
            $threshold('explorer_5', 'นักสำรวจ', 'จบทริปครบ 5 ทริป', '🧭', $tripsCount, 5, $reach($tripMetric, 5)),
            $threshold('hunter_10', 'นักล่า 10 ยอดดอย', 'จบทริปครบ 10 ทริป', '⛰️', $tripsCount, 10, $reach($tripMetric, 10)),
            $threshold('legend_20', 'ตำนานนักเดิน', 'จบทริปครบ 20 ทริป', '👑', $tripsCount, 20, $reach($tripMetric, 20)),

            // ระยะทางสะสม
            $threshold('dist_100', '100 กิโลแรก', 'เดินสะสมครบ 100 กม.', '📍', $totalDistance, 100, $reach($distanceMetric, 100)),
            $threshold('dist_500', 'นักเดินทางไกล', 'เดินสะสมครบ 500 กม.', '🛤️', $totalDistance, 500, $reach($distanceMetric, 500)),
            $threshold('dist_1000', 'พันกิโลเมตร', 'เดินสะสมครบ 1,000 กม.', '🏅', $totalDistance, 1000, $reach($distanceMetric, 1000)),

            // ความสูงสะสม
            $threshold('elev_inthanon', 'พิชิตอินทนนท์', 'ไต่สะสมครบความสูงดอยอินทนนท์ (2,565 ม.)', '🏔️', $totalElevation, self::DOI_INTHANON_M, $reach($elevationMetric, self::DOI_INTHANON_M)),
            $threshold('elev_everest', 'สูงเท่าเอเวอเรสต์', 'ไต่สะสมครบ 8,849 ม.', '🗻', $totalElevation, 8849, $reach($elevationMetric, 8849)),

            // ภูมิภาค
            $threshold('region_3', 'นักสะสมภูมิภาค', 'เดินทางครบ 3 ภูมิภาค', '🗺️', $regionsCount, 3, $reachRegions(3)),
            $threshold('region_5', 'ทั่วไทย', 'เดินทางครบ 5 ภูมิภาค', '🇹🇭', $regionsCount, 5, $reachRegions(5)),

            // พิเศษ
            $flag('hardcore', 'สายโหด', 'จบทริประดับความยากสูงสุด', '🔥', $firstMatch(fn (array $t) => $t['trip']->difficulty === 'hard')),
            $flag('rainy_soul', 'สายหน้าฝน', 'ออกเดินทางในช่วงหน้าฝน', '🌧️', $firstMatch(fn (array $t) => in_array($t['month'], self::RAINY_MONTHS, true))),
            $flag('winter_soul', 'สายลมหนาว', 'ออกเดินทางในช่วงหน้าหนาว', '❄️', $firstMatch(fn (array $t) => in_array($t['month'], self::WINTER_MONTHS, true))),
        ];

        // เรียงตราที่ปลดล็อกแล้วขึ้นก่อน (คงลำดับเดิมภายในกลุ่ม)
        usort($badges, fn ($a, $b) => ($b['earned'] <=> $a['earned']));

        return $badges;
    }
}
