<?php

namespace App\Services;

use App\Jobs\SyncPublicHolidaysJob;
use App\Models\PublicHoliday;
use App\Models\TripSchedule;
use App\Support\AppLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "หยุดยาวครั้งหน้า ไปไหนดี" — ช่วงวันหยุดต่อเนื่องที่กำลังจะมาถึง พร้อมรอบทริปที่
 * ไปกลับทันในช่วงนั้น
 *
 * คนไทยวางแผนเที่ยวตามวันหยุดยาว แต่ปฏิทินรอบเรียงตามวันที่ ลูกค้าต้องไล่เทียบเอง
 * ว่ารอบไหนไม่ต้องลางาน หน้านี้ตอบให้ตรง ๆ:
 * - หยุดยาว = เสาร์ อาทิตย์ หรือวันหยุดราชการ (public_holidays) ติดกันตั้งแต่ 3 วัน
 * - "ลาเพิ่ม 1 วัน" = มีวันทำงานวันเดียวคั่นระหว่างสองช่วงหยุด แล้วรวมได้ 4 วันขึ้นไป
 *   (เช่น วันหยุดวันพฤหัส + ลาศุกร์ = หยุด 4 วัน) — แนบกับหยุดยาวที่ติดกัน หรือขึ้น
 *   เป็นช่วงของตัวเองเมื่อไม่มีหยุดยาวจริง
 * - รอบที่ "ไปได้" = วันทริปวันแรกถึงวันกลับอยู่ในช่วงทั้งหมด รอบที่ต้องใช้วันลาด้วย
 *   ติดป้าย needs_leave รอบที่รถออกคืนก่อนวันทริปยังนับว่าไปได้ (ออกหลังเลิกงาน)
 *
 * วันที่ทั้งหมดเป็นวันไทย (Asia/Bangkok) และรอบมาจาก TripCalendarService ชุดเดียวกับ
 * ปฏิทินรอบ (ตัดรอบเหมาลำ/ทริปที่ปิดแล้วทิ้งเหมือนกัน)
 */
class LongWeekendService
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** ติดกันกี่วันถึงเรียกว่าหยุดยาว */
    public const MIN_DAYS = 3;

    /** ลาเพิ่มวันเดียวแล้วต้องได้อย่างน้อยกี่วันถึงคุ้มจะแนะนำ */
    public const BRIDGE_MIN_DAYS = 4;

    /** แสดงกี่ช่วง */
    public const LIMIT = 8;

    /** รอบต่อช่วงที่ส่งไปวาดบนการ์ด (ที่เหลือบอกเป็นจำนวน) */
    public const ROUNDS_PER_WEEKEND = 12;

    public function __construct(private TripCalendarService $calendar) {}

    /**
     * @return array<string, mixed>
     */
    public function upcoming(): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $until = $today->startOfMonth()->addMonths(TripCalendarService::MONTHS_AHEAD)->endOfMonth()->startOfDay();

        $holidays = PublicHoliday::active()
            ->whereDate('date', '>=', $today->subDays(10)->toDateString())
            ->whereDate('date', '<=', $until->addDays(10)->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (PublicHoliday $h) => $h->date->toDateString());

        $this->ensureSynced($today);

        $periods = $this->periods($today, $until, $holidays->keys()->all());

        $schedules = $periods === []
            ? collect()
            : $this->calendar->schedulesBetween(
                $today,
                CarbonImmutable::parse(max(array_column($periods, 'range_end')), self::TIMEZONE),
            );

        return [
            'weekends' => array_map(fn (array $p) => $this->present($p, $holidays, $schedules, $today), $periods),
            'has_holidays' => $holidays->isNotEmpty(),
            'generated_at' => CarbonImmutable::now(self::TIMEZONE)->toIso8601String(),
        ];
    }

    /**
     * ช่วงหยุดยาว (และช่วงลาเพิ่มวันเดียว) ที่ยังไม่จบ เรียงตามวันเริ่ม
     *
     * @param  array<int, string>  $holidayDates  Y-m-d
     * @return array<int, array<string, mixed>>
     */
    public function periods(CarbonImmutable $today, CarbonImmutable $until, array $holidayDates): array
    {
        $holidaySet = array_flip($holidayDates);
        $from = $today->subDays(9);
        $to = $until->addDays(9);

        // ช่วงวันหยุดต่อเนื่อง [start, end, has_holiday]
        $runs = [];
        $current = null;
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $isHoliday = isset($holidaySet[$key]);
            $off = $d->isWeekend() || $isHoliday;

            if ($off) {
                if ($current === null) {
                    $current = ['start' => $d, 'end' => $d, 'holiday' => $isHoliday];
                } else {
                    $current['end'] = $d;
                    $current['holiday'] = $current['holiday'] || $isHoliday;
                }
            } elseif ($current !== null) {
                $runs[] = $current;
                $current = null;
            }
        }
        if ($current !== null) {
            $runs[] = $current;
        }

        $length = fn (array $r) => (int) $r['start']->diffInDays($r['end']) + 1;

        // หยุดยาวจริง (ติดกัน ≥ 3 วัน)
        $periods = [];
        foreach ($runs as $i => $run) {
            if ($length($run) >= self::MIN_DAYS) {
                $periods[$i] = ['kind' => 'long', 'start' => $run['start'], 'end' => $run['end'], 'days' => $length($run), 'bridge' => null];
            }
        }

        // ลาเพิ่มวันเดียว: วันทำงานวันเดียวคั่นสองช่วงหยุด
        for ($i = 0; $i < count($runs) - 1; $i++) {
            $a = $runs[$i];
            $b = $runs[$i + 1];
            $gap = (int) $a['end']->diffInDays($b['start']) - 1;
            if ($gap !== 1 || ! ($a['holiday'] || $b['holiday'])) {
                continue;
            }

            $leave = $a['end']->addDay();
            $total = $length($a) + 1 + $length($b);
            if ($total < self::BRIDGE_MIN_DAYS || $leave->lt($today)) {
                continue;
            }

            $bridge = ['leave' => $leave, 'start' => $a['start'], 'end' => $b['end'], 'days' => $total];

            // แนบกับหยุดยาวที่ติดกัน (ช่วงที่ยาวกว่าก่อน) — ไม่มี = ขึ้นเป็นช่วงของตัวเอง
            $host = match (true) {
                isset($periods[$i]) && isset($periods[$i + 1]) => $periods[$i]['days'] >= $periods[$i + 1]['days'] ? $i : $i + 1,
                isset($periods[$i]) => $i,
                isset($periods[$i + 1]) => $i + 1,
                default => null,
            };

            if ($host === null) {
                $periods['b'.$i] = ['kind' => 'bridge', 'start' => $a['start'], 'end' => $b['end'], 'days' => $total, 'bridge' => $bridge];
            } elseif ($periods[$host]['bridge'] === null || $periods[$host]['bridge']['days'] < $total) {
                $periods[$host]['bridge'] = $bridge;
            }
        }

        $periods = array_values(array_filter(
            $periods,
            fn (array $p) => $p['end']->gte($today) && $p['start']->lte($until),
        ));
        usort($periods, fn (array $x, array $y) => strcmp($x['start']->toDateString(), $y['start']->toDateString()));

        return array_map(function (array $p) {
            $start = $p['bridge'] && $p['bridge']['start']->lt($p['start']) ? $p['bridge']['start'] : $p['start'];
            $end = $p['bridge'] && $p['bridge']['end']->gt($p['end']) ? $p['bridge']['end'] : $p['end'];
            $p['range_start'] = $start->toDateString();
            $p['range_end'] = $end->toDateString();

            return $p;
        }, array_slice($periods, 0, self::LIMIT));
    }

    /**
     * @param  Collection<string, Collection<int, PublicHoliday>>  $holidays
     * @param  Collection<int, TripSchedule>  $schedules
     * @return array<string, mixed>
     */
    private function present(array $period, Collection $holidays, Collection $schedules, CarbonImmutable $today): array
    {
        $rangeStart = CarbonImmutable::parse($period['range_start'], self::TIMEZONE);
        $rangeEnd = CarbonImmutable::parse($period['range_end'], self::TIMEZONE);
        $bridge = $period['bridge'];

        $names = [];
        for ($d = $rangeStart; $d->lte($rangeEnd); $d = $d->addDay()) {
            foreach ($holidays->get($d->toDateString(), collect()) as $holiday) {
                $label = $holiday->label();
                // สงกรานต์สามวันชื่อเดียวกัน — ขึ้นครั้งเดียวพอ
                if (! in_array($label, array_column($names, 'name'), true)) {
                    $names[] = ['date' => $d->toDateString(), 'name' => $label];
                }
            }
        }

        $fits = $schedules
            ->map(fn (TripSchedule $s) => [$s, $this->tripSpan($s)])
            ->filter(fn (array $row) => $row[1][0]->gte($rangeStart) && $row[1][1]->lte($rangeEnd))
            ->map(function (array $row) use ($period, $bridge, $today) {
                [$schedule, [$first, $last]] = $row;
                $needsLeave = $bridge !== null && (
                    $period['kind'] === 'bridge'
                        ? $first->lte($bridge['leave']) && $last->gte($bridge['leave'])
                        : $first->lt($period['start']) || $last->gt($period['end'])
                );

                return $this->calendar->round($schedule, $today) + ['needs_leave' => $needsLeave];
            })
            ->sortBy(fn (array $r) => [
                $r['is_full'] ? 1 : 0,
                $r['needs_leave'] ? 1 : 0,
                $r['departure_date'],
                $r['price'],
            ])
            ->values();

        $daysUntil = (int) $today->diffInDays($period['start'], false);

        return [
            'key' => $period['start']->toDateString(),
            'kind' => $period['kind'],
            'start' => $period['start']->toDateString(),
            'end' => $period['end']->toDateString(),
            'days' => $period['days'],
            'title' => $period['kind'] === 'bridge'
                ? __('ลาเพิ่ม 1 วัน หยุดยาว :days วัน', ['days' => $period['days']])
                : __('หยุดยาว :days วัน', ['days' => $period['days']]),
            'range_label' => $this->rangeLabel($period['start'], $period['end']),
            'holidays' => $names,
            'bridge' => $bridge === null ? null : [
                'leave_date' => $bridge['leave']->toDateString(),
                'leave_label' => $this->dayLabel($bridge['leave']),
                'start' => $bridge['start']->toDateString(),
                'end' => $bridge['end']->toDateString(),
                'days' => $bridge['days'],
                'range_label' => $this->rangeLabel($bridge['start'], $bridge['end']),
                'text' => __('ลาเพิ่ม :day วันเดียว ได้หยุด :days วัน (:range)', [
                    'day' => $this->dayLabel($bridge['leave']),
                    'days' => $bridge['days'],
                    'range' => $this->rangeLabel($bridge['start'], $bridge['end']),
                ]),
            ],
            'is_ongoing' => $daysUntil <= 0,
            'days_until' => max($daysUntil, 0),
            'days_until_label' => match (true) {
                $daysUntil <= 0 => __('กำลังหยุดอยู่'),
                $daysUntil === 1 => __('เริ่มพรุ่งนี้'),
                default => __('อีก :days วัน', ['days' => $daysUntil]),
            },
            'month' => $period['start']->format('Y-m'),
            'round_count' => $fits->count(),
            'open_count' => $fits->where('is_full', false)->count(),
            'rounds' => $fits->take(self::ROUNDS_PER_WEEKEND)->all(),
        ];
    }

    /**
     * วันแรกถึงวันสุดท้ายของทริป (วันไทย) — ไม่มีวันกลับ = นับจากจำนวนวันของทริป
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function tripSpan(TripSchedule $schedule): array
    {
        $first = CarbonImmutable::parse($schedule->departure_date->toDateString(), self::TIMEZONE);
        $last = $schedule->return_date
            ? CarbonImmutable::parse($schedule->return_date->toDateString(), self::TIMEZONE)
            : $first->addDays(max((int) ($schedule->trip?->duration_days ?? 1), 1) - 1);

        return [$first, $last->lt($first) ? $first : $last];
    }

    /** "ศ. 4 ธ.ค." / "Fri 4 Dec" */
    private function dayLabel(CarbonImmutable $date): string
    {
        return AppLocale::isEnglish()
            ? $date->locale('en')->isoFormat('ddd D MMM')
            : $date->locale('th')->isoFormat('dd D MMM');
    }

    /** "ส. 5 – จ. 7 ธ.ค. 2569" / "Sat 5 – Mon 7 Dec 2026" */
    private function rangeLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $english = AppLocale::isEnglish();
        $locale = $english ? 'en' : 'th';
        $weekday = $english ? 'ddd' : 'dd';
        $year = fn (CarbonImmutable $d) => $english ? $d->year : $d->year + 543;

        $tail = $end->locale($locale)->isoFormat($weekday.' D MMM').' '.$year($end);
        $head = $start->isSameMonth($end)
            ? $start->locale($locale)->isoFormat($weekday.' D')
            : $start->locale($locale)->isoFormat($weekday.' D MMM').($start->year !== $end->year ? ' '.$year($start) : '');

        return $head.' – '.$tail;
    }

    /**
     * ยังไม่มีวันหยุดข้างหน้าเลย (เพิ่ง deploy หรือดึงล่าสุดล้มเหลว) — สั่งดึงในคิว
     * คำขอนี้ไม่รอ ไม่เรียกปฏิทินภายนอกใน request เด็ดขาด
     */
    private function ensureSynced(CarbonImmutable $today): void
    {
        $hasUpcoming = PublicHoliday::whereDate('date', '>=', $today->toDateString())->exists();
        if (! $hasUpcoming && Cache::add('holidays:auto-sync-queued', true, now()->addHours(6))) {
            SyncPublicHolidaysJob::dispatch();
        }
    }
}
