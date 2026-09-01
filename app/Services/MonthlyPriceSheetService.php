<?php

namespace App\Services;

use App\Models\SchedulePickupPoint;
use App\Models\ScheduleVehicleOption;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "ราคาทริปรายเดือน" — รวมทุกรอบเดินทางของเดือนหนึ่งพร้อมราคาทุกแบบไว้ที่เดียว
 * เพื่อให้ทีมงานก๊อปไปทำรูปโปรโมทได้ในครั้งเดียว
 *
 * เหตุผลที่ต้องมี: ข้อมูลนี้มีอยู่แล้ว แต่กระจายอยู่สามที่ — ราคาต่อรอบอยู่ที่
 * price_override (หรือราคาทริปเมื่อไม่ได้ตั้งทับ), ราคา "เจอหน้างาน / ขึ้นรถจุดอื่น"
 * อยู่ที่ schedule_pickup_points.price ซึ่งเป็น "ราคาเต็มของจุดนั้น" ไม่ใช่ส่วนต่าง
 * (ดู SchedulePickupPoint) ส่วนบัส/ตู้คนละราคาอยู่ที่ schedule_vehicle_options
 * ซึ่งเก็บเป็น "ส่วนต่างต่อคน" ต้องบวกกับราคารอบเอง การไล่เปิดทีละรอบเพื่อจดราคา
 * จึงพลาดง่ายมาก
 *
 * รอบเหมาลำ (is_charter) และรอบที่ยกเลิกไม่เคยอยู่ในสื่อโปรโมท จึงตัดออกตั้งแต่ต้น
 * ที่เหลือส่งไปให้ฝั่งหน้าเว็บกรองเอง (เปิดรับจอง / เต็ม / ปิด) จะได้สลับดูได้ทันที
 * โดยไม่ต้องยิงใหม่
 */
class MonthlyPriceSheetService
{
    /**
     * @return array<string, mixed>
     */
    public function forMonth(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $schedules = TripSchedule::query()
            // price_per_person คือราคาที่ effective_price ตกกลับไปใช้เมื่อรอบไม่ได้ตั้งราคาทับ
            ->with(['trip:id,title,slug,price_per_person,location,region,duration_days,destination_type'])
            ->whereNotNull('departure_date')
            ->whereDate('departure_date', '>=', $start->toDateString())
            ->whereDate('departure_date', '<=', $end->toDateString())
            ->where('status', '!=', 'cancelled')
            ->where('is_charter', false)
            ->orderBy('departure_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (TripSchedule $s) => $s->trip !== null)
            ->values();

        $pickups = $this->pickupPointsFor($schedules);
        $vehicleOptions = $this->vehicleOptionsFor($schedules);

        $trips = $schedules
            ->groupBy('trip_id')
            ->map(fn (Collection $rows) => $this->tripRow($rows->first()->trip, $rows, $pickups, $vehicleOptions))
            // ทริปที่ออกเดินทางก่อนขึ้นก่อน แล้วค่อยเรียงชื่อกันเสมอเมื่อวันชนกัน
            ->sortBy(fn (array $trip) => [$trip['first_departure'], $trip['title']])
            ->values()
            ->all();

        return [
            'month' => $start->format('Y-m'),
            'month_label' => ThaiDate::monthYear($start),
            'month_name' => ThaiDate::monthName($start->month),
            'year_be' => $start->year + 543,
            'trips' => $trips,
            'summary' => $this->summary($trips),
        ];
    }

    /**
     * จุดรับของทุกรอบในชุด จัดกลุ่มตาม schedule_id
     *
     * @param  Collection<int, TripSchedule>  $schedules
     * @return Collection<int, Collection<int, SchedulePickupPoint>>
     */
    private function pickupPointsFor(Collection $schedules): Collection
    {
        if ($schedules->isEmpty()) {
            return collect();
        }

        return SchedulePickupPoint::query()
            ->whereIn('schedule_id', $schedules->pluck('id'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('schedule_id');
    }

    /**
     * @param  Collection<int, TripSchedule>  $schedules
     * @return Collection<int, Collection<int, ScheduleVehicleOption>>
     */
    private function vehicleOptionsFor(Collection $schedules): Collection
    {
        if ($schedules->isEmpty()) {
            return collect();
        }

        return ScheduleVehicleOption::query()
            ->whereIn('schedule_id', $schedules->pluck('id'))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('schedule_id');
    }

    /**
     * @param  Collection<int, TripSchedule>  $rows
     * @return array<string, mixed>
     */
    private function tripRow(Trip $trip, Collection $rows, Collection $pickups, Collection $vehicleOptions): array
    {
        $schedules = $rows
            ->map(fn (TripSchedule $s) => $this->scheduleRow($s, $pickups, $vehicleOptions))
            ->values()
            ->all();

        // ราคาที่ "ประกาศได้" ของทริปนี้ในเดือนนี้ — ราคารอบทุกแบบรวมกัน ไม่ใช่แค่รอบแรก
        $prices = collect($schedules)->pluck('price')->filter()->unique()->sort()->values();

        return [
            'trip_id' => $trip->id,
            'title' => $trip->title,
            'slug' => $trip->slug,
            'location' => $trip->location,
            'region' => $trip->region,
            'duration_days' => $trip->duration_days,
            'is_international' => $trip->destination_type === 'international',
            'base_price' => $this->money($trip->price_per_person),
            'min_price' => $prices->first(),
            'max_price' => $prices->last(),
            'first_departure' => $rows->min('departure_date')?->toDateString(),
            'schedules' => $schedules,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleRow(TripSchedule $schedule, Collection $pickups, Collection $vehicleOptions): array
    {
        $price = $this->money($schedule->effective_price);
        $original = $this->money($schedule->original_price);
        $onFlashSale = $schedule->flashSaleActive();

        $points = ($pickups[$schedule->id] ?? collect())
            ->map(function (SchedulePickupPoint $p) use ($price) {
                // ราคาจุดรับคือ "ราคาเต็มของจุดนั้น" ไม่ใช่ส่วนต่าง (ดู SchedulePickupPoint)
                // จุดที่ปล่อยเป็น 0 ไว้คือจุดที่คิดราคารอบตามปกติ
                $pointPrice = (float) $p->price > 0 ? $this->money($p->price) : $price;

                return [
                    'id' => $p->id,
                    'label' => $p->pickup_location,
                    'region_label' => $p->region_label,
                    'pickup_time' => $p->pickup_time,
                    'price' => $pointPrice,
                    // ราคาเท่ากับราคารอบ = ไม่มีอะไรใหม่ให้เขียนลงรูป
                    'is_default_price' => abs($pointPrice - $price) < 0.01,
                ];
            })
            ->values()
            ->all();

        $rides = ($vehicleOptions[$schedule->id] ?? collect())
            // ส่วนต่างต่อคน ไม่ใช่ราคาเต็ม — บวกกับราคารอบก่อนแสดงเสมอ
            ->map(fn (ScheduleVehicleOption $o) => [
                'id' => $o->id,
                'label' => $o->label,
                'transport_type' => $o->transport_type,
                'price' => $this->money($price + (float) $o->price_adjustment),
                'price_adjustment' => $this->money($o->price_adjustment),
            ])
            ->values()
            ->all();

        return [
            'id' => $schedule->id,
            'departure_date' => $schedule->departure_date?->toDateString(),
            'return_date' => $schedule->return_date?->toDateString(),
            'date_label' => $this->dateLabel($schedule),
            'date_full' => ThaiDate::range($schedule->departure_date, $schedule->return_date),
            'status' => $schedule->status,
            'transport_type' => $schedule->transport_type,
            'price' => $price,
            'original_price' => $original,
            'on_flash_sale' => $onFlashSale,
            'join_trip_enabled' => (bool) $schedule->join_trip_enabled,
            'join_trip_price' => $schedule->join_trip_enabled ? $this->money($schedule->join_trip_price) : null,
            'total_seats' => (int) $schedule->total_seats,
            'booked_seats' => (int) $schedule->booked_seats,
            'available_seats' => $schedule->available_seats,
            'pickup_points' => $points,
            'vehicle_options' => $rides,
        ];
    }

    /**
     * วันเดินทางแบบสั้นสำหรับใส่ในรูป — "5 – 6 ก.ย." ในเดือนเดียวกัน,
     * เขียนเดือนทั้งสองฝั่งเมื่อรอบคาบเกี่ยวไปเดือนถัดไป
     */
    private function dateLabel(TripSchedule $schedule): string
    {
        $from = $schedule->departure_date;
        $to = $schedule->return_date;

        if ($from === null) {
            return '-';
        }

        $short = fn ($date) => $date->locale('th')->isoFormat('D MMM');

        if ($to === null || $to->isSameDay($from)) {
            return $short($from);
        }

        if ($to->year === $from->year && $to->month === $from->month) {
            return $from->locale('th')->isoFormat('D').' – '.$short($to);
        }

        return $short($from).' – '.$short($to);
    }

    /**
     * @param  array<int, array<string, mixed>>  $trips
     * @return array<string, mixed>
     */
    private function summary(array $trips): array
    {
        $schedules = collect($trips)->flatMap(fn (array $t) => $t['schedules']);
        $prices = $schedules->pluck('price')->filter()->sort()->values();

        return [
            'trip_count' => count($trips),
            'schedule_count' => $schedules->count(),
            'open_schedule_count' => $schedules->where('status', 'open')->count(),
            'available_seats' => (int) $schedules->sum('available_seats'),
            'min_price' => $prices->first(),
            'max_price' => $prices->last(),
        ];
    }

    /** ราคาส่งออกเป็นตัวเลข — ตัด .00 ทิ้งเพื่อให้ฝั่งหน้าเว็บจัดรูปแบบเองได้ */
    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
