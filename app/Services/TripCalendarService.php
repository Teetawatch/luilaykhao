<?php

namespace App\Services;

use App\Models\TripSchedule;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "เดือนนี้มีทริปอะไร" — คำถามที่ลูกค้าถามเข้ามาทุกวัน ตอบเป็นลิงก์เดียวได้
 *
 * ต่างจาก PriceSheetService ที่ตอบคำถามของทีมงาน ("ราคาทุกแบบของช่วงนี้ เอาไป
 * ทำรูปโปรโมท") หน้านี้ตอบคำถามของลูกค้า ("เดือนหน้าไปไหนได้บ้าง จองยังไง") จึง
 * เรียงตามวันไม่ใช่ตามทริป ตัดราคาย่อยทุกแบบทิ้ง เหลือราคาเริ่มต้นกับที่ว่าง
 * และทุกแถวมีปลายทางที่กดต่อได้
 *
 * รอบเหมาลำและรอบที่ยกเลิกไม่เคยอยู่ในหน้านี้ ส่วนรอบที่เต็มแล้วยังอยู่ —
 * "เต็มแล้ว" เป็นคำตอบของคำถามที่ลูกค้าถาม และยังพาไปจองคิวรอได้
 */
class TripCalendarService
{
    /** ปฏิทินเปิดดูล่วงหน้าได้ถึงกี่เดือน — ไกลกว่านี้รอบยังไม่ถูกวางขายจริง */
    public const MONTHS_AHEAD = 6;

    /**
     * "ไฟไหม้" คือใกล้ออกเดินทางแค่ไหน
     *
     * สิบวันคือช่วงที่การตัดสินใจของลูกค้ายังเปลี่ยนผลได้จริง — ไกลกว่านี้ยังไม่
     * เร่งด่วนพอจะเรียกว่าไฟไหม้ ใกล้กว่านี้ก็สายเกินกว่าจะลาหยุดงานทัน
     */
    public const HOT_DAYS = 10;

    /**
     * รอบที่ตั้ง flash sale ไว้ถือว่าไฟไหม้ด้วย แม้ยังไม่ใกล้ออกเดินทาง —
     * แต่ไม่ไกลเกินสองเดือน ไม่งั้นโซน "รีบเลย" จะเต็มไปด้วยรอบของปีหน้า
     */
    public const HOT_FLASH_SALE_DAYS = 60;

    /** จำนวนรอบสูงสุดในโซนไฟไหม้ — เป็นรายการที่ต้องกวาดตาจบ ไม่ใช่รายการที่ต้องอ่าน */
    public const HOT_LIMIT = 8;

    /** เหลือเท่านี้หรือน้อยกว่า = "ใกล้เต็ม" ตัวเลขเดียวกับที่หน้าอื่นใช้เตือน */
    public const LOW_SEATS = 3;

    public const TIMEZONE = 'Asia/Bangkok';

    /**
     * ปฏิทินของเดือนหนึ่ง พร้อมโซนไฟไหม้ (ซึ่งไม่ขึ้นกับเดือนที่เลือกดู)
     *
     * @return array<string, mixed>
     */
    public function forMonth(?string $month = null): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $target = $this->resolveMonth($month, $today);

        // เดือนปัจจุบันเริ่มนับจาก "วันนี้" ไม่ใช่วันที่ 1 — รอบที่ออกไปแล้วเมื่อ
        // สัปดาห์ก่อนไม่ใช่คำตอบของคำถาม "เดือนนี้มีทริปอะไร"
        $from = $target->isSameMonth($today) ? $today : $target->startOfMonth();
        $to = $target->endOfMonth();

        $days = $this->days($this->schedulesBetween($from, $to));

        return [
            'month' => $target->format('Y-m'),
            'month_label' => ThaiDate::monthYear($target),
            'is_current_month' => $target->isSameMonth($today),
            'months' => $this->monthOptions($today, $target),
            'days' => $days,
            'summary' => $this->summary($days),
            'hot' => $this->hot($today),
            'hot_days' => self::HOT_DAYS,
            'generated_at' => CarbonImmutable::now(self::TIMEZONE)->toIso8601String(),
        ];
    }

    /**
     * เดือนที่เลือกดูได้ — เดือนนี้ถึงอีก MONTHS_AHEAD เดือนข้างหน้า
     *
     * เดือนที่ผ่านไปแล้วไม่อยู่ในรายการ แต่ถ้ามีคนเปิดลิงก์เก่าของเดือนนั้นก็ยัง
     * เปิดได้ (ลิงก์ที่เคยส่งไปในไลน์ไม่ควรกลายเป็นหน้าเสีย)
     *
     * @return array<int, array<string, mixed>>
     */
    private function monthOptions(CarbonImmutable $today, CarbonImmutable $selected): array
    {
        $options = collect(range(0, self::MONTHS_AHEAD))
            ->map(fn (int $offset) => $today->startOfMonth()->addMonths($offset));

        // เดือนที่กำลังดูอยู่ต้องมีปุ่มของตัวเองเสมอ แม้จะเป็นเดือนที่ผ่านไปแล้ว
        if (! $options->contains(fn (CarbonImmutable $m) => $m->isSameMonth($selected))) {
            $options = $options->push($selected->startOfMonth())->sort();
        }

        return $options
            ->map(fn (CarbonImmutable $month) => [
                'value' => $month->format('Y-m'),
                'label' => ThaiDate::monthYear($month),
                'short_label' => ThaiDate::monthName($month->month),
                'is_current' => $month->isSameMonth($today),
                'is_selected' => $month->isSameMonth($selected),
            ])
            ->values()
            ->all();
    }

    /**
     * รอบที่ขายอยู่ในช่วงวันที่กำหนด
     *
     * @return Collection<int, TripSchedule>
     */
    private function schedulesBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return TripSchedule::query()
            ->with(['trip:id,title,slug,status,location,region,duration_days,price_per_person,cover_image,destination_type,type,difficulty'])
            ->whereNotNull('departure_date')
            ->whereDate('departure_date', '>=', $from->toDateString())
            ->whereDate('departure_date', '<=', $to->toDateString())
            ->whereIn('status', ['open', 'full'])
            ->where('is_charter', false)
            ->orderBy('departure_date')
            ->orderBy('id')
            ->get()
            // ทริปที่ถูกปิดไปแล้วไม่ควรมีรอบโผล่ในปฏิทินสาธารณะ
            ->filter(fn (TripSchedule $s) => $s->trip !== null && $s->trip->status === 'active')
            ->values();
    }

    /**
     * จัดรอบเป็นวัน ๆ — หน้าเว็บวาดตามนี้ได้เลยโดยไม่ต้องจัดกลุ่มเอง
     *
     * @param  Collection<int, TripSchedule>  $schedules
     * @return array<int, array<string, mixed>>
     */
    private function days(Collection $schedules): array
    {
        return $schedules
            ->groupBy(fn (TripSchedule $s) => $s->departure_date->toDateString())
            ->map(fn (Collection $rows, string $date) => [
                'date' => $date,
                'date_label' => ThaiDate::short(CarbonImmutable::parse($date)),
                'weekday' => $this->weekday(CarbonImmutable::parse($date)),
                'rounds' => $rows->map(fn (TripSchedule $s) => $this->round($s))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * โซนไฟไหม้ — รอบที่ใกล้ออกเดินทางและยังมีที่ว่าง บวกรอบที่ทีมงานตั้งลดราคาไว้
     *
     * รอบที่เต็มแล้วไม่อยู่ในโซนนี้ ต่างจากรายการรายเดือนที่ยังแสดง "เต็มแล้ว" อยู่ —
     * โซนนี้มีไว้ให้กดจอง ไม่ใช่ให้ดูว่ามีอะไรบ้าง
     *
     * @return array<int, array<string, mixed>>
     */
    private function hot(CarbonImmutable $today): array
    {
        $schedules = $this->schedulesBetween($today, $today->addDays(self::HOT_FLASH_SALE_DAYS));
        $soonest = $today->addDays(self::HOT_DAYS)->toDateString();

        return $schedules
            ->filter(fn (TripSchedule $s) => $s->status === 'open' && $s->available_seats > 0)
            ->filter(fn (TripSchedule $s) => $s->flashSaleActive()
                || $s->departure_date->toDateString() <= $soonest)
            // ออกก่อนขึ้นก่อน — ความเร่งด่วนคือเหตุผลเดียวที่โซนนี้มีอยู่
            ->sortBy(fn (TripSchedule $s) => $s->departure_date->toDateString())
            ->take(self::HOT_LIMIT)
            ->map(fn (TripSchedule $s) => $this->round($s, $today))
            ->values()
            ->all();
    }

    /**
     * หนึ่งรอบในรูปแบบที่หน้าเว็บวาดได้เลย
     *
     * @return array<string, mixed>
     */
    private function round(TripSchedule $schedule, ?CarbonImmutable $today = null): array
    {
        $trip = $schedule->trip;
        $price = round((float) $schedule->effective_price, 2);
        $original = round((float) $schedule->original_price, 2);
        $seats = $schedule->available_seats;
        $isFull = $schedule->status === 'full' || $seats <= 0;

        return [
            'id' => $schedule->id,
            'trip' => [
                'id' => $trip->id,
                'title' => $trip->title,
                'slug' => $trip->slug,
                'location' => $trip->location,
                'duration_days' => $trip->duration_days,
                'cover_image' => $trip->cover_image,
                'type' => $trip->type,
                'difficulty' => $trip->difficulty,
                'is_international' => $trip->destination_type === 'international',
            ],
            'departure_date' => $schedule->departure_date->toDateString(),
            'return_date' => $schedule->return_date?->toDateString(),
            'date_label' => ThaiDate::range($schedule->departure_date, $schedule->return_date),
            'price' => $price,
            'original_price' => $original > $price ? $original : null,
            'on_flash_sale' => $schedule->flashSaleActive(),
            'flash_sale_ends_at' => $schedule->flashSaleActive()
                ? $schedule->flash_sale_ends_at?->toIso8601String()
                : null,
            'join_trip_price' => $schedule->join_trip_enabled
                ? round((float) $schedule->effective_join_trip_price, 2)
                : null,
            'seats_left' => $seats,
            'is_full' => $isFull,
            'is_low' => ! $isFull && $seats <= self::LOW_SEATS,
            'status_label' => $this->statusLabel($schedule, $seats, $isFull),
            'days_left' => $today ? $this->daysLeft($today, $schedule) : null,
            'days_left_label' => $today ? $this->daysLeftLabel($today, $schedule) : null,
            // ไปหน้าทริปพร้อมเลือกรอบนี้ไว้ให้ ไม่ตัดหน้ารายละเอียดทิ้ง —
            // คนที่มาจากลิงก์ในไลน์ส่วนใหญ่ยังไม่เคยอ่านว่าทริปนี้คืออะไร
            'url' => '/trips/'.$trip->slug.'?schedule='.$schedule->id,
        ];
    }

    private function statusLabel(TripSchedule $schedule, int $seats, bool $isFull): string
    {
        if ($isFull) {
            return 'เต็มแล้ว · จองคิวรอได้';
        }

        if ($seats <= self::LOW_SEATS) {
            return 'เหลือ '.$seats.' ที่';
        }

        return 'ว่าง '.$seats.' ที่';
    }

    private function daysLeft(CarbonImmutable $today, TripSchedule $schedule): int
    {
        return (int) $today->diffInDays(
            CarbonImmutable::parse($schedule->departure_date->toDateString(), self::TIMEZONE)->startOfDay(),
            false,
        );
    }

    private function daysLeftLabel(CarbonImmutable $today, TripSchedule $schedule): string
    {
        return match ($days = $this->daysLeft($today, $schedule)) {
            0 => 'วันนี้',
            1 => 'พรุ่งนี้',
            default => 'อีก '.$days.' วัน',
        };
    }

    private function weekday(CarbonImmutable $date): string
    {
        return $date->locale('th')->isoFormat('dd');
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function summary(array $days): array
    {
        $rounds = collect($days)->flatMap(fn (array $day) => $day['rounds']);
        $prices = $rounds->where('is_full', false)->pluck('price')->filter()->sort()->values();

        return [
            'round_count' => $rounds->count(),
            'trip_count' => $rounds->pluck('trip.id')->unique()->count(),
            'open_count' => $rounds->where('is_full', false)->count(),
            'seats_left' => (int) $rounds->sum('seats_left'),
            'min_price' => $prices->first(),
            'max_price' => $prices->last(),
        ];
    }

    /**
     * เดือนที่ขอมาในรูป Y-m — ค่าที่อ่านไม่ออกถือว่าเป็นเดือนนี้ หน้าเว็บสาธารณะ
     * ไม่ควรตอบด้วย error เพียงเพราะมีคนพิมพ์ URL เพี้ยน
     */
    private function resolveMonth(?string $month, CarbonImmutable $today): CarbonImmutable
    {
        // เดือนต้องอยู่ในช่วง 01–12 จริง ๆ — '2026-13' ถ้าปล่อยผ่านจะถูกแปลงเป็น
        // มกราคมปีหน้าเงียบ ๆ แล้วลูกค้าได้ปฏิทินคนละเดือนกับที่ลิงก์บอก
        if (! $month || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $today->startOfMonth();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', self::TIMEZONE);
        } catch (\Throwable $e) {
            return $today->startOfMonth();
        }

        if (! $parsed) {
            return $today->startOfMonth();
        }

        $parsed = $parsed->startOfMonth();

        // ไกลเกินกว่าที่รอบจะถูกวางขายจริง = คนเดาลิงก์เอง ไม่ใช่ลิงก์ที่เราส่งไป
        $furthest = $today->startOfMonth()->addMonths(self::MONTHS_AHEAD);

        return $parsed->gt($furthest) ? $furthest : $parsed;
    }
}
