<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * "ข้อมูลการเดินทางของฉัน" — คำตอบของคำถามที่ลูกค้าถามซ้ำที่สุด
 * (ขึ้นรถกี่โมง / รอที่ไหน / รถทะเบียนอะไร / เบอร์คนขับ-สตาฟ)
 *
 * รวมไว้ที่เดียวเพราะข้อมูลชุดนี้ถูกใช้หลายที่: ปุ่มคำถามด่วนในห้องแชท,
 * ปุ่ม "ส่งสรุปการเดินทาง" ของสตาฟ และข้อความเตือนก่อนเดินทาง
 *
 * ค่าที่ยัง "ไม่รู้" จะไม่ถูกตัดทิ้ง แต่ส่ง null พร้อม pending_note กลับไป
 * ให้ client แสดงว่า "ทีมงานจะยืนยันให้ก่อนเดินทาง" — ความไม่รู้ที่อธิบายได้
 * หยุดคำถามได้พอ ๆ กับตัวคำตอบ
 */
class TripFactsService
{
    public const PENDING_VEHICLE = 'ทีมงานจะยืนยันรถและทะเบียนให้ก่อนเดินทาง 1 วัน';

    public const PENDING_DRIVER = 'ทีมงานจะแจ้งชื่อและเบอร์คนขับให้ก่อนเดินทาง 1 วัน';

    public const PENDING_STAFF = 'ทีมงานจะแจ้งสตาฟประจำรอบให้ก่อนเดินทาง';

    public const PENDING_PICKUP = 'ทีมงานจะยืนยันจุดรับและเวลาให้อีกครั้งก่อนเดินทาง';

    /** ยกกำหนดการมาให้ชีตคำถามด่วนกี่รายการ (ที่เหลือกดดูต่อในหน้ากำหนดการเต็ม) */
    public const ITINERARY_LIMIT = 12;

    /** ยกกำหนดการลงห้องแชทกี่รายการ — ยาวกว่านี้บับเบิลเดียวอ่านไม่ไหว */
    public const ITINERARY_CHAT_LIMIT = 8;

    /** ขึ้นต้นข้อความกำหนดการทุกฉบับ — ใช้หาข้อความกำหนดการล่าสุดในห้อง */
    public const ITINERARY_MARK = '🗺️';

    public function __construct(
        private ScheduleItineraryService $itineraryService,
    ) {}

    /**
     * ข้อมูลการเดินทางของผู้ใช้คนนี้ในรอบนี้
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, TripSchedule $schedule): array
    {
        $schedule->loadMissing(['trip', 'vehicle.driver', 'pickupPoints']);
        $booking = $this->bookingOf($user, $schedule);

        return [
            'departure_label' => $schedule->departureLabelThai(),
            'departs_at' => $schedule->departs_at?->toISOString(),
            'booking_ref' => $booking?->booking_ref,
            'pickup' => $this->pickup($booking, $schedule),
            'pickup_points' => $schedule->pickupPoints
                ->sortBy('sort_order')
                ->map(fn (SchedulePickupPoint $p) => $this->pickupPayload($p))
                ->values()
                ->all(),
            'vehicle' => $this->vehicle($schedule),
            'driver' => $this->driver($schedule),
            'staff' => $this->staff($schedule),
            'itinerary' => $this->itinerary($schedule),
        ];
    }

    /**
     * กำหนดการของรอบแบบย่อสำหรับชีตคำถามด่วน — null เมื่อรอบนี้ยังไม่มีกำหนดการ
     * (ทั้งของรอบเองและของทริป) ฝั่งแอปใช้ค่านี้ตัดสินว่าจะโชว์ปุ่มถามไหม
     *
     * รูปร่างของ items เหมือนกับ GET schedules/{id}/itinerary ทุกประการ แอปจะได้
     * วาดด้วยโค้ดชุดเดียวกับหน้ากำหนดการเต็ม
     *
     * @return array<string, mixed>|null
     */
    public function itinerary(TripSchedule $schedule): ?array
    {
        $payload = $this->itineraryService->payload($schedule);
        $items = $payload['items'];

        if ($items === []) {
            return null;
        }

        return [
            'source' => $payload['source'],
            'total' => count($items),
            'items' => array_slice($items, 0, self::ITINERARY_LIMIT),
        ];
    }

    /**
     * กำหนดการในรูปข้อความสำหรับโพสต์ลงห้องแชท — ใช้ทั้งข้อความอัตโนมัติ D-2
     * (TripChatTimelineService), ปุ่ม "ส่งกำหนดการ" ของสตาฟ และข้อความแจ้งเมื่อ
     * แอดมินแก้กำหนดการทีหลัง (AnnounceItineraryChangeJob)
     *
     * $updated เปลี่ยน "เฉพาะบรรทัดหัว" เท่านั้น ที่เหลือของข้อความต้องเหมือนกัน
     * เป๊ะ ๆ เพราะ AnnounceItineraryChangeJob ใช้ส่วนที่เหลือเทียบว่ากำหนดการ
     * เปลี่ยนจริงไหม (แอดมินแก้แล้วแก้กลับ = ไม่ต้องกวนห้อง)
     *
     * คืน null เมื่อยังไม่มีกำหนดการ เพื่อให้ผู้เรียกรอข้อมูลของแอดมินได้
     */
    public function itinerarySummaryText(TripSchedule $schedule, bool $updated = false): ?string
    {
        $payload = $this->itineraryService->payload($schedule);
        $items = $payload['items'];

        if ($items === []) {
            return null;
        }

        $title = trim((string) ($schedule->trip?->title ?? ''));

        $heading = $updated ? 'กำหนดการมีการปรับ' : 'กำหนดการเดินทาง';

        $lines = [self::ITINERARY_MARK.' '.$heading.($title !== '' ? " — {$title}" : '')];
        $lines[] = 'ออกเดินทาง '.$schedule->departureLabelThai();

        // เกินโควตาอยู่รายการเดียวก็ใส่ให้ครบไปเลย — "ยังมีอีก 1 รายการ"
        // กินที่พอ ๆ กับรายการนั้นเอง แถมทำให้กำหนดการดูขาดตอนโดยไม่จำเป็น
        $limit = count($items) === self::ITINERARY_CHAT_LIMIT + 1
            ? count($items)
            : self::ITINERARY_CHAT_LIMIT;

        $shown = array_slice($items, 0, $limit);
        $currentGroup = null;

        foreach ($shown as $item) {
            $group = $this->itineraryGroupLabel($item);

            if ($group !== null && $group !== $currentGroup) {
                $currentGroup = $group;
                $lines[] = '';
                $lines[] = "📅 {$group}";
            }

            $time = trim((string) ($item['time'] ?? ''));
            $lines[] = '• '.($time !== '' ? "{$time} น. " : '').trim((string) $item['title']);

            $detail = trim((string) ($item['detail'] ?? ''));
            if ($detail !== '') {
                $lines[] = '  '.Str::limit(preg_replace('/\s+/u', ' ', $detail), 90);
            }
        }

        $lines[] = '';

        // บอก "ในแอป" ให้ชัด — ห้องแชทบนเว็บอ่านข้อความเดียวกันนี้ได้ แต่ยังไม่มี
        // ปุ่มคำถามด่วน การชี้ปุ่มลอย ๆ จะกลายเป็นบอกทางผิดสำหรับคนที่เปิดจากเว็บ
        $more = count($items) - count($shown);
        if ($more > 0) {
            $lines[] = "ยังมีอีก {$more} รายการ — เปิดแอปแล้วกดปุ่ม \"กำหนดการ\" เหนือช่องพิมพ์ ดูฉบับเต็มได้ครับ";
        } else {
            $lines[] = 'ดูย้อนหลังได้ตลอดในแอป ที่ปุ่ม "กำหนดการ" เหนือช่องพิมพ์ครับ';
        }

        $lines[] = 'เวลาอาจขยับได้ตามหน้างานและสภาพอากาศ ทีมงานจะแจ้งในห้องนี้ทุกครั้งครับ 🌿';

        return implode("\n", $lines);
    }

    /**
     * หัวข้อกลุ่มของรายการกำหนดการ — กำหนดการของรอบจัดกลุ่มด้วยวันที่จริง
     * ส่วนกำหนดการระดับทริปมาพร้อมชื่อภาค/"วันที่ N" อยู่แล้ว
     *
     * @param  array<string, mixed>  $item
     */
    private function itineraryGroupLabel(array $item): ?string
    {
        // กำหนดการระดับทริปมากับกลุ่มของมันเอง (ชื่อภาค หรือ "วันที่ N") — ใช้ตามนั้น
        // ทั้งชุด จะได้ไม่ปนกันระหว่างหัวข้อวันที่จริงกับ "วันที่ N" ในข้อความเดียว
        $group = trim((string) ($item['group'] ?? ''));
        if ($group !== '') {
            return $group;
        }

        $date = trim((string) ($item['item_date'] ?? ''));

        return $date !== '' ? ThaiDate::short(CarbonImmutable::parse($date)) : null;
    }

    /**
     * ข้อความสรุปการเดินทางของทั้งรอบ — ใช้กับปุ่ม "ส่งสรุปการเดินทาง" ของสตาฟ
     * (โพสต์เข้าห้องแชทให้ทุกคนเห็นพร้อมกัน แทนการพิมพ์ตอบทีละคน)
     */
    public function roundSummaryText(TripSchedule $schedule): string
    {
        $schedule->loadMissing(['trip', 'vehicle.driver', 'pickupPoints', 'staff']);

        $lines = ['📋 สรุปข้อมูลการเดินทาง'];
        $lines[] = 'ออกเดินทาง '.$schedule->departureLabelThai();

        $points = $schedule->pickupPoints->sortBy('sort_order');
        if ($points->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🚩 จุดรับและเวลา';
            foreach ($points as $point) {
                $lines[] = '• '.$this->pickupLine($point);
            }
        }

        $vehicle = $schedule->vehicle;
        $lines[] = '';
        if ($vehicle) {
            $plate = trim((string) $vehicle->license_plate);
            $color = trim((string) $vehicle->color);
            $detail = collect([
                trim((string) $vehicle->name) ?: trim((string) $vehicle->type),
                $plate !== '' ? "ทะเบียน {$plate}" : null,
                $color !== '' ? "สี{$color}" : null,
            ])->filter()->implode(' · ');
            $lines[] = '🚐 รถ: '.($detail !== '' ? $detail : 'ทีมงานจะยืนยันอีกครั้ง');
        } else {
            $lines[] = '🚐 รถ: '.self::PENDING_VEHICLE;
        }

        $driverName = trim((string) ($vehicle?->driver_name ?? ''));
        $driverPhone = trim((string) ($vehicle?->driver_phone ?? ''));
        $lines[] = '👤 คนขับ: '.($driverName !== '' || $driverPhone !== ''
            ? trim($driverName.' '.($driverPhone !== '' ? "โทร {$driverPhone}" : ''))
            : self::PENDING_DRIVER);

        $staff = $this->staff($schedule);
        if ($staff !== []) {
            $lines[] = '🎽 สตาฟประจำรอบ:';
            foreach ($staff as $member) {
                $phone = $member['phone'] ? " โทร {$member['phone']}" : '';
                $lines[] = '• '.$member['name'].$phone;
            }
        } else {
            $lines[] = '🎽 สตาฟประจำรอบ: '.self::PENDING_STAFF;
        }

        $lines[] = '';
        $lines[] = 'ข้อมูลนี้ดูได้ตลอดในแอป ที่ใบจองของคุณ หรือกดปุ่มคำถามด่วนในห้องแชทได้เลยครับ 🌿';

        return implode("\n", $lines);
    }

    /**
     * สรุปสั้นสำหรับ push เตือนก่อนเดินทาง — จุดรับ/เวลา + ทะเบียน (ถ้ารู้แล้ว)
     * ให้ลูกค้าอ่านจบใน notification โดยไม่ต้องเปิดแอป
     */
    public function reminderLine(Booking $booking): string
    {
        $schedule = $booking->schedule;
        if (! $schedule) {
            return '';
        }

        $parts = [];

        $pickup = $this->pickup($booking, $schedule);
        if ($pickup && $pickup['location']) {
            $where = $pickup['time']
                ? "{$pickup['time']} น. ที่ {$pickup['location']}"
                : "จุดรับ {$pickup['location']}";
            $parts[] = $where;
        }

        $vehicle = $schedule->vehicle;
        $plate = trim((string) ($vehicle?->license_plate ?? ''));
        if ($plate !== '') {
            $parts[] = "รถทะเบียน {$plate}";
        }

        $driverPhone = trim((string) ($vehicle?->driver_phone ?? ''));
        if ($driverPhone !== '') {
            $parts[] = "คนขับ {$driverPhone}";
        }

        return implode(' · ', $parts);
    }

    /**
     * การจอง active ของผู้ใช้ในรอบนี้ (เจ้าของก่อน แล้วค่อยดูว่าเป็นเพื่อนร่วมจองไหม)
     */
    private function bookingOf(User $user, TripSchedule $schedule): ?Booking
    {
        $booking = Booking::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
            ->with('pickupPoint')
            ->latest('id')
            ->first();

        if ($booking) {
            return $booking;
        }

        $memberBookingId = BookingMember::where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereHas('booking', fn ($q) => $q
                ->where('schedule_id', $schedule->id)
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES))
            ->value('booking_id');

        return $memberBookingId
            ? Booking::with('pickupPoint')->find($memberBookingId)
            : null;
    }

    /**
     * จุดรับของการจองนี้ — จุดที่เลือกไว้, จุดรับแบบปักหมุดเอง หรือ null เมื่อยังไม่ระบุ
     *
     * @return array<string, mixed>|null
     */
    private function pickup(?Booking $booking, TripSchedule $schedule): ?array
    {
        if (! $booking) {
            return null;
        }

        // จุดรับแบบปักหมุดเองที่แอดมินอนุมัติแล้ว มาก่อนจุดรับมาตรฐาน
        if ($booking->custom_pickup_status === 'approved' && $booking->custom_pickup_label) {
            return [
                'label' => 'จุดรับที่คุณปักหมุด',
                'location' => $booking->custom_pickup_label,
                'time' => null,
                'map_url' => ($booking->custom_pickup_lat && $booking->custom_pickup_lng)
                    ? 'https://www.google.com/maps/search/?api=1&query='
                        .$booking->custom_pickup_lat.','.$booking->custom_pickup_lng
                    : null,
                'notes' => $booking->custom_pickup_note,
                'is_custom' => true,
            ];
        }

        $point = $booking->pickupPoint;

        // การจองเก่าที่เก็บแค่ภูมิภาค — จับคู่กับจุดรับของรอบให้
        if (! $point && $booking->pickup_region) {
            $point = $schedule->pickupPoints
                ->firstWhere('region', $booking->pickup_region);
        }

        return $point ? $this->pickupPayload($point) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function pickupPayload(SchedulePickupPoint $point): array
    {
        return [
            'label' => trim((string) $point->region_label) ?: trim((string) $point->pickup_location),
            'location' => trim((string) $point->pickup_location),
            'time' => trim((string) $point->pickup_time) ?: null,
            'map_url' => trim((string) $point->map_url) ?: null,
            'notes' => trim((string) $point->notes) ?: null,
            'is_custom' => false,
        ];
    }

    private function pickupLine(SchedulePickupPoint $point): string
    {
        $where = trim((string) $point->region_label) ?: trim((string) $point->pickup_location);
        $detail = trim((string) $point->pickup_location);
        $time = trim((string) $point->pickup_time);

        $line = $where;
        if ($detail !== '' && $detail !== $where) {
            $line .= " — {$detail}";
        }
        if ($time !== '') {
            $line .= " เวลา {$time} น.";
        }

        return $line;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function vehicle(TripSchedule $schedule): ?array
    {
        $vehicle = $schedule->vehicle;
        if (! $vehicle) {
            return null;
        }

        return [
            'name' => trim((string) $vehicle->name) ?: trim((string) $vehicle->type),
            'license_plate' => trim((string) $vehicle->license_plate) ?: null,
            'color' => trim((string) $vehicle->color) ?: null,
        ];
    }

    /**
     * ชื่อ-เบอร์คนขับของรอบ (อ่านจากสำเนาบนคันรถ ซึ่ง VehicleDriverService มิเรอร์
     * มาจากทะเบียนคนขับให้แล้ว) — สาธารณะเพราะข้อความสรุปทีมงานในห้องแชท
     * (TripChatTimelineService) ต้องใช้ชุดเดียวกับที่แอปแสดง
     *
     * @return array<string, mixed>|null
     */
    public function driver(TripSchedule $schedule): ?array
    {
        $vehicle = $schedule->vehicle;
        $name = trim((string) ($vehicle?->driver_name ?? ''));
        $phone = trim((string) ($vehicle?->driver_phone ?? ''));

        if ($name === '' && $phone === '') {
            return null;
        }

        return [
            'name' => $name !== '' ? $name : 'คนขับ',
            'phone' => $phone !== '' ? $phone : null,
            'photo' => $vehicle?->driver_photo ?: $vehicle?->driver?->photo,
        ];
    }

    /**
     * สตาฟที่ยังประจำรอบอยู่ (ที่ถูกปลดหลังจบทริปแล้วไม่นับ)
     *
     * สาธารณะด้วยเหตุผลเดียวกับ [driver()] — ห้องแชทสรุปรายชื่อทีมงานให้ลูกค้า
     * ก่อนเดินทาง และต้องเป็นรายชื่อชุดเดียวกับหน้าใบจอง
     *
     * @return array<int, array<string, mixed>>
     */
    public function staff(TripSchedule $schedule): array
    {
        return $schedule->activeStaff()
            ->get(['users.id', 'users.name', 'users.nickname', 'users.phone', 'users.avatar'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => trim((string) $u->nickname) ?: $u->name,
                'phone' => trim((string) $u->phone) ?: null,
                'avatar_url' => $u->avatar_url,
            ])
            ->values()
            ->all();
    }
}
