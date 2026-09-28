<?php

namespace App\Services;

use App\Models\ChallengeCompletion;
use App\Models\SmartNotification;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ชาเลนจ์รายเดือน/รายปี — เป้าที่ชวนให้กลับมาเดินอีก นับจากเหรียญพิชิต
 * (คือหลักฐานว่าเดินจบจริง) ตามวันที่พิชิต ไม่ใช่วันที่จอง
 *
 * ความคืบหน้าคำนวณสดทุกครั้ง ส่วน challenge_completions จำแค่ว่าสำเร็จเมื่อไร
 * (= วันพิชิตของเหรียญที่ทำให้ถึงเป้า) ไว้แจ้งเตือนครั้งเดียวและโชว์ประวัติ
 *
 * ชาเลนจ์เป็นชุดตายตัวในโค้ด ไม่มีหน้าแอดมิน — เป้าต้องเท่ากันทุกคน ไม่งั้น
 * "สำเร็จ" จะไม่มีความหมายเวลาเอาไปอวดกัน
 */
class ChallengeService
{
    private const TIMEZONE = 'Asia/Bangkok';

    /** ส่ง push ตอนเที่ยงวันถัดจากวันที่สำเร็จ — 10:00 เป็นของ push เหรียญ ไม่ให้ซ้อนกัน */
    public const NOTIFY_HOUR = 12;

    public const NOTIFY_WINDOW_DAYS = 3;

    /**
     * metric: trips | distance (กม.) | climb (ม.) | regions (ภาค/ประเทศที่ไม่ซ้ำ)
     * icon: ชื่อ Material Symbols (แอปแมปเป็น IconData)
     */
    public const MONTHLY = [
        'month_trip' => [
            'title' => 'ลุยประจำเดือน',
            'description' => 'เดินทริปให้จบอย่างน้อย 1 ทริปภายในเดือนนี้',
            'metric' => 'trips',
            'target' => 1,
            'icon' => 'event_available',
        ],
        'month_climb' => [
            'title' => 'ไต่ให้ถึง 1,000 เมตร',
            'description' => 'ไต่ความสูงสะสมให้ครบ 1,000 ม. ภายในเดือนนี้',
            'metric' => 'climb',
            'target' => 1000,
            'icon' => 'trending_up',
        ],
    ];

    public const YEARLY = [
        'year_distance' => [
            'title' => '100 กิโลเมตรแห่งปี',
            'description' => 'เดินสะสมให้ครบ 100 กม. ภายในปีนี้',
            'metric' => 'distance',
            'target' => 100,
            'icon' => 'hiking',
        ],
        'year_climb' => [
            'title' => 'ไต่ 5,000 เมตร',
            'description' => 'ไต่ความสูงสะสมให้ครบ 5,000 ม. ภายในปีนี้ (เกือบสองเท่าดอยอินทนนท์)',
            'metric' => 'climb',
            'target' => 5000,
            'icon' => 'landscape',
        ],
        'year_trips' => [
            'title' => '6 ทริปใน 1 ปี',
            'description' => 'เดินทริปให้จบ 6 ทริปภายในปีนี้',
            'metric' => 'trips',
            'target' => 6,
            'icon' => 'flag',
        ],
        'year_regions' => [
            'title' => 'นักสำรวจ 3 ภูมิภาค',
            'description' => 'เดินทริปให้ครบ 3 ภาค (หรือประเทศ) ที่ไม่ซ้ำกันภายในปีนี้',
            'metric' => 'regions',
            'target' => 3,
            'icon' => 'map',
        ],
    ];

    public function __construct(private MedalStatsService $stats) {}

    /**
     * ชาเลนจ์เดือนนี้/ปีนี้พร้อมความคืบหน้า + ประวัติที่เคยทำสำเร็จ
     *
     * @return array<string, mixed>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->stats->forUser($userId);
        $this->sync($userId, $rows);

        $now = CarbonImmutable::now(self::TIMEZONE);
        $month = $now->format('Y-m');
        $year = $now->format('Y');

        $completions = ChallengeCompletion::where('user_id', $userId)
            ->orderByDesc('completed_on')
            ->orderByDesc('id')
            ->get();

        $done = $completions->keyBy(fn (ChallengeCompletion $c) => $c->challenge_key.'@'.$c->period);

        return [
            'month' => [
                'period' => $month,
                'label' => ThaiDate::monthYear($now),
                'ends_on' => $now->endOfMonth()->toDateString(),
                'days_left' => (int) $now->startOfDay()->diffInDays($now->endOfMonth()->startOfDay()),
                'challenges' => $this->present(self::MONTHLY, $this->inPeriod($rows, $month), $month, $done),
            ],
            'year' => [
                'period' => $year,
                'label' => 'ปี '.((int) $year + 543),
                'ends_on' => $now->endOfYear()->toDateString(),
                'days_left' => (int) $now->startOfDay()->diffInDays($now->endOfYear()->startOfDay()),
                'challenges' => $this->present(self::YEARLY, $this->inPeriod($rows, $year), $year, $done),
            ],
            'history' => $completions
                ->map(fn (ChallengeCompletion $c) => $this->historyItem($c))
                ->filter()
                ->values()
                ->all(),
            'completed_count' => $completions->count(),
        ];
    }

    /**
     * บันทึกความสำเร็จที่ยังไม่ได้บันทึก — ทุกเดือน/ปีที่มีเหรียญ (รวมย้อนหลัง
     * เพราะเหรียญทริปเก่าถูกแจกย้อนหลังได้) คืนจำนวนที่เพิ่งบันทึก
     *
     * @param  Collection<int, array<string, mixed>>|null  $rows
     */
    public function sync(int $userId, ?Collection $rows = null): int
    {
        $rows ??= $this->stats->forUser($userId);

        if ($rows->isEmpty()) {
            return 0;
        }

        $existing = ChallengeCompletion::where('user_id', $userId)
            ->get()
            ->keyBy(fn (ChallengeCompletion $c) => $c->challenge_key.'@'.$c->period);

        $periods = [
            [self::MONTHLY, $rows->map(fn ($r) => $r['medal']->earned_on->format('Y-m'))->unique()],
            [self::YEARLY, $rows->map(fn ($r) => $r['medal']->earned_on->format('Y'))->unique()],
        ];

        $created = 0;

        foreach ($periods as [$catalog, $keys]) {
            foreach ($keys as $period) {
                $inPeriod = $this->inPeriod($rows, $period);

                foreach ($catalog as $key => $def) {
                    if ($existing->has($key.'@'.$period)) {
                        continue;
                    }

                    $reachedOn = $this->reachedOn($inPeriod, $def['metric'], $def['target']);

                    if ($reachedOn === null) {
                        continue;
                    }

                    ChallengeCompletion::firstOrCreate(
                        ['user_id' => $userId, 'challenge_key' => $key, 'period' => $period],
                        ['completed_on' => $reachedOn],
                    );
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * push "สำเร็จชาเลนจ์" — เที่ยงวันถัดจากวันที่สำเร็จ ของเก่า (สำเร็จย้อนหลัง
     * เพราะเพิ่งเปิดระบบ) บันทึกเงียบ ๆ ไม่ปลุกคน
     */
    public function notifyPending(): int
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $due = [];

        ChallengeCompletion::query()
            ->whereNull('notified_at')
            ->chunkById(200, function (Collection $completions) use ($now, &$due) {
                foreach ($completions as $completion) {
                    $opensAt = CarbonImmutable::parse($completion->completed_on->toDateString(), self::TIMEZONE)
                        ->addDay()
                        ->setTime(self::NOTIFY_HOUR, 0);

                    if ($this->definition($completion->challenge_key) === null
                        || $now->greaterThanOrEqualTo($opensAt->addDays(self::NOTIFY_WINDOW_DAYS))) {
                        $completion->update(['notified_at' => now()]);

                        continue;
                    }

                    if ($now->greaterThanOrEqualTo($opensAt)) {
                        $due[$completion->user_id][] = $completion;
                    }
                }
            });

        $sent = 0;

        // หนึ่งทริปทำให้สำเร็จได้หลายชาเลนจ์พร้อมกัน — รวมเป็น push เดียวต่อคน
        foreach ($due as $userId => $completions) {
            $titles = array_map(
                fn (ChallengeCompletion $c) => $this->definition($c->challenge_key)['title'],
                $completions,
            );
            $first = $completions[0];

            try {
                SmartNotification::send(
                    (int) $userId,
                    'challenge_completed',
                    count($titles) === 1
                        ? '🏆 สำเร็จชาเลนจ์ "'.$titles[0].'"'
                        : '🏆 สำเร็จ '.count($titles).' ชาเลนจ์รวดเดียว!',
                    count($titles) === 1
                        ? 'ชาเลนจ์ของ'.$this->periodLabel($first->period).' สำเร็จแล้ว เก่งมาก! แตะเพื่อดูชาเลนจ์ถัดไป'
                        : implode(' · ', $titles).' — แตะเพื่อดูชาเลนจ์ถัดไป',
                    [
                        'challenge_key' => $first->challenge_key,
                        'period' => $first->period,
                        'route' => 'challenges',
                    ],
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Challenge push failed', ['user_id' => $userId, 'message' => $e->getMessage()]);
            }

            ChallengeCompletion::whereIn('id', array_map(fn ($c) => $c->id, $completions))
                ->update(['notified_at' => now()]);
        }

        return $sent;
    }

    // ── คำนวณ ───────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function inPeriod(Collection $rows, string $period): Collection
    {
        $format = strlen($period) === 4 ? 'Y' : 'Y-m';

        return $rows
            ->filter(fn ($r) => $r['medal']->earned_on->format($format) === $period)
            ->values();
    }

    /**
     * ค่าสะสมของ metric ตามลำดับเหรียญ — คืนค่ารวมสุดท้าย
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function value(Collection $rows, string $metric): float
    {
        return match ($metric) {
            'trips' => (float) $rows->count(),
            'distance' => round((float) $rows->sum('distance_km'), 1),
            'climb' => (float) $rows->sum('climb_m'),
            'regions' => (float) $rows->pluck('region_key')->filter()->unique()->count(),
            default => 0.0,
        };
    }

    /**
     * วันที่ถึงเป้าเป็นครั้งแรก (วันพิชิตของเหรียญที่ทำให้ถึง) — null ถ้ายังไม่ถึง
     *
     * @param  Collection<int, array<string, mixed>>  $rows  เรียงตามเวลาแล้ว
     */
    private function reachedOn(Collection $rows, string $metric, float $target): ?string
    {
        $seen = collect();

        foreach ($rows as $row) {
            $seen->push($row);

            if ($this->value($seen, $metric) >= $target) {
                return $row['medal']->earned_on->toDateString();
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<string, ChallengeCompletion>  $done
     * @return array<int, array<string, mixed>>
     */
    private function present(array $catalog, Collection $rows, string $period, Collection $done): array
    {
        $out = [];

        foreach ($catalog as $key => $def) {
            $current = $this->value($rows, $def['metric']);
            $completion = $done->get($key.'@'.$period);

            $out[] = [
                'key' => $key,
                'title' => $def['title'],
                'description' => $def['description'],
                'metric' => $def['metric'],
                'icon' => $def['icon'],
                'current' => $current,
                'target' => $def['target'],
                'unit' => $this->unit($def['metric']),
                'progress' => round(min(1, $current / $def['target']), 3),
                'completed' => $completion !== null || $current >= $def['target'],
                'completed_on' => $completion?->completed_on?->toDateString(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function historyItem(ChallengeCompletion $completion): ?array
    {
        $def = $this->definition($completion->challenge_key);

        if ($def === null) {
            return null;
        }

        return [
            'key' => $completion->challenge_key,
            'title' => $def['title'],
            'icon' => $def['icon'],
            'period' => $completion->period,
            'period_label' => $this->periodLabel($completion->period),
            'completed_on' => $completion->completed_on->toDateString(),
            'completed_label' => ThaiDate::full($completion->completed_on),
        ];
    }

    /** @return array<string, mixed>|null */
    public function definition(string $key): ?array
    {
        return self::MONTHLY[$key] ?? self::YEARLY[$key] ?? null;
    }

    public function periodLabel(string $period): string
    {
        if (strlen($period) === 4) {
            return 'ปี '.((int) $period + 543);
        }

        return ThaiDate::monthYear(CarbonImmutable::parse($period.'-01', self::TIMEZONE));
    }

    private function unit(string $metric): string
    {
        return match ($metric) {
            'trips' => 'ทริป',
            'distance' => 'กม.',
            'climb' => 'ม.',
            'regions' => 'ภูมิภาค',
            default => '',
        };
    }
}
