<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Receipt;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Support\AppLinks;
use App\Support\SiteSettings;
use App\Support\ThaiDate;
use Carbon\Carbon;
use Illuminate\Support\Arr;
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

    public function __construct(
        private WeatherService $weather,
        private BookingDocumentService $documents,
        private QrCodeService $qrCodes,
        private PickupStatusService $pickupStatus,
        private TripProgressService $progress,
    ) {}

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

        // หน้าเว็บต้องเห็นฐานข้อมูล ณ วินาทีที่เปิดเสมอ จึงทิ้งของที่ประกอบไว้รอบก่อน
        //
        // ที่ต้องเขียนบรรทัดนี้เพราะอ็อบเจกต์นี้อยู่ได้นานกว่าหนึ่งรีเควสต์ง่ายกว่าที่คิด:
        // Route จำอินสแตนซ์ของคอนโทรลเลอร์ไว้กับตัวมันเอง (และของที่ฉีดเข้ามาด้วย)
        // ลูกค้าที่กดปุ่มแล้วกลับมาหน้าเดิมจึงมีสิทธิ์ได้ใบเดินทางฉบับก่อนกดคืนไป
        unset($this->cache[$booking->id]);

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
            'schedule.announcements.author',
            'installmentPayments',
            'documents',
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
            'announcements' => $this->announcementsBlock($schedule),
            'todo' => $this->todoBlock($booking),
            'checkin' => $this->checkinBlock($booking, $schedule),
            'pickup_status' => $this->pickupStatusBlock($booking, $schedule),
            'progress' => $this->progressBlock($schedule),
            'payment' => $this->paymentBlock($booking),
            'weather' => $this->weatherBlock($schedule),
            'app' => $this->appBlock($booking),
            'links' => [
                'brief' => $booking->briefUrl(),
                'calendar' => $booking->briefUrl().'/calendar.ics',
                'track' => $booking->shareUrl(),
                'pay' => $booking->payUrl(),
                'receipt' => $this->receiptUrl($booking),
            ],
            'ack' => [
                'read_at' => $booking->brief_read_at?->toISOString(),
                'acknowledged' => $booking->brief_ack_at !== null,
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
     * ช่องใน [whenBlock] ที่เปลี่ยนค่าเองทุกเที่ยงคืนโดยไม่มีใครไปแตะอะไรเลย
     *
     * "อีก 2 วัน" กลายเป็น "พรุ่งนี้" เองตามนาฬิกา ถ้าปล่อยให้มันอยู่ในลายนิ้วมือ
     * ลายนิ้วมือก็เปลี่ยนทุกวัน และลูกค้าจะได้อีเมล "อัปเดตใบเดินทาง" ทุกวัน
     * ทั้งที่รถคันเดิม คนเดิม เวลาเดิม
     *
     * @var array<int, string>
     */
    private const CLOCK_DRIVEN_FIELDS = ['days_left', 'countdown_label'];

    /**
     * ลายนิ้วมือของเนื้อหาที่ "ถ้าเปลี่ยนแล้วลูกค้าต้องรู้"
     *
     * เกณฑ์เดียวที่ใช้ตัดสินว่าอะไรควรอยู่ในนี้: มีคนตั้งใจเปลี่ยนมันหรือเปล่า
     * เปลี่ยนคนขับ ย้ายจุดขึ้นรถ ขยับกำหนดการ — ใช่ ลูกค้าต้องรู้
     * ส่วนสภาพอากาศ ยอดเงินคงเหลือ และตัวเลขนับถอยหลัง ขยับเองทั้งนั้น และสองอย่าง
     * แรกมีช่องทางเตือนของตัวเองอยู่แล้ว
     */
    public function digest(Booking $booking): string
    {
        $payload = $this->payload($booking);

        return sha1(json_encode([
            Arr::except($payload['when'], self::CLOCK_DRIVEN_FIELDS),
            $payload['pickup'],
            $payload['meetup'],
            $payload['vehicle'],
            $payload['crew'],
            $payload['itinerary'],
            $payload['announcements'],
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
            // วันแบบดิบ (Y-m-d) สำหรับไฟล์ปฏิทิน — ป้ายภาษาไทยข้างบนเอาไปคำนวณต่อไม่ได้
            'start_date' => $departureDate?->toDateString(),
            'end_date' => $schedule->return_date?->toDateString(),
            'departs_on_date' => $departsAt?->toDateString(),
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
     * ประกาศจากผู้จัดของรอบนี้
     *
     * ประกาศมีช่องทางของตัวเองอยู่แล้ว (แจ้งเตือนเข้าแอป) แต่คนที่ได้ใบเดินทาง
     * คือคนที่ไม่ได้โหลดแอปพอดี — ถ้าไม่เอามาไว้ตรงนี้ กลุ่มนี้จะไม่มีวันได้อ่าน
     * เลยสักฉบับ ทั้งที่มันคือเรื่องที่ทีมงานตั้งใจเขียนถึงเขาโดยตรง
     *
     * @return array<int, array<string, mixed>>
     */
    private function announcementsBlock(?TripSchedule $schedule): array
    {
        if (! $schedule) {
            return [];
        }

        return $schedule->announcements
            ->map(fn ($announcement) => [
                'title' => $announcement->title,
                'body' => $announcement->body,
                'category' => $announcement->category,
                'category_label' => AnnouncementService::categoryLabel($announcement->category),
                'is_pinned' => (bool) $announcement->is_pinned,
                'is_urgent' => $announcement->category === 'urgent',
                'author_name' => $announcement->author?->nickname ?: $announcement->author?->name ?: 'ทีมงาน',
                'date_label' => $announcement->created_at ? ThaiDate::short($announcement->created_at) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * "ยังขาดอะไรอยู่" — ข้อมูลที่ทีมงานต้องได้ก่อนวันเดินทาง และตอนนี้ต้องไล่โทรตามเอง
     *
     * ทุกข้อมีปุ่มที่กรอกจบได้ในหน้านั้นเลย ไม่ใช่แค่บอกว่าขาด — รายการที่บอกว่า
     * ขาดแต่ไม่บอกว่าไปกรอกที่ไหน แปลว่าลูกค้าต้องโทรมาถาม ซึ่งแพงกว่าเดิม
     *
     * ตั้งใจไม่ใส่ไว้ใน [digest]: ลูกค้ากรอกข้อมูลของตัวเองเสร็จแล้วรายการนี้จะสั้นลง
     * เอง ถ้านับเป็นการเปลี่ยนแปลง ลูกค้าจะได้อีเมล "อัปเดตใบเดินทาง" เพราะตัวเอง
     * เพิ่งกรอกฟอร์มไป ซึ่งไม่มีใครอยากได้
     *
     * @return array<int, array<string, mixed>>
     */
    private function todoBlock(Booking $booking): array
    {
        $items = [];

        $missingPassport = $booking->passengersMissingPassport();

        if ($missingPassport->isNotEmpty()) {
            $items[] = [
                'key' => 'passport',
                'title' => 'ข้อมูลพาสปอร์ต',
                'detail' => $this->whoLabel($missingPassport->pluck('name')->all(), $booking)
                    .' — ต้องใช้ชื่อภาษาอังกฤษ เลขที่พาสปอร์ต และวันหมดอายุ สำหรับออกตั๋วและทำประกัน',
                'cta' => 'กรอกข้อมูลพาสปอร์ต',
                'url' => $booking->passportUrl(),
            ];
        }

        $missingBirthdate = $booking->passengers->filter(fn ($passenger) => blank($passenger->birth_date));

        if ($missingBirthdate->isNotEmpty()) {
            $items[] = [
                'key' => 'birthdate',
                'title' => 'วันเกิดของผู้เดินทาง',
                'detail' => $this->whoLabel($missingBirthdate->pluck('name')->all(), $booking)
                    .' — ใช้ทำประกันการเดินทาง กรอกไว้ก่อนวันเดินทางนะครับ',
                'cta' => 'กรอกวันเกิด',
                'url' => $booking->birthdateUrl(),
            ];
        }

        foreach ($this->documents->missingRequirements($booking) as $requirement) {
            $items[] = [
                'key' => 'document:'.$requirement['key'],
                'title' => $requirement['label'],
                'detail' => $this->whoLabel($requirement['passengers'], $booking)
                    .($requirement['note'] ? ' — '.$requirement['note'] : ''),
                // ยังไม่มีหน้าอัปโหลดแบบไม่ต้องล็อกอิน ทางที่ไปถึงทีมงานได้จริงคือทักไลน์
                'cta' => null,
                'url' => null,
            ];
        }

        return $items;
    }

    /**
     * "ใคร" ของรายการที่ยังขาด — ขึ้นชื่อเมื่อขาดแค่บางคน เพราะนั่นคือข้อมูลจริง
     * ที่ทำให้ลูกค้ารู้ว่าต้องไปถามใคร แต่พอขาดทั้งใบก็ไม่ต้องไล่ชื่อให้รก
     *
     * @param  array<int, string>  $names
     */
    private function whoLabel(array $names, Booking $booking): string
    {
        $total = $booking->passengers->count();

        if ($names === [] || (count($names) === $total && $total > 1)) {
            return 'ผู้เดินทางทุกท่าน';
        }

        if (count($names) > 3) {
            return 'ผู้เดินทาง '.count($names).' ท่าน';
        }

        return implode(', ', $names);
    }

    /**
     * ใบเสร็จฉบับล่าสุดของการจองนี้ — ไม่ใช่ทุกใบจองที่มี (ออกเมื่อชำระครบ)
     */
    private function receiptUrl(Booking $booking): ?string
    {
        $receipt = Receipt::where('booking_id', $booking->id)
            ->whereNotNull('verify_token')
            ->orderByDesc('id')
            ->first();

        return $receipt ? url('/receipt/'.$receipt->verify_token) : null;
    }

    /**
     * QR เช็คอินบนหน้าใบเดินทาง
     *
     * จนถึงตอนนี้ QR อยู่ในแอปอย่างเดียว ซึ่งแปลว่ากลุ่มที่ได้ใบเดินทาง (คนที่
     * ไม่ได้โหลดแอป) คือกลุ่มเดียวที่สตาฟต้องขานชื่อทีละคนหน้างาน ทั้งที่รหัส
     * ของเขามีอยู่แล้วตั้งแต่วันจอง
     *
     * ขึ้นเฉพาะช่วงที่ใช้จริง (เย็นวันก่อนถึงวันเดินทาง) ไม่ใช่ตั้งแต่วันแรกที่ส่งใบ —
     * ใบเดินทางถูกส่งต่อให้คนที่บ้านเป็นปกติ รหัสขึ้นรถจึงไม่ควรค้างอยู่บนหน้าจอ
     * นานกว่าที่จำเป็น
     *
     * @return array<string, mixed>
     */
    private function checkinBlock(Booking $booking, ?TripSchedule $schedule): array
    {
        if ($booking->checked_in) {
            return [
                'show' => false,
                'checked_in' => true,
                'checked_in_label' => $booking->checked_in_at?->timezone(self::TIMEZONE)->format('H:i'),
            ];
        }

        $show = $schedule
            && $booking->status === 'confirmed'
            && blank($booking->qr_code) === false
            && $this->pickupStatus->isWithinWindow($schedule);

        return [
            'show' => $show,
            'checked_in' => false,
            'checked_in_label' => null,
            'code' => $show ? $booking->qr_code : null,
            'qr' => $show ? $this->qrCodes->svgDataUri($booking->qr_code, 220) : null,
        ];
    }

    /**
     * ปุ่ม "กำลังไป / ถึงแล้ว / อาจสาย" สำหรับคนที่ไม่มีแอป
     *
     * กติกาว่ากดได้เมื่อไหร่เป็นของ PickupStatusService ทั้งหมด ที่นี่แค่ถาม —
     * ปุ่มที่กดแล้วเด้ง error กลับมาแย่กว่าปุ่มที่ไม่ขึ้นให้เห็นตั้งแต่แรก
     *
     * @return array<string, mixed>
     */
    private function pickupStatusBlock(Booking $booking, ?TripSchedule $schedule): array
    {
        $current = $booking->freshPickupStatus();

        $show = $schedule
            && $booking->status === 'confirmed'
            && ! $booking->checked_in
            && ! $booking->is_join_trip
            && $this->pickupStatus->isWithinWindow($schedule);

        return [
            'show' => $show,
            'current' => $current,
            'current_label' => PickupStatusService::label($current, $booking->pickup_status_eta_minutes),
            'reported_at_label' => $current
                ? $booking->pickup_status_at?->timezone(self::TIMEZONE)->format('H:i')
                : null,
            'options' => [
                ['value' => Booking::PICKUP_STATUS_ON_THE_WAY, 'label' => 'กำลังไปจุดนัด', 'emoji' => '🚶'],
                ['value' => Booking::PICKUP_STATUS_ARRIVED, 'label' => 'ถึงจุดนัดแล้ว', 'emoji' => '📍'],
                ['value' => Booking::PICKUP_STATUS_LATE, 'label' => 'อาจมาสาย', 'emoji' => '⏳'],
            ],
        ];
    }

    /**
     * ความคืบหน้าระหว่างทริป — ของชิ้นเดียวกับที่หน้าวันเดินทางในแอปและลิงก์ให้
     * ที่บ้านติดตามใช้ (หมุดกำหนดการที่ทีมงานกดยืนยัน ไม่ใช่พิกัดสดของใคร)
     *
     * ขึ้นเฉพาะเมื่อออกเดินทางแล้วจริง ๆ — ก่อนหน้านั้นแถบ 0% ไม่ได้บอกอะไร
     * นอกจากทำให้ลูกค้าคิดว่าทีมงานลืมกด
     *
     * @return array<string, mixed>|null
     */
    private function progressBlock(?TripSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        $progress = $this->progress->forSchedule($schedule);

        if (! $progress['has_itinerary'] || $progress['reached_count'] === 0) {
            return null;
        }

        return $progress;
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
     * ชวนโหลดแอป — เฉพาะคนที่ยังไม่มี
     *
     * ใบเดินทางไปถึงกลุ่มที่ไม่ได้โหลดแอปพอดี และไปถึงในนาทีที่เขาสนใจทริปที่สุด
     * ของในแอปที่ใบเดินทางให้ไม่ได้จริง ๆ มีอยู่: ห้องแชทของรอบ QR เช็คอิน และ
     * การติดตามรถ จึงเป็นคำชวนที่มีเนื้อ ไม่ใช่ป้ายโฆษณา
     *
     * คนที่มีแอปอยู่แล้วต้องไม่เห็นบล็อกนี้ — เห็นของที่ตัวเองถืออยู่ถูกขายซ้ำ
     * ทำให้ทั้งใบเดินทางดูเหมือนใบปลิว และคำชวนครั้งที่สำคัญจริงจะหมดน้ำหนัก
     *
     * @return array<string, mixed>
     */
    private function appBlock(Booking $booking): array
    {
        return [
            'show' => ! AppLinks::hasApp($booking->user),
            'ios' => AppLinks::ios(),
            'android' => AppLinks::android(),
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
