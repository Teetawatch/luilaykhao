<?php

namespace App\Services;

use App\Models\ChallengeCompletion;
use App\Models\MedalKudos;
use App\Models\SmartNotification;
use App\Models\TripMedal;
use App\Models\User;
use App\Support\MedalDesign;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * สรุปทั้งปี (Year in Review) — สไลด์แบบ Wrapped ของทุกทริปที่เดินจบในปีนั้น
 *
 * นับจากเหรียญพิชิต (ทริปที่เดินจบจริง) ตามวันพิชิต ใช้ตัวเลขชุดเดียวกับ
 * ชาเลนจ์ (MedalStatsService — GPS ก่อน ไม่มีค่อยใช้ของทริป)
 */
class YearReviewService
{
    private const TIMEZONE = 'Asia/Bangkok';

    /** วันที่ส่ง push "สรุปปีของคุณพร้อมแล้ว" — ปลายปีที่ทริปส่วนใหญ่ของปีจบแล้ว */
    public const NOTIFY_MONTH = 12;

    public const NOTIFY_DAY = 28;

    public function __construct(
        private MedalStatsService $stats,
        private MedalService $medals,
        private ModerationService $moderation,
    ) {}

    /**
     * @param  int|null  $year  ค.ศ. — null = ปีปัจจุบันตามเวลาไทย
     * @return array<string, mixed>
     */
    public function forUser(User $user, ?int $year = null): array
    {
        $year ??= (int) CarbonImmutable::now(self::TIMEZONE)->format('Y');
        $all = $this->stats->forUser($user->id);

        $years = $all
            ->map(fn ($r) => (int) $r['medal']->earned_on->format('Y'))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        $rows = $all
            ->filter(fn ($r) => (int) $r['medal']->earned_on->format('Y') === $year)
            ->values();

        $medalIds = $rows->map(fn ($r) => $r['medal']->id);
        $scheduleIds = $rows->map(fn ($r) => $r['medal']->schedule_id);
        $hidden = $this->moderation->hiddenAuthorIds($user);

        $climb = (int) $rows->sum('climb_m');
        $longest = $rows->sortByDesc('distance_km')->first();
        $highest = $rows->filter(fn ($r) => $r['max_elevation_m'] !== null)->sortByDesc('max_elevation_m')->first();

        $byMonth = $rows->groupBy(fn ($r) => (int) $r['medal']->earned_on->format('n'));
        $topMonth = $byMonth->sortByDesc(fn ($group) => $group->count())->keys()->first();

        return [
            'year' => $year,
            'year_label' => (string) ($year + 543),
            'available_years' => $years,
            'holder_name' => $this->medals->holderName($user),
            'trips_count' => $rows->count(),
            'distance_km' => round((float) $rows->sum('distance_km'), 1),
            'climb_m' => $climb,
            'inthanon_multiple' => $climb > 0 ? round($climb / PassportService::DOI_INTHANON_M, 1) : 0.0,
            'days_on_trail' => (int) $rows->sum('days'),
            'gps_trips' => $rows->where('gps', true)->count(),
            'months_active' => $byMonth->count(),
            'top_month' => $topMonth ? ThaiDate::monthName($topMonth) : null,
            'top_month_trips' => $topMonth ? $byMonth[$topMonth]->count() : 0,
            'places' => $rows->pluck('region_label')->filter()->unique()->values()->all(),
            'longest' => $longest && $longest['distance_km'] > 0 ? [
                'name' => MedalDesign::name($longest['medal']->trip),
                'distance_km' => round($longest['distance_km'], 1),
            ] : null,
            'highest' => $highest ? [
                'name' => MedalDesign::name($highest['medal']->trip),
                'elevation_m' => (int) $highest['max_elevation_m'],
            ] : null,
            // คนที่เดินจบรอบเดียวกันกับเรา (ไม่นับตัวเอง/คนที่บล็อกกัน)
            'companions_count' => $scheduleIds->isEmpty() ? 0 : TripMedal::query()
                ->whereIn('schedule_id', $scheduleIds)
                ->where('user_id', '!=', $user->id)
                ->whereNotIn('user_id', $hidden)
                ->distinct()
                ->count('user_id'),
            'kudos_received' => $medalIds->isEmpty() ? 0 : MedalKudos::query()
                ->whereIn('medal_id', $medalIds)
                ->whereNotIn('user_id', $hidden)
                ->count(),
            'challenges_completed' => ChallengeCompletion::query()
                ->where('user_id', $user->id)
                ->where(fn ($q) => $q->where('period', (string) $year)
                    ->orWhere('period', 'like', $year.'-%'))
                ->count(),
            'medals' => $rows
                ->map(fn ($r) => [
                    'id' => $r['medal']->id,
                    'finisher_label' => 'Finisher #'.$r['medal']->finisher_no,
                    'earned_on' => $r['medal']->earned_on->toDateString(),
                    'design' => MedalDesign::forTrip($r['medal']->trip),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * push "สรุปปีพร้อมแล้ว" ครั้งเดียวต่อคนต่อปี — ให้ทุกคนที่มีเหรียญในปีนี้
     */
    public function notifyYearReady(?int $year = null): int
    {
        $year ??= (int) CarbonImmutable::now(self::TIMEZONE)->format('Y');
        $sent = 0;

        TripMedal::query()
            ->whereYear('earned_on', $year)
            ->distinct()
            ->pluck('user_id')
            ->chunk(200)
            ->each(function ($userIds) use ($year, &$sent) {
                foreach ($userIds as $userId) {
                    $already = SmartNotification::where('user_id', $userId)
                        ->where('type', 'year_review')
                        ->where('data->year', $year)
                        ->exists();

                    if ($already) {
                        continue;
                    }

                    try {
                        SmartNotification::send(
                            (int) $userId,
                            'year_review',
                            '✨ สรุปปี '.($year + 543).' ของคุณพร้อมแล้ว',
                            'ทริปที่พิชิต ระยะทาง ความสูงที่ไต่ และเหรียญทั้งปี — แตะเพื่อดูและแชร์',
                            ['year' => $year, 'route' => 'year_review'],
                        );
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::warning('Year review push failed', ['user_id' => $userId, 'message' => $e->getMessage()]);
                    }
                }
            });

        return $sent;
    }
}
