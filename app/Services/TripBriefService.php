<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Support\SiteSettings;
use App\Support\ThaiDate;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * "ใบเดินทาง" — แหล่งเดียวของทุกอย่างที่ลูกค้าต้องรู้ก่อนออกเดินทาง
 *
 * ทั้งหน้าเว็บ /t/{token} และอีเมลฉบับเต็มวาดจาก payload ก้อนเดียวกันนี้ ที่ทำ
 * แบบนี้เพราะเนื้อหาชุดเดียวกันต้องไปโผล่สองที่ และสองที่นั้นต้องไม่มีวันพูดไม่ตรงกัน
 *
 * ทำไมต้องมีหน้าเว็บด้วย ไม่ใช่อีเมลอย่างเดียว:
 *   1. ลูกค้าที่ทีมงานเปิดใบจองแทนให้ ส่วนมากไม่มีอีเมลจริงในระบบ — ส่งลิงก์ทาง
 *      SMS ได้ แต่ส่งเนื้อหาเต็ม ๆ ทาง SMS ไม่ได้
 *   2. อีเมลที่ส่งไปแล้วแก้ไม่ได้ พอเปลี่ยนคนขับหรือขยับกำหนดการ ลูกค้าจะถือ
 *      ข้อมูลผิดไปหน้างาน หน้าเว็บอัปเดตตัวเองทุกครั้งที่เปิด
 *
 * เรื่องความเป็นส่วนตัว: ใบเดินทางถูกส่งต่อให้คนที่บ้านเป็นปกติ จึงใส่เฉพาะ
 * ข้อมูลของรอบและของผู้จอง — ไม่มีชื่อหรือเบอร์ของผู้โดยสารรายอื่นในรอบ
 * ส่วนเบอร์ทีมงาน/คนขับใส่ได้เพราะเป็นเบอร์สำหรับงาน และกำกับไว้ว่าใช้ช่วงทริป
 */
class TripBriefService
{
    /**
     * เปิดใบเดินทางได้ถึงเมื่อไหร่หลังจบทริป — ลิงก์ที่ค้างอยู่ในกล่องข้อความไม่ควร
     * เปิดเจอเบอร์ทีมงานไปตลอดกาล แต่ก็ต้องเผื่อให้ลูกค้าเปิดย้อนดูวันที่กลับถึงบ้าน
     */
    public const EXPIRES_DAYS_AFTER = 3;

    public const TIMEZONE = 'Asia/Bangkok';

    /** สถานะการจองที่ยังได้ใบเดินทาง */
    public const ELIGIBLE_STATUSES = ['confirmed', 'pending'];

    /**
     * payload ของใบจองที่ประกอบไปแล้วในรอบนี้
     *
     * งานส่งใบเดินทางเรียก payload() ซ้ำหลายรอบต่อใบจอง (เช็คว่าพร้อมไหม →
     * คำนวณลายนิ้วมือ → เรนเดอร์อีเมล) ถ้าประกอบใหม่ทุกครั้งก็เท่ากับไปถามพยากรณ์
     * อากาศซ้ำ ๆ ทั้งที่เป็นข้อมูลชุดเดิม อายุของแคชคืออายุของอ็อบเจกต์นี้ ซึ่งสั้น
     * แค่การทำงานหนึ่งรอบ จึงไม่มีทางค้างข้ามการแก้ไขของแอดมิน
     *
     * @var array<int, array<string, mixed>>
     */
    private array $cache = [];

    public function __construct(private WeatherService $weather) {}

    /**
     * ใบเดินทางจากโทเคนสาธารณะ — null เมื่อหาไม่เจอ ถูกยกเลิก หรือหมดอายุแล้ว
     */
    public function forToken(string $token): ?array
    {
        $booking = Booking::where('brief_token', $token)
            ->with($this->relations())
            ->first();

        if (! $booking || ! $this->isViewable($booking)) {
            return null;
        }

        return $this->payload($booking);
    }

    /**
     * ใบเดินทางยังเปิดดูได้อยู่ไหม
     */
    public function isViewable(Booking $booking): bool
    {
        if (! in_array($booking->status, self::ELIGIBLE_STATUSES, true)) {
            return false;
        }

        $schedule = $booking->schedule;

        if (! $schedule || $schedule->status === 'cancelled') {
            return false;
        }

        $endsOn = ($schedule->return_date ?: $schedule->departure_date)?->copy()->startOfDay();

        if ($endsOn && $endsOn->addDays(self::EXPIRES_DAYS_AFTER)->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * "ยังทันส่งไหม" — ต่างจาก isViewable() ที่ตอบว่า "ยังเปิดดูได้ไหม"
     *
     * ใบเดินทางเปิดดูย้อนหลังได้อีกสองสามวันหลังจบทริป (ลูกค้ากดลิงก์เก่าในกล่อง
     * ข้อความได้โดยไม่เจอหน้า error) แต่การ *ส่ง* ใบเดินทางหลังรถออกไปแล้วคือ
     * การส่งคำแนะนำสำหรับเหตุการณ์ที่จบไปแล้ว — รบกวนเปล่า ๆ และทำให้ลูกค้า
     * สงสัยว่าตัวเองพลาดอะไรไปหรือเปล่า
     *
     * รอบที่ไม่เคยตั้งเวลาออกรถ ตัดสินด้วย "วัน" ไม่ใช่ชั่วโมง — เที่ยงคืนที่
     * effectiveDepartsAt() เติมให้ไม่ใช่เวลาจริงที่ใครสัญญาไว้
     */
    public function isSendable(Booking $booking): bool
    {
        if (! $this->isViewable($booking)) {
            return false;
        }

        $schedule = $booking->schedule;

        if ($schedule?->departs_at) {
            // departs_at เก็บเวลาไทยไว้ในคอลัมน์ชนิด UTC — เทียบ "ตัวเลขกับตัวเลข"
            // ของเวลาไทยเท่านั้น อย่าเอา Carbon สองตัวคนละโซนมา gt() กันตรง ๆ
            return $schedule->departs_at->format('Y-m-d H:i:s')
                > Carbon::now(self::TIMEZONE)->format('Y-m-d H:i:s');
        }

        $days = $this->daysLeft($schedule);

        return $days === null || $days >= 0;
    }

    /**
     * ความสัมพันธ์ทั้งหมดที่ payload ต้องใช้ — โหลดทีเดียวเพราะงานที่เรียกมัน
     * ส่วนใหญ่วนทีละใบจองทั้งรอบ
     *
     * @return array<int, string>
     */
    public function relations(): array
    {
        return [
            'user',
            'passengers.pickupPoint',
            'pickupPoint',
            'seats',
            'schedule.trip',
            'schedule.vehicle',
            'schedule.itineraryItems',
            'schedule.activeStaff',
            'installmentPayments',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Booking $booking): array
    {
        if (isset($this->cache[$booking->id])) {
            return $this->cache[$booking->id];
        }

        $booking->loadMissing($this->relations());

        $schedule = $booking->schedule;
        $trip = $schedule?->trip;

        return $this->cache[$booking->id] = [
            'booking' => $this->bookingBlock($booking),
            'trip' => [
                'title' => $trip?->title ?? 'ทริปของคุณ',
                'location' => $trip?->location,
                'duration_days' => $trip?->duration_days,
                'cover_image' => $trip?->cover_image,
                'is_international' => (bool) $trip?->isInternational(),
                'bring' => $trip?->checkinBringNote(),
                'preparations' => array_values(array_filter((array) ($trip?->preparations ?? []))),
                'must_know' => array_values(array_filter((array) ($trip?->must_know ?? []))),
            ],
            'when' => $this->whenBlock($schedule),
            'pickup' => $this->pickupBlock($booking, $schedule),
            'meetup' => $this->meetupBlock($schedule),
            'vehicle' => $this->vehicleBlock($schedule),
            'crew' => $this->crewBlock($schedule),
            'itinerary' => $this->itineraryBlock($schedule),
            'payment' => $this->paymentBlock($booking),
            'weather' => $this->weatherBlock($schedule),
            'links' => [
                'brief' => $booking->briefUrl(),
                'track' => $booking->shareUrl(),
                'pay' => $booking->payUrl(),
            ],
            'support' => [
                'phone' => SiteSettings::supportPhone() ?: config('company.phone'),
                'line_url' => SiteSettings::supportLineUrl(),
                'line_id' => SiteSettings::supportLine(),
                'hours' => SiteSettings::supportHours(),
            ],
        ];
    }

    /**
     * ลายนิ้วมือของเนื้อหาที่ "ถ้าเปลี่ยนแล้วลูกค้าต้องรู้"
     *
     * ตั้งใจไม่รวมสภาพอากาศและยอดเงิน — สองอย่างนั้นขยับเองได้ทุกวันและมีช่องทาง
     * เตือนของตัวเองอยู่แล้ว ถ้าเอามารวมด้วย ลูกค้าจะโดนอีเมล "อัปเดต" ทุกวัน
     */
    public function digest(Booking $booking): string
    {
        $payload = $this->payload($booking);

        return sha1(json_encode([
            $payload['when'],
            $payload['pickup'],
            $payload['meetup'],
            $payload['vehicle'],
            $payload['crew'],
            $payload['itinerary'],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * ข้อมูลของรอบพร้อมพอที่จะส่งใบเดินทางแล้วหรือยัง
     *
     * ใบเดินทางที่ไม่มีทั้งเบอร์ทีมงานและจุดขึ้นรถ แย่กว่าไม่ส่งเลย เพราะลูกค้าจะ
     * สรุปว่า "ทีมงานยังไม่พร้อม" ทั้งที่แค่แอดมินยังไม่ได้กรอก — รอไปส่งวันถัดไป
     * ดีกว่า ยกเว้นพรุ่งนี้เดินทางแล้ว ตอนนั้นข้อมูลเท่าที่มีก็ยังดีกว่าเงียบ
     */
    public function isReady(Booking $booking): bool
    {
        $payload = $this->payload($booking);

        return ! empty($payload['crew'])
            || ! empty($payload['vehicle'])
            || ! empty($payload['pickup']['points'])
            || ! empty($payload['meetup']);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingBlock(Booking $booking): array
    {
        return [
            'ref' => $booking->booking_ref,
            'status' => $booking->status,
            'customer_name' => $booking->user?->name ?: $booking->passengers->first()?->name,
            'passenger_count' => max(1, $booking->passengers->count()),
            'passenger_names' => $booking->passengers
                ->pluck('name')
                ->filter()
                ->values()
                ->all(),
            'is_join_trip' => (bool) $booking->is_join_trip,
            'checked_in' => (bool) $booking->checked_in,
            'seats' => $booking->seats?->pluck('seat_id')->filter()->values()->all() ?? [],
            'vehicle_option_label' => $booking->vehicle_option_label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function whenBlock(?TripSchedule $schedule): array
    {
        if (! $schedule) {
            return [];
        }

        $departsAt = $schedule->departs_at;
        $departureDate = $schedule->departure_date;

        // รถที่ออกคืนก่อนวันทริป — ลูกค้าพลาดรถเพราะเรื่องนี้บ่อยกว่าเรื่องอื่นทั้งหมด
        $nightBefore = $departsAt
            && $departureDate
            && $departsAt->toDateString() < $departureDate->toDateString();

        return [
            'date_label' => ThaiDate::full($departureDate),
            'range_label' => ThaiDate::range($departureDate, $schedule->return_date),
            'return_label' => $schedule->return_date ? ThaiDate::full($schedule->return_date) : null,
            // เวลาที่ไม่เคยถูกตั้งต้องเงียบ ไม่ใช่พิมพ์ 00:00 ออกไป
            'time_label' => $departsAt ? $departsAt->format('H:i') : null,
            'departs_on_label' => $departsAt ? ThaiDate::full($departsAt) : null,
            'night_before' => $nightBefore,
            'days_left' => $this->daysLeft($schedule),
            'countdown_label' => $this->countdownLabel($schedule),
            'is_flight' => $schedule->isFlight(),
            'transport_type' => $schedule->transport_type,
        ];
    }

    /**
     * นับเป็น "วัน" เสมอ ไม่ใช่ชั่วโมง — รอบที่ไม่เคยตั้งเวลาออกรถจะมี 00:00 ปลอม
     * ติดมากับ effectiveDepartsAt() ถ้าเอาไปลบเป็นชั่วโมงจะได้ตัวเลขที่ผิด
     */
    private function daysLeft(?TripSchedule $schedule): ?int
    {
        $target = $schedule?->departs_at ?: $schedule?->departure_date;

        if (! $target) {
            return null;
        }

        // คอลัมน์พวกนี้เก็บ "วันเวลาไทย" ไว้ในชนิดที่แอปมองเป็น UTC เทียบตรง ๆ กับ
        // now('Asia/Bangkok') จะเพี้ยนไป 7 ชั่วโมง — ตัดเหลือเฉพาะวันแล้วค่อยเทียบ
        $targetDay = Carbon::parse($target->toDateString(), self::TIMEZONE)->startOfDay();

        return (int) Carbon::now(self::TIMEZONE)->startOfDay()->diffInDays($targetDay, false);
    }

    private function countdownLabel(TripSchedule $schedule): ?string
    {
        $days = $this->daysLeft($schedule);

        return match (true) {
            $days === null => null,
            $days < 0 => 'เดินทางไปแล้ว',
            $days === 0 => 'วันนี้',
            $days === 1 => 'พรุ่งนี้',
            default => "อีก {$days} วัน",
        };
    }

    /**
     * จุดขึ้นรถ — เก็บไว้สองที่ (หัวใบจองและรายผู้โดยสาร) เพราะในใบเดียวกัน
     * แต่ละคนขึ้นคนละจุดได้ จึงไล่จากรายคนก่อนแล้วค่อย fallback มาที่หัวใบ
     *
     * @return array<string, mixed>
     */
    private function pickupBlock(Booking $booking, ?TripSchedule $schedule): array
    {
        if (! $schedule || $schedule->isFlight()) {
            return ['points' => []];
        }

        // จอยทริปไม่มีจุดขึ้นรถโดยตั้งใจ — ลูกค้าเดินทางไปเจอกันที่หน้างานเอง
        if ($booking->is_join_trip) {
            return ['points' => [], 'join_trip' => true];
        }

        $points = collect();

        foreach ($booking->passengers as $passenger) {
            $point = $passenger->pickupPoint ?: $booking->pickupPoint;

            if (! $point) {
                continue;
            }

            $points->push([
                'passenger' => $passenger->name,
                'point' => $this->pickupPointArray($point),
            ]);
        }

        if ($points->isEmpty() && $booking->pickupPoint) {
            $points->push([
                'passenger' => null,
                'point' => $this->pickupPointArray($booking->pickupPoint),
            ]);
        }

        $unique = $points->pluck('point.id')->unique();

        return [
            // ทุกคนขึ้นจุดเดียวกัน = ไม่ต้องโชว์ชื่อกำกับรายคนให้รก
            'same_for_everyone' => $unique->count() <= 1,
            'points' => $points->all(),
            'custom' => $this->customPickupArray($booking),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pickupPointArray(SchedulePickupPoint $point): array
    {
        return [
            'id' => $point->id,
            'location' => $point->pickup_location,
            'region' => $point->region_label ?: $point->region,
            'time' => $point->pickup_time,
            'map_url' => $point->map_url,
            'notes' => $point->notes,
        ];
    }

    /**
     * จุดรับที่ลูกค้าปักหมุดเอง — มีค่าเฉพาะใบที่แอดมินอนุมัติแล้วเท่านั้น
     *
     * @return array<string, mixed>|null
     */
    private function customPickupArray(Booking $booking): ?array
    {
        if (blank($booking->custom_pickup_label) || $booking->custom_pickup_status !== 'approved') {
            return null;
        }

        $hasPin = $booking->custom_pickup_lat && $booking->custom_pickup_lng;

        return [
            'label' => $booking->custom_pickup_label,
            'note' => $booking->custom_pickup_note,
            'map_url' => $hasPin
                ? 'https://www.google.com/maps/search/?api=1&query='.$booking->custom_pickup_lat.','.$booking->custom_pickup_lng
                : null,
        ];
    }

    /**
     * รอบบิน — ไม่มีรถไปรับ มีแต่จุดนัดพบที่สนามบิน
     *
     * @return array<string, mixed>|null
     */
    private function meetupBlock(?TripSchedule $schedule): ?array
    {
        if (! $schedule || ! $schedule->isFlight()) {
            return null;
        }

        $meetingAt = $schedule->meetingAt();

        if (blank($schedule->meeting_point) && ! $meetingAt) {
            return null;
        }

        return [
            'point' => $schedule->meeting_point,
            'map_url' => $schedule->meeting_map_url,
            'time_label' => $meetingAt?->format('H:i'),
            'date_label' => $meetingAt ? ThaiDate::full($meetingAt) : null,
            'baggage' => $schedule->baggage_allowance,
            'legs' => $schedule->flightLegs(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function vehicleBlock(?TripSchedule $schedule): ?array
    {
        $vehicle = $schedule?->vehicle;

        if (! $vehicle) {
            return null;
        }

        $plate = trim((string) $vehicle->license_plate);
        $driverName = trim((string) $vehicle->driver_name);
        $driverPhone = trim((string) $vehicle->driver_phone);

        // ยังไม่มีอะไรให้บอกเลย = อย่าโชว์กล่องว่าง
        if ($plate === '' && $driverName === '' && $driverPhone === '') {
            return null;
        }

        return [
            'name' => $vehicle->name,
            'type' => $vehicle->type,
            'plate' => $plate ?: null,
            'color' => $vehicle->color,
            'driver_name' => $driverName ?: null,
            'driver_phone' => $driverPhone ?: null,
        ];
    }

    /**
     * ทีมงานที่ยังรับผิดชอบรอบนี้อยู่ — คนที่ถูกปลดหลังจบทริปไม่ควรมีเบอร์ค้างอยู่
     * ในใบเดินทางของรอบถัดไป
     *
     * @return array<int, array<string, mixed>>
     */
    private function crewBlock(?TripSchedule $schedule): array
    {
        if (! $schedule) {
            return [];
        }

        return $schedule->activeStaff
            ->map(fn ($staff) => [
                'name' => $staff->nickname ?: $staff->name,
                'full_name' => $staff->name,
                'phone' => trim((string) $staff->phone) ?: null,
            ])
            ->filter(fn ($staff) => filled($staff['name']))
            ->values()
            ->all();
    }

    /**
     * กำหนดการทั้งหมด ไม่ตัดท้าย — ลูกค้าอ่านใบนี้แทนการเปิดแอป ถ้าตัดก็คือหาย
     *
     * @return array<int, array<string, mixed>>
     */
    private function itineraryBlock(?TripSchedule $schedule): array
    {
        if (! $schedule) {
            return [];
        }

        return $schedule->itineraryItems
            ->map(fn ($item) => [
                'date_label' => $item->item_date ? ThaiDate::short($item->item_date) : null,
                'time' => $item->time ? Str::substr((string) $item->time, 0, 5) : null,
                'title' => $item->title,
                'detail' => $item->detail,
                'link' => $item->link,
            ])
            ->values()
            ->all();
    }

    /**
     * ยอดที่ยังค้าง — รู้ตอนอยู่หน้างานแล้วคือสายเกินไป
     *
     * @return array<string, mixed>
     */
    private function paymentBlock(Booking $booking): array
    {
        $total = (float) $booking->total_amount;
        $paid = (float) $booking->paid_amount;
        $outstanding = max(0, round($total - $paid, 2));

        $nextInstallment = $booking->installmentPayments
            ->where('status', '!=', 'paid')
            ->sortBy('due_date')
            ->first();

        $dueAt = $nextInstallment?->due_date ?: $booking->balance_due_at;

        return [
            'total' => $total,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'is_settled' => $outstanding <= 0,
            'due_label' => $dueAt ? ThaiDate::full($dueAt) : null,
            'is_overdue' => $dueAt ? $dueAt->copy()->endOfDay()->isPast() : false,
            'installment_no' => $nextInstallment?->installment_no,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function weatherBlock(?TripSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        // attach() กลืนข้อผิดพลาดของปลายทางไว้เองแล้ว และไม่ทำอะไรเลยเมื่อรอบอยู่
        // ไกลเกินหน้าต่างพยากรณ์ — พยากรณ์อากาศเป็นของแถม ใบเดินทางต้องออกได้เสมอ
        $this->weather->attach($schedule);

        return $schedule->weather_forecast ?: null;
    }
}
