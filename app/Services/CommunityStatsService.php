<?php

namespace App\Services;

use App\Models\BookingMember;
use App\Models\Review;
use App\Models\TripPost;
use App\Models\TripSchedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * สถิติรวมของชุมชน — ตัวเลข "จริง" ที่ดึงจากทริปที่เดินทางจบไปแล้ว ใช้แทน
 * ตัวเลขโฆษณาแบบ "ลูกค้า 5,000+ คน" ที่ไม่มีที่มา.
 *
 * ระยะทาง/ความสูงคิดแบบ person-distance: รอบที่จบแล้ว × จำนวนที่นั่งที่ถูกจอง
 * เพราะสิ่งที่เราเล่าคือ "ชุมชนเราเดินรวมกันไปเท่าไหร่" ไม่ใช่ระยะทางของเส้นทาง.
 *
 * ผลลัพธ์ cache ไว้ 1 ชม. — ตัวเลขระดับนี้ไม่จำเป็นต้องสดวินาทีต่อวินาที
 * และหน้าแรกเรียกใช้ทุก request.
 */
class CommunityStatsService
{
    /** ดอยอินทนนท์ ยอดสูงสุดของไทย ~2,565 ม. */
    public const DOI_INTHANON_M = PassportService::DOI_INTHANON_M;

    /** ยอดเอเวอเรสต์ 8,849 ม. */
    public const EVEREST_M = 8849;

    public const CACHE_KEY = 'community_stats';

    public const CACHE_TTL_SECONDS = 3600;

    public function get(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->compute());
    }

    /** ล้าง cache — เรียกหลังปิดรอบเดินทาง/แก้ระยะทางทริป. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function compute(): array
    {
        // รอบที่ "เดินทางจบแล้ว" เท่านั้น — ยังไม่ไปก็ยังไม่นับเป็นระยะทางสะสม
        $completed = TripSchedule::query()
            ->join('trips', 'trips.id', '=', 'trip_schedules.trip_id')
            ->whereDate(
                DB::raw('COALESCE(trip_schedules.return_date, trip_schedules.departure_date)'),
                '<',
                now('Asia/Bangkok')->toDateString()
            )
            ->where('trip_schedules.status', '!=', 'cancelled')
            ->where('trip_schedules.booked_seats', '>', 0)
            ->selectRaw('
                COALESCE(SUM(trip_schedules.booked_seats * COALESCE(trips.distance_km, 0)), 0) as person_km,
                COALESCE(SUM(trip_schedules.booked_seats * COALESCE(trips.elevation_gain_m, 0)), 0) as person_elevation,
                COALESCE(SUM(trip_schedules.booked_seats * COALESCE(trips.duration_days, 0)), 0) as person_days,
                COUNT(*) as rounds,
                COALESCE(SUM(trip_schedules.booked_seats), 0) as seats
            ')
            ->first();

        $personKm = round((float) ($completed->person_km ?? 0), 1);
        $personElevation = (int) round((float) ($completed->person_elevation ?? 0));

        return [
            'total_distance_km' => $personKm,
            'total_elevation_gain_m' => $personElevation,
            'total_traveller_days' => (int) ($completed->person_days ?? 0),
            'rounds_completed' => (int) ($completed->rounds ?? 0),
            'seats_travelled' => (int) ($completed->seats ?? 0),
            'travellers_count' => $this->travellersCount(),
            'regions_count' => $this->regionsCount(),
            'photos_count' => TripPost::published()->count(),
            'reviews_count' => Review::where('is_approved', true)->count(),
            'avg_rating' => round((float) (Review::where('is_approved', true)->avg('rating') ?: 5.0), 1),
            'highlights' => [
                'doi_inthanon_m' => self::DOI_INTHANON_M,
                'everest_m' => self::EVEREST_M,
                // ความสูงสะสมของทั้งชุมชนเทียบเป็นกี่รอบของยอดนั้น ๆ
                'inthanon_multiple' => $personElevation > 0
                    ? round($personElevation / self::DOI_INTHANON_M, 1)
                    : 0.0,
                'everest_multiple' => $personElevation > 0
                    ? round($personElevation / self::EVEREST_M, 1)
                    : 0.0,
            ],
        ];
    }

    /** จำนวนคนที่เดินทางจริง — เจ้าของการจอง + เพื่อนร่วมทาง (นับไม่ซ้ำคน). */
    private function travellersCount(): int
    {
        $owners = DB::table('bookings')
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        $members = BookingMember::where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return $owners->merge($members)->unique()->count();
    }

    /** จำนวนภูมิภาคที่ชุมชนเคยไปถึง (จากทริปที่มีรอบเดินทางจบแล้ว). */
    private function regionsCount(): int
    {
        return TripSchedule::query()
            ->join('trips', 'trips.id', '=', 'trip_schedules.trip_id')
            ->whereDate(
                DB::raw('COALESCE(trip_schedules.return_date, trip_schedules.departure_date)'),
                '<',
                now('Asia/Bangkok')->toDateString()
            )
            ->where('trip_schedules.status', '!=', 'cancelled')
            ->where('trip_schedules.booked_seats', '>', 0)
            ->whereNotNull('trips.region')
            ->distinct()
            ->count('trips.region');
    }
}
