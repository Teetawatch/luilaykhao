<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\FcmToken;
use App\Models\LiveActivity;
use App\Models\ScheduleAnnouncement;
use App\Models\SchedulePickupPoint;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\Maps\VehicleEtaService;
use App\Support\MediaDisk;
use App\Support\SiteSettings;
use App\Support\ThaiDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "รถถึงใน 8 นาที" บนหน้าจอล็อก — ที่เดียวที่นิยามว่าตอนนี้ควรขึ้นว่าอะไร
 *
 * ตอนตี 4 ที่ยืนรอรถอยู่ข้างถนน ไม่มีใครเปิดแอป เขาปลดล็อกจอแล้วดู คลาสนี้แปลง
 * "รอบเดินทาง + ตำแหน่งรถล่าสุด + สถานะเช็คอิน" ให้เป็นข้อความบรรทัดเดียวที่
 * ตอบคำถามนั้น แล้วส่งไปให้ทั้งสองแพลตฟอร์มด้วย state ก้อนเดียวกัน:
 *
 *   iOS     → APNs ตรงเข้า Live Activity (ดู [ApnsLiveActivityService])
 *   Android → FCM data message ให้แอปวาด ongoing notification เอง
 *
 * เจตนาคือฝั่งแอปทั้งสองฝั่ง "ไม่ต้องคิดเอง" — ข้อความ ภาษาไทย ลำดับขั้น และ
 * เกณฑ์เวลาทั้งหมดอยู่ที่นี่ ที่เดียว ไม่งั้นวันหนึ่ง iOS กับ Android จะบอกเวลา
 * รถถึงไม่ตรงกัน ซึ่งแย่กว่าไม่บอกเลย
 */
class TripActivityService
{
    /** เข้าใกล้จุดรับกว่านี้ = ถือว่าถึงแล้ว (กม.) */
    private const ARRIVED_KM = 0.2;

    /** ETA ต่ำกว่านี้ = "กำลังจะถึง" (นาที) */
    private const ARRIVING_MINUTES = 5;

    /** ETA ต่ำกว่านี้ = "กำลังมา" — และเป็นช่วงที่แถบความคืบหน้าเริ่มขยับ (นาที) */
    private const APPROACHING_MINUTES = 30;

    /** ตำแหน่งรถที่เก่ากว่านี้ถือว่าใช้ไม่ได้ (นาที) */
    private const STALE_LOCATION_MINUTES = 20;

    /** Live Activity เริ่มแสดงล่วงหน้ากี่ชั่วโมงก่อนรถออก */
    private const START_BEFORE_HOURS = 18;

    /**
     * เพดานของ ActivityKit — การ์ดที่เปิดมาครบเท่านี้ถูกระบบหยุดรับอัปเดต (ชั่วโมง)
     */
    private const IOS_ACTIVE_HOURS = 8;

    /** เปิดใบใหม่แทนก่อนชนเพดานเท่านี้ (นาที) — เผื่อรอบซิงก์ที่พลาดไปสักสองสามรอบ */
    private const ROTATE_AFTER_MINUTES = 450;

    /** เปิดการ์ดให้เอง (push-to-start) ล่วงหน้ากี่ชั่วโมงก่อนเวลารถมารับ */
    private const PUSH_TO_START_HOURS = 6;

    /** ถึงเวลานี้แล้วยังไม่มีอะไรเปลี่ยน ก็ยิงซ้ำกันหน้าจอค้างข้อมูลเก่า (นาที) */
    private const HEARTBEAT_MINUTES = 20;

    /** หลังเช็คอินกี่นาทีจึงเลิกโชว์ "ขึ้นรถเรียบร้อยแล้ว" แล้วเดินตามกำหนดการต่อ */
    private const ONBOARD_MINUTES = 15;

    /**
     * หมุดที่ทีมงานกดล่าสุดเก่ากว่านี้ = ทีมงานเลิกกดไปแล้ว (ชั่วโมง)
     *
     * หลังจากนั้นเชื่อเวลาในกำหนดการแทน ไม่งั้นการ์ดแช่ "จุดถัดไป" อันเดิมไปทั้งวัน
     * ทั้งที่ทริปเดินผ่านไปหลายจุดแล้ว
     */
    private const TICK_TRUST_HOURS = 3;

    /** ประกาศจากทีมงานขึ้นการ์ดนานเท่านี้หลังโพสต์ (นาที) */
    private const ANNOUNCEMENT_MINUTES = 30;

    /**
     * ขั้นที่ประกาศแทรกได้ — ช่วงที่การ์ดไม่ได้กำลังบอกเวลารถถึงอยู่
     *
     * ตอนรถกำลังวิ่งมารับหรือกำลังพากลับ ตัวเลข ETA สำคัญกว่า และประกาศเองก็มี
     * push ของมันแยกอยู่แล้ว
     */
    private const ANNOUNCEMENT_STAGES = ['countdown', 'preparing', 'itinerary', 'trip_day'];

    /** เช็คอินแล้วอย่างน้อยเท่านี้ถึงเริ่มจับขากลับ — กันช่วงรถยังวนรับคนอื่นอยู่ (ชั่วโมง) */
    private const RETURN_AFTER_CHECKIN_HOURS = 3;

    /**
     * รถต้องเข้าใกล้จุดส่งติดกันสองช่วง ช่วงละอย่างน้อยเท่านี้ถึงนับว่ากำลังกลับ (กม.)
     *
     * ช่วงเดียวไม่พอ — เช้าวันกลับรถอาจขับไปจุดชมวิวที่บังเอิญอยู่ทางเดียวกับบ้าน
     */
    private const RETURN_CLOSING_KM = 2.0;

    /** ใกล้จุดส่งกว่านี้ = ส่งถึงแล้ว (กม.) */
    private const DROPOFF_KM = 0.5;

    /** ETA ขากลับต่ำกว่านี้ = ขั้น "ใกล้ถึงจุดส่ง" ที่ควรปลุกให้เก็บของ (นาที) */
    private const DROPOFF_SOON_MINUTES = 15;

    /** ส่งถึงแล้วโชว์ "ถึงจุดส่งแล้ว" ค้างไว้นานเท่านี้ แล้วค่อยปิดการ์ด (นาที) */
    private const DROPOFF_LINGER_MINUTES = 30;

    /** ไกลกว่านี้ถาม Google (ติดรถบนทางหลวงนับเส้นตรงไม่ได้) ใกล้กว่านี้คิดเอง (กม.) */
    private const RETURN_GOOGLE_MIN_KM = 15;

    /** ถาม Google ซ้ำทุกกี่วินาทีต่อหนึ่งจุดส่ง — ถามทุกนาทีคือเผาเงินเปล่า ๆ */
    private const RETURN_ETA_REFRESH_SECONDS = 600;

    /** ขั้นที่ควรทำให้เครื่องสั่น/เด้ง ไม่ใช่แค่เปลี่ยนตัวเลขเงียบ ๆ */
    private const ALERTING_STAGES = ['arriving', 'arrived', 'onboard', 'meetup', 'boarding', 'dropoff_soon', 'dropoff'];

    private const TIMEZONE = 'Asia/Bangkok';

    public function __construct(
        private ApnsLiveActivityService $apns,
        private FcmService $fcm,
        private TripProgressService $tripProgress,
    ) {}

    /**
     * ความคืบหน้ากำหนดการของรอบหนึ่ง จำไว้ตลอดการซิงก์รอบนั้น
     *
     * `syncSchedule()` วน stateFor ทีละใบจองของรอบเดียวกัน คำตอบของ
     * [TripProgressService] เหมือนกันหมด การไม่จำคือยิงคิวรีเดิม 20 ครั้งทุกนาที
     *
     * @var array<int, array<string, mixed>>
     */
    private array $progressCache = [];

    /** @var array<int, ScheduleAnnouncement|null> ประกาศล่าสุดต่อรอบ จำไว้ตลอดการซิงก์รอบนั้น */
    private array $announcementCache = [];

    /** @var array<int, array{mid: array|null, old: array|null}> พิกัดย้อนหลังต่อคันรถ */
    private array $trackCache = [];

    /**
     * ใบจองที่ "ควรมี Live Activity อยู่ตอนนี้" — ตั้งแต่ 18 ชม. ก่อนรถออก จนถึง
     * สิ้นวันกลับ นอกช่วงนี้ไม่มีอะไรน่าแสดงบนหน้าจอล็อก
     */
    public function isWithinWindow(TripSchedule $schedule): bool
    {
        $departsAt = $schedule->effectiveDepartsAt();
        if (! $departsAt) {
            return false;
        }

        $end = ($schedule->return_date ?? $schedule->departure_date)?->copy()->endOfDay();

        return $this->nowThai()->betweenIncluded(
            $departsAt->copy()->subHours(self::START_BEFORE_HOURS),
            $end ?? $departsAt->copy()->endOfDay(),
        );
    }

    /**
     * "ตอนนี้" ในรูปแบบเดียวกับที่ `departs_at` ถูกเก็บ
     *
     * departs_at เก็บเป็นตัวเลขนาฬิกาไทยตรง ๆ (ไม่เคยถูกแปลง) แต่ timezone ของแอป
     * เป็น UTC มันจึงถูกอ่านกลับมาเป็นเวลา UTC ที่มีตัวเลขของไทย การเทียบกับ now()
     * หรือ now('Asia/Bangkok') — ซึ่งเป็น "ขณะเดียวกัน" ทั้งคู่ — จะคลาดไป 7 ชั่วโมง
     * เสมอ ทางที่ถูกคือเอาตัวเลขนาฬิกาไทยมาสวมกรอบเดียวกัน แล้วค่อยเทียบ
     */
    private function nowThai(): Carbon
    {
        return Carbon::parse(now(self::TIMEZONE)->format('Y-m-d H:i:s'));
    }

    /**
     * state ปัจจุบันของใบจองหนึ่งใบ — โครงเดียวกับ ContentState ฝั่ง Swift
     *
     * คืน null เมื่อใบจองนี้ไม่ควรมี Live Activity แล้ว (ยกเลิก/จบทริป) ซึ่งผู้เรียก
     * ต้องแปลว่า "ปิด Activity" ไม่ใช่ "ข้ามไป"
     *
     * @return array<string, mixed>|null
     */
    public function stateFor(Booking $booking, ?int $userId = null): ?array
    {
        $schedule = $booking->schedule;
        if (! $schedule || ! in_array($booking->status, ['confirmed', 'pending'], true)) {
            return null;
        }
        if (in_array($schedule->status, ['cancelled'], true)) {
            return null;
        }
        if (! $this->isWithinWindow($schedule)) {
            return null;
        }

        // การ์ดเป็นของ "คน" ไม่ใช่ของใบจอง — จุดรับและการขึ้นรถของคนที่ถือเครื่องนี้
        // (ดู TripActivityViewer) ทั้งกลุ่มแจ้งไม่ไป = ไม่มีการ์ด
        $viewer = TripActivityViewer::resolve($booking, $userId, app(PickupArrivalService::class));
        if (! $viewer) {
            return null;
        }

        $departsAt = $schedule->effectiveDepartsAt();

        // รอบที่บินไปไม่มีทั้งจุดรับและ GPS รถ ขั้นที่ขับเคลื่อนด้วยตำแหน่งรถ
        // (enroute/approaching/arriving/arrived) จึงไม่มีทางเกิดขึ้นเลย การ์ดเคย
        // ค้างอยู่ที่ "อีก N ชั่วโมงออกเดินทาง · จุดรับของคุณ" แล้วกระโดดไป onboard
        // — ไทม์ไลน์ของสนามบินเดินด้วยเวลานัดพบกับเวลาเครื่องออกแทน
        if ($schedule->isFlight()) {
            return $this->flightStateFor($booking, $viewer, $schedule, $departsAt);
        }

        $pickupCoords = $viewer->coords();
        $pickupName = $viewer->name();
        $location = $schedule->vehicle_id ? $this->vehicleLocation((int) $schedule->vehicle_id) : null;

        $etaMinutes = null;
        $distanceKm = null;

        if ($location && $pickupCoords) {
            $distanceKm = $this->distanceKm(
                (float) $location['latitude'],
                (float) $location['longitude'],
                $pickupCoords['lat'],
                $pickupCoords['lng'],
            );
            // ETA ร่วมจาก Google ก่อน — การ์ดหน้าจอล็อกกับจอติดตามรถต้องพูดเลขเดียวกัน
            $etaMinutes = $this->sharedEtaMinutes($booking, $viewer)
                ?? $this->etaMinutes(
                    $distanceKm,
                    isset($location['speed']) ? (float) $location['speed'] : null,
                );
        }

        // เวลาของ "คนนี้" คือเวลารถมารับที่จุดที่เขาเลือก ไม่ใช่เวลารถออกจากต้นทาง —
        // คนที่ขึ้นจุดที่สามอาจต้องยืนรอช้ากว่าเวลารถออกเป็นชั่วโมง การ์ดจึงนับถอยหลัง
        // และพิมพ์เวลาของจุดรับเมื่อจุดนั้นตั้ง pickup_time ไว้ ไม่มีก็ถอยไปใช้เวลารถออก
        $pickupAt = $viewer->point?->pickupAt($schedule);
        $anchor = $pickupAt ?? $departsAt;
        $departTime = $this->departTimeLabel($schedule);
        $pickupTime = $pickupAt ? $pickupAt->format('H:i').' น.' : null;
        $timeKnown = $pickupTime !== null || $departTime !== null;

        $stage = $this->stage($viewer, $schedule, $anchor, $timeKnown, $distanceKm, $etaMinutes);
        $copy = $this->copyFor($stage, $anchor, $departTime, $pickupTime, $etaMinutes, $pickupName, $booking, $viewer);
        $progress = $this->progress($stage, $anchor, $etaMinutes);

        if ($stage === 'onboard') {
            // ส่งถึงจุดส่งมาพักหนึ่งแล้ว — ทริปจบจริง เก็บการ์ดออกจากหน้าจอล็อก
            // แทนที่จะแช่ "ถึงจุดส่งแล้ว" ไว้จนเที่ยงคืน
            if ($this->returnFinished($booking, $viewer)) {
                return null;
            }

            // ETA ไปจุดรับไม่มีความหมายแล้วหลังขึ้นรถ อย่าให้ Dynamic Island โชว์เลขค้าง
            [$etaMinutes, $distanceKm] = [null, null];

            if ($leg = $this->afterBoarding($booking, $viewer, $schedule, $pickupCoords, $pickupName, $location)) {
                [$stage, $copy, $progress, $etaMinutes, $distanceKm] = [
                    $leg['stage'], $leg, $leg['progress'], $leg['eta_minutes'] ?? null, $leg['distance_km'] ?? null,
                ];
            }
        }

        return $this->withAnnouncement($schedule, [
            'stage' => $stage,
            'headline' => $copy['headline'],
            'detail' => $copy['detail'],
            'eta_minutes' => $etaMinutes,
            'distance_km' => $distanceKm !== null ? round($distanceKm, 2) : null,
            'progress' => $progress,
            'departs_at' => $departsAt?->toIso8601String(),
            // เวลารถมารับที่จุดของคนนี้ — null เมื่อจุดไม่ได้ตั้งเวลา (ใช้ตัดสินช่วง push-to-start)
            'pickup_at' => $pickupAt?->toIso8601String(),
            'pickup_name' => $pickupName,
            'trip_title' => $schedule->trip?->title ?? 'ทริปของคุณ',
            'booking_ref' => $booking->booking_ref,
            'schedule_id' => (int) $schedule->id,
            'vehicle_label' => $this->vehicleLabel($schedule),
            ...$this->vehicleCard($schedule, $stage, $etaMinutes),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * state ของรอบที่บินไป — ไทม์ไลน์สนามบินแทนไทม์ไลน์รถตู้
     *
     * ขั้น: countdown → preparing → meetup → boarding → onboard
     * เดินด้วย "เวลานัดพบ" (M) กับ "เวลาเครื่องออก" (D) ล้วน ๆ ไม่มี GPS เข้ามาเกี่ยว
     * ซึ่งเหมาะกว่า เพราะคำถามของคนที่กำลังจะบินไม่ใช่ "รถถึงไหนแล้ว" แต่เป็น
     * "ต้องไปถึงสนามบินกี่โมง" กับ "เครื่องออกกี่โมง"
     *
     * ใช้คีย์ชุดเดิมทุกคี่ย์ (รวม pickup_name/vehicle_label) เพื่อให้ทั้ง widget ฝั่ง
     * iOS และ ongoing notification ฝั่ง Android วาดได้เลยโดยไม่ต้องรู้ว่านี่คือ
     * รอบบิน — pickup_name ใส่จุดนัดพบ, vehicle_label ใส่เที่ยวบิน
     *
     * @return array<string, mixed>
     */
    private function flightStateFor(Booking $booking, TripActivityViewer $viewer, TripSchedule $schedule, ?Carbon $departsAt): array
    {
        $meetingAt = $schedule->meetingAt();
        $meetingPoint = trim((string) $schedule->meeting_point) ?: null;
        $flightLabel = $this->flightLabel($schedule);
        $departTime = $this->departTimeLabel($schedule);

        $stage = $this->flightStage($viewer, $meetingAt, $departsAt, $departTime !== null);

        // นับถอยหลังไปหา "สิ่งที่ต้องทำอันถัดไป" — ก่อนเจอทีมงานคือเวลานัดพบ
        // หลังจากนั้นคือเวลาเครื่องออก ตัวเลขบนการ์ดจึงเป็นตัวเลขที่ยังต้องรออยู่จริง
        $knownDepartsAt = $departTime !== null ? $departsAt : null;
        $target = in_array($stage, ['boarding', 'onboard'], true)
            ? $knownDepartsAt
            : ($meetingAt ?? $knownDepartsAt);
        $etaMinutes = $target
            ? max(0, (int) ceil($this->nowThai()->diffInMinutes($target, false)))
            : null;

        $copy = $this->flightCopy($stage, $meetingAt, $departsAt, $departTime, $etaMinutes, $meetingPoint, $flightLabel);
        $progress = $this->flightProgress($stage, $meetingAt);

        // ลงเครื่องแล้วกำหนดการก็เดินต่อเหมือนกัน — ไทม์ไลน์สนามบินจบที่ขึ้นเครื่อง
        // ไม่ใช่จบที่ทริป (ไม่มีรถตู้พากลับจุดส่ง ขากลับจึงไม่มีให้ตาม)
        if ($stage === 'onboard' && ($leg = $this->afterBoarding($booking, $viewer, $schedule, null, null, null))) {
            [$stage, $copy, $progress, $etaMinutes] = [$leg['stage'], $leg, $leg['progress'], null];
        }

        return $this->withAnnouncement($schedule, [
            'stage' => $stage,
            'headline' => $copy['headline'],
            'detail' => $copy['detail'],
            'eta_minutes' => $etaMinutes,
            'distance_km' => null,
            'progress' => $progress,
            'departs_at' => $departsAt?->toIso8601String(),
            'pickup_name' => $meetingPoint,
            'trip_title' => $schedule->trip?->title ?? 'ทริปของคุณ',
            'booking_ref' => $booking->booking_ref,
            'schedule_id' => (int) $schedule->id,
            'vehicle_label' => $flightLabel,
            ...self::NO_VEHICLE_CARD,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** เจอทีมงานเมื่อไหร่ / ขึ้นเครื่องเมื่อไหร่ — เกณฑ์เวลาของรอบบิน (นาที) */
    private const FLIGHT_MEETUP_MINUTES = 30;

    private const FLIGHT_BOARDING_MINUTES = 60;

    private const FLIGHT_PREPARING_HOURS = 6;

    private function flightStage(TripActivityViewer $viewer, ?Carbon $meetingAt, ?Carbon $departsAt, bool $timeKnown): string
    {
        if ($viewer->boarded) {
            return 'onboard';
        }

        $now = $this->nowThai();

        if ($timeKnown && $departsAt && $now->gte($departsAt->copy()->subMinutes(self::FLIGHT_BOARDING_MINUTES))) {
            return 'boarding';
        }

        // ไม่ได้ตั้งเวลานัดพบไว้ ก็ยังบอกอะไรได้อยู่จากเวลาเครื่องออก อย่าเงียบ —
        // แต่เฉพาะเวลาที่ถูกกรอกไว้จริง เที่ยงคืนที่ถูกเติมแทนเวลาที่ยังไม่รู้จะทำให้
        // "ขึ้นเครื่อง" เด้งตั้งแต่ห้าทุ่มของคืนก่อน
        $anchor = $meetingAt ?? ($timeKnown ? $departsAt : null);
        if (! $anchor) {
            return $this->departureDayReached($departsAt) ? 'preparing' : 'countdown';
        }

        if ($now->gte($anchor->copy()->subMinutes(self::FLIGHT_MEETUP_MINUTES))) {
            return 'meetup';
        }

        if ($now->gte($anchor->copy()->subHours(self::FLIGHT_PREPARING_HOURS))) {
            return 'preparing';
        }

        return 'countdown';
    }

    /**
     * @return array{headline: string, detail: string}
     */
    private function flightCopy(
        string $stage,
        ?Carbon $meetingAt,
        ?Carbon $departsAt,
        ?string $departTime,
        ?int $etaMinutes,
        ?string $meetingPoint,
        ?string $flightLabel,
    ): array {
        $place = $meetingPoint ?: 'จุดนัดพบที่สนามบิน';
        $meetingTime = $meetingAt ? $meetingAt->format('H:i').' น.' : null;
        $flight = $flightLabel ? "เที่ยวบิน $flightLabel" : 'เที่ยวบินของคุณ';

        return match ($stage) {
            'onboard' => [
                'headline' => 'เช็คอินกับทีมงานแล้ว',
                'detail' => $departTime
                    ? "$flight ออก $departTime · เดินทางปลอดภัย ✈️"
                    : 'เดินทางปลอดภัย แล้วเจอกันที่ปลายทาง ✈️',
            ],
            'boarding' => [
                'headline' => $departTime ? "เครื่องออก $departTime" : 'ใกล้เวลาขึ้นเครื่อง',
                'detail' => $etaMinutes !== null && $etaMinutes > 0
                    ? "อีก {$etaMinutes} นาทีเครื่องออก · ไปที่ประตูขึ้นเครื่องได้เลย"
                    : 'ไปที่ประตูขึ้นเครื่องได้เลย',
            ],
            'meetup' => [
                'headline' => $etaMinutes !== null && $etaMinutes > 0
                    ? "อีก {$etaMinutes} นาทีเจอทีมงาน"
                    : 'ถึงเวลาเจอทีมงานแล้ว',
                'detail' => "ทีมงานรออยู่ที่$place".($departTime ? " · เครื่องออก $departTime" : ''),
            ],
            'preparing' => [
                'headline' => $meetingTime ? "เจอกัน $meetingTime" : 'ใกล้ถึงเวลาเดินทาง',
                'detail' => "ไปที่$place".($departTime ? " · เครื่องออก $departTime" : ''),
            ],
            default => [
                'headline' => $this->countdownLabel(
                    $meetingAt ?? $departsAt,
                    $meetingTime !== null || $departTime !== null,
                ),
                'detail' => $meetingTime
                    ? "เจอกัน $meetingTime ที่$place"
                    : $place,
            ],
        };
    }

    /**
     * แถบความคืบหน้าของรอบบิน — ไม่มี ETA รถให้อ้าง จึงวัดจาก "ใกล้เวลานัดพบแค่ไหน"
     */
    private function flightProgress(string $stage, ?Carbon $meetingAt): float
    {
        return match ($stage) {
            'onboard' => 1.0,
            'boarding' => 0.9,
            'meetup' => $meetingAt
                ? round(
                    0.5 + 0.35 * (1 - min(
                        max(0, $this->nowThai()->diffInMinutes($meetingAt, false)) / self::FLIGHT_MEETUP_MINUTES,
                        1,
                    )),
                    2,
                )
                : 0.6,
            'preparing' => 0.25,
            default => 0.0,
        };
    }

    /** "TG319" หรือ "Thai Airways TG319" — ป้ายเที่ยวบินขาไป */
    private function flightLabel(TripSchedule $schedule): ?string
    {
        $outbound = $schedule->flightLegs()['outbound'][0] ?? null;
        if (! $outbound) {
            return null;
        }

        $label = trim(($outbound['airline'] ?? '').' '.($outbound['flight_no'] ?? ''));

        return $label !== '' ? $label : null;
    }

    /**
     * ค่าคงที่ตลอดอายุ Activity — ฝั่ง Swift เก็บไว้ใน Attributes ไม่ใช่ ContentState
     * เพราะมันไม่เปลี่ยนแล้วไม่ควรกินแบนด์วิดท์ทุกครั้งที่ยิงอัปเดต
     *
     * @return array<string, mixed>
     */
    public function attributesFor(Booking $booking): array
    {
        return [
            'bookingRef' => (string) $booking->booking_ref,
            'tripTitle' => $booking->schedule?->trip?->title ?? 'ทริปของคุณ',
            'scheduleId' => (int) $booking->schedule_id,
        ];
    }

    /**
     * ลงทะเบียน Activity ที่แอปเพิ่งเปิดบนเครื่องหนึ่ง
     *
     * เครื่องเดิมเปิด Activity ใหม่ = token ใหม่เสมอ ของเก่าจึงถูกปิดทิ้งเพื่อไม่ให้
     * ยิงไปหา Activity ที่ตายแล้วทุกนาทีจนกว่า APNs จะบอกว่า token เสีย
     *
     * แอปฝาก token เดิมซ้ำทุกครั้งที่เปิดขึ้นมาใหม่ — `started_at` ต้องไม่ถูกรีเซ็ต
     * ตอนนั้น เพราะมันคือนาฬิกาที่นับเพดาน 8 ชั่วโมงของ iOS ([outlived]/[rotate])
     */
    public function register(Booking $booking, int $userId, string $pushToken, ?string $activityId, string $platform = 'ios'): LiveActivity
    {
        LiveActivity::live()
            ->where('booking_id', $booking->id)
            ->where('user_id', $userId)
            ->where('push_token', '!=', $pushToken)
            ->update(['ended_at' => now()]);

        // การ์ดที่เซิร์ฟเวอร์สั่งเปิดไว้ฝาก token มาแล้ว — จากนี้แถวนี้คือความจริง
        Cache::forget($this->pendingKey((int) $booking->id, $userId));

        $activity = LiveActivity::firstOrNew(['push_token' => $pushToken]);
        $sameCard = $activity->exists
            && $activity->ended_at === null
            && (int) $activity->booking_id === (int) $booking->id
            && $activity->started_at !== null;

        $activity->fill([
            'user_id' => $userId,
            'booking_id' => $booking->id,
            'schedule_id' => $booking->schedule_id,
            'platform' => $platform,
            'activity_id' => $activityId ?? $activity->activity_id,
            'state' => $this->stateFor($booking, $userId),
            'stage' => $sameCard ? $activity->stage : null,
            'started_at' => $sameCard ? $activity->started_at : now(),
            'ended_at' => null,
        ])->save();

        return $activity;
    }

    /**
     * ผู้ใช้ทุกคนที่ควรมีการ์ดของใบจองนี้ — เจ้าของ กับเพื่อนที่ตอบรับคำเชิญแล้ว
     * (เกณฑ์เดียวกับที่ LiveActivityController ให้สิทธิ์ดูการ์ด)
     *
     * @return array<int, int>
     */
    public function viewerIds(Booking $booking): array
    {
        $members = $booking->relationLoaded('members')
            ? $booking->members
            : $booking->members()->get();

        return collect([$booking->user_id])
            ->merge($members->where('status', BookingMember::STATUS_ACTIVE)->pluck('user_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ผู้ใช้ปัดการ์ดทิ้งเอง — เคารพไปจนจบวัน: ไม่เปิดใบใหม่ให้เอง ไม่ต่ออายุใบเดิม
     * (วันถัดไปของทริปหลายวันคือเรื่องใหม่ เช่นขากลับ จึงเปิดให้ได้อีก)
     */
    public function markDismissed(int $bookingId, int $userId): void
    {
        $now = $this->nowThai();

        Cache::put($this->dismissedKey($bookingId, $userId), true, max(60, (int) $now->diffInSeconds($now->copy()->endOfDay())));
    }

    private function wasDismissed(int $bookingId, int $userId): bool
    {
        return Cache::has($this->dismissedKey($bookingId, $userId));
    }

    private function dismissedKey(int $bookingId, int $userId): string
    {
        return "trip_activity_dismissed:{$bookingId}:{$userId}";
    }

    /**
     * ซิงก์ทุกการ์ดของรอบเดินทางหนึ่งรอบ — นี่คือสิ่งที่ scheduler เรียกทุกนาที
     *
     * เดินทีละใบจอง ไม่ใช่ทีละแถวใน live_activities: การ์ด Android ไม่มีแถว
     * (มันคือ ongoing notification ที่แอปวาดเอง) เดิมจึงได้อัปเดตเฉพาะใบจองที่
     * บังเอิญมีการ์ด iOS เปิดอยู่ด้วย ETA บน Android เลยไม่เคยขยับเอง
     *
     * @return int จำนวนที่ยิงออกไปจริง
     */
    public function syncSchedule(TripSchedule $schedule): int
    {
        $liveBookingIds = LiveActivity::live()
            ->where('schedule_id', $schedule->id)
            ->pluck('booking_id');

        $bookings = Booking::with($this->relations())
            ->where('schedule_id', $schedule->id)
            ->where(fn ($q) => $q
                ->whereIn('status', ['confirmed', 'pending'])
                ->orWhereIn('id', $liveBookingIds))
            ->get();

        $androidUsers = $this->androidUserIds(
            $bookings->flatMap(fn (Booking $b) => $this->viewerIds($b))->unique()->values()->all()
        );

        $pushed = 0;

        foreach ($bookings as $booking) {
            if ($this->sync($booking, androidUsers: $androidUsers)) {
                $pushed++;
            }
        }

        LiveActivity::live()
            ->where('schedule_id', $schedule->id)
            ->whereNotIn('booking_id', $bookings->pluck('id'))
            ->update(['ended_at' => now()]);

        return $pushed;
    }

    /** ความสัมพันธ์ที่ stateFor ต้องใช้ — โหลดทีเดียวทั้งรอบ ไม่ใช่ทีละใบ */
    public function relations(): array
    {
        return [
            'schedule.trip', 'schedule.vehicle', 'schedule.pickupPoints', 'pickupPoint',
            'passengers:'.PickupArrivalService::PASSENGER_PICKUP_COLUMNS,
            'members',
        ];
    }

    /**
     * ซิงก์ใบจองหนึ่งใบ — เรียกได้จากทั้ง scheduler และจากเหตุการณ์ที่เกิดทันที
     * (เช่นสตาฟสแกนเช็คอิน ซึ่งต้องเห็นบนหน้าจอล็อกเดี๋ยวนั้น ไม่ใช่รออีกนาที)
     *
     * การ์ดแต่ละใบได้ state ของคนที่ถือเครื่อง ([stateFor] กับ user id ของแถวนั้น)
     *
     * @param  array<int, int>|null  $androidUsers  ผู้ใช้ที่มีเครื่อง Android (null = ถามเอง)
     */
    public function sync(Booking $booking, ?LiveActivity $only = null, ?array $androidUsers = null): bool
    {
        $states = [];
        $stateOf = function (?int $userId) use (&$states, $booking): ?array {
            $key = $userId ?? 0;

            return array_key_exists($key, $states)
                ? $states[$key]
                : $states[$key] = $this->stateFor($booking, $userId);
        };

        $activities = $only
            ? collect([$only])
            : LiveActivity::live()->where('booking_id', $booking->id)->get();

        $pushed = false;

        foreach ($activities as $activity) {
            $state = $stateOf($activity->user_id ? (int) $activity->user_id : null);

            if ($state === null) {
                $this->apns->end($activity, $this->endState($activity));
                $pushed = true;

                continue;
            }

            // iOS หยุดรับอัปเดตการ์ดที่อายุครบ 8 ชม. แล้ว — ยิงไปก็ไม่ขึ้น
            if ($this->outlived($activity)) {
                $activity->forceFill(['ended_at' => now()])->save();

                continue;
            }

            if ($this->rotate($booking, $activity, $state)) {
                $pushed = true;

                continue;
            }

            if (! $this->shouldPush($activity, $state)) {
                continue;
            }

            $alert = $this->alertFor($activity->stage, $state);
            if ($this->apns->update($activity, $this->contentState($state), $alert)) {
                $pushed = true;
            }

            // เก็บ state ไว้แม้ APNs ล้ม เพื่อไม่ให้รอบถัดไปยิงซ้ำด้วย alert เดิม
            // ซ้ำ ๆ ทุกนาทีเวลาปลายทางมีปัญหา
            $activity->forceFill([
                'state' => $state,
                'stage' => $state['stage'],
            ])->save();
        }

        // ซิงก์การ์ดใบเดียว (แอปเพิ่งฝาก token) ไม่ต้องไปแตะเครื่อง Android ของใคร
        if ($only !== null) {
            return $pushed;
        }

        $viewers = $this->viewerIds($booking);
        $androidUsers ??= $this->androidUserIds($viewers);

        foreach (array_intersect($viewers, $androidUsers) as $userId) {
            if ($this->pushToAndroid($booking, $userId, $stateOf($userId))) {
                $pushed = true;
            }
        }

        return $pushed;
    }

    /**
     * เปิด Live Activity จากฝั่งเซิร์ฟเวอร์ให้เครื่องที่ยังไม่มี (iOS 17.2+)
     *
     * นี่คือส่วนที่ทำให้ "ตื่นมาแล้วมันอยู่บนจอแล้ว" เป็นจริง — ลูกค้าที่จองไว้เมื่อ
     * เดือนที่แล้วแล้วไม่ได้เปิดแอปอีกเลย ก็ยังได้เห็น ทั้งเจ้าของและเพื่อนในใบจอง
     *
     * เปิดให้เองเฉพาะช่วงที่การ์ดมีค่าจริง ([startPhase]) ช่วงละครั้งต่อคน — ช่วง
     * รอรถมารับ กับช่วงขากลับ (ทริปหลายวันการ์ดของเช้าวันแรกหมดอายุไปนานแล้ว)
     *
     * @return int จำนวนเครื่องที่สั่งเปิดไป
     */
    public function pushToStart(Booking $booking): int
    {
        $started = 0;

        foreach ($this->viewerIds($booking) as $userId) {
            if ($this->wasDismissed((int) $booking->id, $userId)) {
                continue;
            }

            // เพิ่งสั่งเปิดไป แต่เครื่องยังไม่ส่ง token กลับมา (แอปยังไม่ได้ตื่น) — ถ้า
            // ไม่รอ นาทีถัดไปจะเปิดซ้อนอีกใบ
            if (Cache::has($this->pendingKey((int) $booking->id, $userId))) {
                continue;
            }

            // มีอยู่แล้วบนเครื่องไหนก็ไม่ต้องเปิดซ้ำ — ปล่อยให้ sync() ดูแลต่อ
            $hasCard = LiveActivity::live()
                ->where('booking_id', $booking->id)
                ->where('user_id', $userId)
                ->exists();
            if ($hasCard) {
                continue;
            }

            $tokens = $this->startTokens($userId);
            if ($tokens->isEmpty()) {
                continue;
            }

            $state = $this->stateFor($booking, $userId);
            $phase = $state ? $this->startPhase($state) : null;
            if ($phase === null) {
                continue;
            }

            // push-to-start ที่ไม่สำเร็จ (ผู้ใช้ปิด Live Activity ในตั้งค่า) จะไม่มีวัน
            // สำเร็จในรอบถัดไปเช่นกัน — ลองครั้งเดียวต่อช่วง
            $key = "trip_activity_started:{$booking->id}:{$userId}:{$phase}";
            if (Cache::has($key)) {
                continue;
            }
            Cache::put($key, true, now()->addHours(12));

            $started += $this->startOn($booking, $userId, $tokens, $state);
        }

        return $started;
    }

    /**
     * สั่งเปิดการ์ดบนทุกเครื่องของผู้ใช้คนหนึ่ง แล้วจำไว้ว่า "มีการ์ดที่ยังไม่ได้ฝาก
     * token" อยู่ตลอดอายุของมัน — การ์ดที่เปิดจากเซิร์ฟเวอร์จะมีแถวใน live_activities
     * ก็ต่อเมื่อแอปตื่นมาส่ง token ให้ ซึ่งอาจไม่เกิดเลย
     *
     * @param  Collection<int, string>  $tokens
     */
    private function startOn(Booking $booking, int $userId, $tokens, array $state): int
    {
        $started = 0;

        foreach ($tokens as $token) {
            if ($this->apns->start($token, $this->attributesFor($booking), $this->contentState($state))) {
                $started++;
            }
        }

        if ($started > 0) {
            Cache::put($this->pendingKey((int) $booking->id, $userId), true, now()->addHours(self::IOS_ACTIVE_HOURS));
        }

        return $started;
    }

    private function pendingKey(int $bookingId, int $userId): string
    {
        return "trip_activity_pending:{$bookingId}:{$userId}";
    }

    /**
     * แอป iOS ควรเปิดการ์ดตอนนี้ไหม (ลูกค้าเปิดแอปเอง)
     *
     * การ์ดของ ActivityKit อยู่ได้ 8 ชม. — เปิดตอนสองทุ่มคืนก่อนเดินทาง = การ์ดหยุด
     * อัปเดตตีสี่ ตอนรถเพิ่งออกพอดี เครื่องที่ต่ำกว่า iOS 17.2 ต่ออายุไม่ได้ ([rotate])
     * จึงรอให้ใกล้เวลารถมารับก่อน ส่วนช่วงหลังขึ้นรถเปิดได้เสมอ
     */
    public function shouldOpenOnIos(array $state): bool
    {
        return $state['stage'] !== 'countdown' || $this->startPhase($state) !== null;
    }

    /**
     * ช่วงที่ควรเปิดการ์ดให้เองโดยไม่มีใครขอ — null = ตอนนี้ไม่ใช่
     *
     *   pickup  ภายใน 6 ชม. ก่อนเวลารถมารับ (หรือรถออก) จนถึงขึ้นรถ
     *   return  รถกำลังพากลับจุดส่ง
     */
    private function startPhase(array $state): ?string
    {
        $stage = $state['stage'];

        if (in_array($stage, ['returning', 'dropoff_soon'], true)) {
            return 'return';
        }

        if (in_array($stage, ['preparing', 'enroute', 'approaching', 'arriving', 'arrived', 'meetup', 'boarding'], true)) {
            return 'pickup';
        }

        if ($stage === 'countdown') {
            $anchor = $state['pickup_at'] ?? $state['departs_at'] ?? null;

            return $anchor && $this->nowThai()->diffInHours(Carbon::parse($anchor), false) <= self::PUSH_TO_START_HOURS
                ? 'pickup'
                : null;
        }

        return null;
    }

    /**
     * การ์ด iOS ใบนี้เลยเพดาน 8 ชั่วโมงของ ActivityKit แล้ว — ระบบหยุดรับอัปเดต
     * (ค้างบนจอล็อกต่ออีกราว 4 ชม. ด้วยข้อความสุดท้าย) เซิร์ฟเวอร์ต้องเลิกนับว่า
     * มันยังเปิดอยู่ ไม่งั้น [pushToStart] จะไม่มีวันเปิดใบใหม่ให้
     */
    private function outlived(LiveActivity $activity): bool
    {
        return $activity->platform === 'ios'
            && $activity->started_at !== null
            && $activity->started_at->lte(now()->subHours(self::IOS_ACTIVE_HOURS));
    }

    /**
     * ต่ออายุการ์ดก่อนชนเพดาน 8 ชม. — ActivityKit ไม่มี API ต่ออายุ แต่เปิดใบใหม่
     * ด้วย push-to-start แล้วปิดใบเก่าทันทีได้ ลูกค้าเห็นการ์ดใบเดิมเดินต่อ
     *
     * คนที่ไม่มี start token (iOS ต่ำกว่า 17.2) ต่ออายุไม่ได้ ปล่อยให้แอปเปิดใบใหม่
     * ตอนเขากลับเข้าแอป ([outlived] ทำให้เซิร์ฟเวอร์ยอมรับใบนั้น)
     */
    private function rotate(Booking $booking, LiveActivity $activity, array $state): bool
    {
        if ($activity->platform !== 'ios'
            || $activity->started_at === null
            || $activity->started_at->gt(now()->subMinutes(self::ROTATE_AFTER_MINUTES))
            || ! $activity->user_id
            || $this->wasDismissed((int) $booking->id, (int) $activity->user_id)
        ) {
            return false;
        }

        $tokens = $this->startTokens((int) $activity->user_id);
        if ($tokens->isEmpty() || $this->startOn($booking, (int) $activity->user_id, $tokens, $state) === 0) {
            return false;
        }

        // ปิดใบเก่าแบบไม่มีคำว่า "จบการเดินทาง" — ข้อความเดียวกับใบใหม่ แล้วหายไปเลย
        $this->apns->end($activity, $this->contentState($state), dismissAfterMinutes: 0);

        return true;
    }

    /** @return Collection<int, string> */
    private function startTokens(int $userId)
    {
        return FcmToken::where('user_id', $userId)
            ->where('is_active', true)
            ->whereNotNull('live_activity_start_token')
            ->pluck('live_activity_start_token')
            ->unique()
            ->values();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function androidUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return FcmToken::whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->where('platform', 'android')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * ปิดทุก Activity ของใบจอง — ใช้ตอนยกเลิกการจอง/จบทริป และตอนผู้ใช้กดปิดเอง
     */
    public function end(Booking $booking, ?string $reason = null): void
    {
        $activities = LiveActivity::live()->where('booking_id', $booking->id)->get();

        foreach ($activities as $activity) {
            $this->apns->end($activity, $this->endState($activity, $reason));
        }

        foreach (array_intersect($this->viewerIds($booking), $this->androidUserIds($this->viewerIds($booking))) as $userId) {
            $this->pushToAndroid($booking, $userId, null, force: true);
        }
    }

    /**
     * เก็บการ์ด Android ของรอบที่จบไปแล้ว — เฉพาะเครื่องที่เซิร์ฟเวอร์เคยส่งการ์ดไป
     * และยังไม่เคยสั่งเก็บ ([pushToAndroid] จำไว้) รอบที่หลุดออกจากชุด "กำลังเดินทาง"
     * ตอนเที่ยงคืนจะไม่มีใครแตะมันอีก ถ้าไม่เก็บตรงนี้ การ์ดค้างบนแถบแจ้งเตือนไปเรื่อย ๆ
     */
    public function retireAndroid(Booking $booking): void
    {
        $viewers = $this->viewerIds($booking);

        foreach (array_intersect($viewers, $this->androidUserIds($viewers)) as $userId) {
            $this->pushToAndroid($booking, $userId, null, retire: true);
        }
    }

    /**
     * ปิดการ์ดของผู้ใช้คนเดียวบนใบจองนี้ — คนที่ส่งต่อที่นั่งให้คนอื่นไปแล้วไม่ควร
     * เห็นการ์ดนับถอยหลังไปยังทริปที่ตัวเองไม่ได้ไป ส่วนคนอื่นในใบเดียวกันยังไปต่อ
     */
    public function endForUser(Booking $booking, int $userId, ?string $reason = null): void
    {
        $activities = LiveActivity::live()
            ->where('booking_id', $booking->id)
            ->where('user_id', $userId)
            ->get();

        foreach ($activities as $activity) {
            $this->apns->end($activity, $this->endState($activity, $reason), dismissAfterMinutes: 0);
        }

        if ($this->androidUserIds([$userId]) !== []) {
            $this->pushToAndroid($booking, $userId, null, force: true);
        }
    }

    /**
     * Android ไม่มี ActivityKit — ส่ง state ก้อนเดิมไปเป็น data message แล้วให้แอป
     * วาด ongoing notification เอง (state === null แปลว่า "เก็บการ์ดออก")
     *
     * ไม่มีแถวใน live_activities ให้จำว่าส่งอะไรไปแล้ว จึงจำใน cache แทน — ยิงเมื่อ
     * ข้อความเปลี่ยน หรือเงียบนานเกิน heartbeat เหมือนฝั่ง iOS ([shouldPush])
     */
    private function pushToAndroid(Booking $booking, int $userId, ?array $state, bool $force = false, bool $retire = false): bool
    {
        $key = "trip_activity_android:{$booking->id}:{$userId}";
        $fingerprint = $state === null
            ? 'end'
            : md5(json_encode([
                $state['stage'], $state['eta_minutes'], $state['headline'], $state['detail'],
                $state['vehicle_summary'] ?? null, $state['driver_photo_url'] ?? null,
            ], JSON_UNESCAPED_UNICODE));

        $last = Cache::get($key);
        $fresh = is_array($last)
            && $last['fp'] === $fingerprint
            && ($fingerprint === 'end' || $last['at'] > now()->subMinutes(self::HEARTBEAT_MINUTES)->timestamp);

        if ($fresh && ! $force) {
            return false;
        }

        // ไม่เคยส่งการ์ดให้ใบนี้เลย ก็ไม่มีอะไรให้ปิด — ยกเว้นใบที่ถูกยกเลิก ซึ่งแอป
        // อาจวาดการ์ดไว้เองตอนเปิดแอป (ยังไม่เคยผ่านเซิร์ฟเวอร์)
        if ($state === null && ! $force && $last === null
            && ($retire || in_array($booking->status, ['confirmed', 'pending'], true))) {
            return false;
        }

        try {
            $this->fcm->sendDataToUser($userId, [
                'type' => 'trip_activity',
                'event' => $state === null ? 'end' : 'update',
                'booking_ref' => (string) $booking->booking_ref,
                'state' => json_encode($state ?? [], JSON_UNESCAPED_UNICODE),
            ], platform: 'android');
        } catch (\Throwable $e) {
            Log::warning('Trip activity android push failed', ['message' => $e->getMessage()]);

            return false;
        }

        Cache::put($key, ['fp' => $fingerprint, 'at' => now()->timestamp], now()->addDays(2));

        return true;
    }

    /**
     * ยิงเมื่อมีอะไรเปลี่ยนจริง หรือเงียบมานานจนข้อมูลบนจอเริ่มน่าสงสัย
     */
    private function shouldPush(LiveActivity $activity, array $state): bool
    {
        $previous = $activity->state ?? [];

        if (($previous['stage'] ?? null) !== $state['stage']) {
            return true;
        }
        if (($previous['eta_minutes'] ?? null) !== $state['eta_minutes']) {
            return true;
        }
        if (($previous['headline'] ?? null) !== $state['headline']) {
            return true;
        }
        // แอดมินสลับรถ/คนขับกลางวันเดินทาง — ข้อความอาจเหมือนเดิมทุกตัว แต่รูปกับ
        // ทะเบียนบนการ์ดผิดคันไปแล้ว รอ heartbeat คือให้ลูกค้ามองหารถผิดคัน
        foreach (['vehicle_summary', 'vehicle_image_url', 'driver_photo_url'] as $key) {
            if (($previous[$key] ?? null) !== ($state[$key] ?? null)) {
                return true;
            }
        }

        return $activity->last_pushed_at === null
            || $activity->last_pushed_at->lt(now()->subMinutes(self::HEARTBEAT_MINUTES));
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function alertFor(?string $previousStage, array $state): ?array
    {
        $stage = $state['stage'];

        if ($stage === $previousStage || ! in_array($stage, self::ALERTING_STAGES, true)) {
            return null;
        }

        return [
            'title' => $state['headline'],
            'body' => $state['detail'],
        ];
    }

    /**
     * ContentState ฝั่ง Swift รับเฉพาะคีย์ที่มันประกาศไว้ — ส่งเกินมาแล้วถอดรหัสพัง
     * ทั้งก้อน (Activity ค้างข้อมูลเก่าโดยไม่มี error ให้เห็น) จึงคัดที่นี่
     *
     * @return array<string, mixed>
     */
    private function contentState(array $state): array
    {
        return [
            'stage' => $state['stage'],
            'headline' => $state['headline'],
            'detail' => $state['detail'],
            'etaMinutes' => $state['eta_minutes'],
            'progress' => $state['progress'],
            'pickupName' => $state['pickup_name'],
            'vehicleLabel' => $state['vehicle_label'],
            'departsAt' => $state['departs_at'],
            'kicker' => $state['kicker'] ?? null,
            'vehicleSummary' => $state['vehicle_summary'] ?? null,
            'vehicleImageUrl' => $state['vehicle_image_url'] ?? null,
            'driverPhotoUrl' => $state['driver_photo_url'] ?? null,
            'driverName' => $state['driver_name'] ?? null,
            'updatedAt' => $state['updated_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function endState(LiveActivity $activity, ?string $reason = null): array
    {
        $last = $activity->state ?? [];

        return [
            'stage' => 'ended',
            'headline' => $reason ?? 'จบการเดินทางแล้ว',
            'detail' => 'ขอบคุณที่ร่วมทางกับเรา 🎒',
            'etaMinutes' => null,
            'progress' => 1.0,
            'pickupName' => $last['pickup_name'] ?? null,
            'vehicleLabel' => $last['vehicle_label'] ?? null,
            'departsAt' => $last['departs_at'] ?? null,
            // การ์ดที่ปิดแล้วไม่มีรถให้มองหา — กลับไปเป็นการ์ดข้อความธรรมดา
            'kicker' => null,
            'vehicleSummary' => null,
            'vehicleImageUrl' => null,
            'driverPhotoUrl' => null,
            'driverName' => null,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    private function stage(TripActivityViewer $viewer, TripSchedule $schedule, ?Carbon $departsAt, bool $timeKnown, ?float $distanceKm, ?int $etaMinutes): string
    {
        if ($viewer->boarded) {
            return 'onboard';
        }

        // สตาฟที่นั่งมากับรถกดยืนยันว่าจอดถึงจุดนี้แล้ว — ชนะทุกอย่างที่คำนวณจาก
        // GPS รวมถึงกรณีไม่มีพิกัดเลย ซึ่งเคยทำให้การ์ดค้างที่ "เตรียมตัว" ทั้งที่
        // รถจอดอยู่ตรงหน้าลูกค้า (หน้าจอในแอปเชื่อสตาฟอยู่แล้ว การ์ดต้องพูดตรงกัน)
        if ($this->arrivedPickupPoint($viewer, $schedule) !== null) {
            return 'arrived';
        }

        if ($distanceKm !== null && $etaMinutes !== null) {
            if ($distanceKm <= self::ARRIVED_KM) {
                return 'arrived';
            }
            if ($etaMinutes <= self::ARRIVING_MINUTES) {
                return 'arriving';
            }
            if ($etaMinutes <= self::APPROACHING_MINUTES) {
                return 'approaching';
            }

            return 'enroute';
        }

        // รอบที่ไม่ได้กรอกเวลารถออก ถูกอ่านกลับมาเป็นเที่ยงคืนของวันเดินทาง เทียบ
        // "อีกกี่ชั่วโมง" กับเที่ยงคืนที่ไม่มีใครตั้งจึงกลายเป็นเตรียมตัวตั้งแต่สาม
        // ทุ่มของคืนก่อน — ที่รู้จริงคือ "วัน" ก็เดินด้วยวันไปตรง ๆ
        $close = $timeKnown
            ? ($departsAt && $this->nowThai()->diffInHours($departsAt, false) <= 3)
            : $this->departureDayReached($departsAt);

        return $close ? 'preparing' : 'countdown';
    }

    /**
     * @return array{headline: string, detail: string}
     */
    private function copyFor(string $stage, ?Carbon $departsAt, ?string $departTime, ?string $pickupTime, ?int $etaMinutes, ?string $pickupName, Booking $booking, TripActivityViewer $viewer): array
    {
        $timeKnown = $pickupTime !== null || $departTime !== null;
        $place = $pickupName ? "จุดรับ $pickupName" : 'จุดรับของคุณ';

        // ตอนรถถึงแล้ว คำถามเปลี่ยนจาก "อีกนานไหม" เป็น "คันไหน" — ป้ายทะเบียน
        // เคยอยู่แค่มุมบนขวาของการ์ด iOS ตัวเล็กจาง ๆ และไม่มีเลยบนแอนดรอยด์
        // ย้ายมาอยู่ในบรรทัดที่ทั้งสองฝั่งวาดแน่ ๆ
        $plate = $this->plateLabel($booking);
        $parked = $this->parkingNote($viewer, $booking->schedule);

        return match ($stage) {
            'arrived' => [
                'headline' => $plate ? "รถถึงแล้ว · {$plate}" : 'รถถึงจุดรับแล้ว',
                'detail' => $parked
                    ? "รออยู่ที่$place · {$parked}"
                    : "รถรออยู่ที่$place แล้ว ขึ้นรถได้เลย",
            ],
            'arriving' => [
                'headline' => 'รถกำลังจะถึง',
                'detail' => $plate
                    ? "อีกประมาณ {$etaMinutes} นาทีถึง$place · {$plate}"
                    : "อีกประมาณ {$etaMinutes} นาทีถึง$place",
            ],
            'approaching' => [
                'headline' => "รถถึงใน {$etaMinutes} นาที",
                'detail' => "กำลังมาที่$place",
            ],
            'enroute' => [
                'headline' => 'รถออกเดินทางแล้ว',
                'detail' => $etaMinutes !== null
                    ? "อีกประมาณ {$etaMinutes} นาทีถึง$place"
                    : "กำลังมาที่$place",
            ],
            'onboard' => [
                'headline' => 'ขึ้นรถเรียบร้อยแล้ว',
                'detail' => 'เดินทางปลอดภัย แล้วเจอกันที่ปลายทาง 🎒',
            ],
            'preparing' => [
                'headline' => match (true) {
                    $pickupTime !== null => "รถมารับเวลา $pickupTime",
                    $departTime !== null => "รถออกเวลา $departTime",
                    default => 'ถึงวันเดินทางแล้ว',
                },
                'detail' => "เตรียมตัวไปที่$place",
            ],
            default => [
                'headline' => $this->countdownLabel($departsAt, $timeKnown),
                'detail' => match (true) {
                    $pickupTime !== null => "ขึ้นรถ $pickupTime · $place",
                    $departTime !== null => "ออกเดินทาง $departTime · $place",
                    default => $place,
                },
            ],
        };
    }

    private function countdownLabel(?Carbon $departsAt, bool $timeKnown = true): string
    {
        if (! $departsAt) {
            return 'ทริปของคุณ';
        }

        // ไม่รู้เวลารถออก ก็อย่านับเป็นชั่วโมง "อีก 7 ชั่วโมงออกเดินทาง" ที่นับจาก
        // เที่ยงคืนสมมติคือตัวเลขที่ดูแม่นยำแต่ผิด ซึ่งแย่กว่าบอกเป็นวันตรง ๆ
        if (! $timeKnown) {
            $days = (int) $this->nowThai()->startOfDay()->diffInDays($departsAt->copy()->startOfDay(), false);

            return match (true) {
                $days <= 0 => 'ถึงวันเดินทางแล้ว',
                $days === 1 => 'พรุ่งนี้ออกเดินทาง',
                default => "อีก {$days} วันออกเดินทาง",
            };
        }

        $hours = (int) ceil($this->nowThai()->diffInMinutes($departsAt, false) / 60);

        if ($hours <= 0) {
            return 'ถึงเวลาออกเดินทางแล้ว';
        }
        if ($hours < 24) {
            return "อีก {$hours} ชั่วโมงออกเดินทาง";
        }

        return 'อีก '.(int) ceil($hours / 24).' วันออกเดินทาง';
    }

    /**
     * แถบความคืบหน้า 0..1 — ก่อนรถมาถึงคือ "รถวิ่งมาใกล้แค่ไหน" ไม่ใช่ "เดินทางไป
     * ถึงไหนแล้ว" เพราะสิ่งที่คนรออยากรู้ตอนนั้นคืออย่างแรก
     */
    private function progress(string $stage, ?Carbon $departsAt, ?int $etaMinutes): float
    {
        return match ($stage) {
            'arrived', 'onboard' => 1.0,
            'arriving', 'approaching' => round(1 - min(($etaMinutes ?? self::APPROACHING_MINUTES) / self::APPROACHING_MINUTES, 1), 2),
            'enroute' => 0.15,
            'preparing' => 0.08,
            default => 0.0,
        };
    }

    /**
     * หลังเช็คอิน — การ์ดต้องมีอะไรให้ดูต่อเสมอ ไม่แช่ "ขึ้นรถเรียบร้อยแล้ว" จนจบทริป
     *
     * เดิมทีเช็คอินคือจุดจบของเรื่องเล่า และต่อมาก็เดินต่อได้เฉพาะรอบที่มีกำหนดการ
     * *และ* ทีมงานกดหมุดหน้างานครบ ซึ่งในทางปฏิบัติคือส่วนน้อย ที่เหลือค้างทั้งทริป
     * ลำดับที่ใช้ตอนนี้ (อันแรกที่มีคำตอบชนะ):
     *
     *   1. ขากลับ — รถกำลังพามาส่ง บอกเวลาถึงจุดส่ง ([returnLeg])
     *   2. กำหนดการ — หมุดที่ทีมงานกด หรือเวลาในแผนเมื่อไม่มีใครกด ([itineraryLeg])
     *   3. วันของทริป — รอบที่ไม่มีกำหนดการก็ยังบอกได้ว่าวันที่เท่าไหร่ กลับเมื่อไหร่
     *
     * คืน null เฉพาะช่วงที่เพิ่งสแกนตั๋ว ซึ่ง "ขึ้นรถเรียบร้อยแล้ว" คือคำตอบที่ถูก
     *
     * @param  array{lat: float, lng: float}|null  $pickupCoords
     * @param  array<string, mixed>|null  $location
     * @return array<string, mixed>|null
     */
    private function afterBoarding(Booking $booking, TripActivityViewer $viewer, TripSchedule $schedule, ?array $pickupCoords, ?string $pickupName, ?array $location): ?array
    {
        // เพิ่งสแกนตั๋วเสร็จ ปล่อยให้ "ขึ้นรถเรียบร้อยแล้ว" ค้างไว้ก่อน — มันคือคำ
        // ยืนยันที่คนเพิ่งยื่นโทรศัพท์ให้ทีมงานกำลังมองหา
        $checkedInAt = $viewer->checkedInAt;
        if ($checkedInAt && $checkedInAt->gt(now()->subMinutes(self::ONBOARD_MINUTES))) {
            return null;
        }

        return $this->returnLeg($booking, $viewer, $schedule, $pickupCoords, $pickupName, $location)
            ?? $this->itineraryLeg($schedule)
            ?? $this->tripDayLeg($schedule, $pickupName);
    }

    /**
     * "ต่อไปทำอะไร" ตามกำหนดการของรอบ
     *
     * หมุดที่ทีมงานกดยืนยันหน้างานชนะเสมอเมื่อยังสด ([TripProgressService] — ตัว
     * เดียวกับที่หน้าวันเดินทางและลิงก์ให้ที่บ้านติดตามใช้) เพราะแผนเลื่อนได้ทุกทริป
     * แต่หมุดที่กดแล้วคือสิ่งที่เกิดขึ้นจริง
     *
     * แต่ทีมงานหน้างานมีงานของตัวเอง หมุดจึงมักไม่ถูกกดเลย หรือกดไปสองสามจุดแล้ว
     * หยุด ช่วงนั้นเดินตามเวลาในแผนแทน และบอกตรง ๆ ว่า "ตามแผน" ไม่ใช่ยืนยันแล้ว
     *
     * คืน null เมื่อรอบไม่มีกำหนดการ หรือแผนเดินจนสุดแล้วโดยไม่มีใครกด — ให้การ์ด
     * วันของทริปรับช่วงต่อ
     *
     * @return array{stage: string, headline: string, detail: string, progress: float}|null
     */
    private function itineraryLeg(TripSchedule $schedule): ?array
    {
        $progress = $this->itineraryProgress($schedule);
        if (! ($progress['has_itinerary'] ?? false)) {
            return null;
        }

        $total = (int) $progress['total'];
        $reached = (int) $progress['reached_count'];
        $next = $progress['next'];

        if ($next === null) {
            return [
                'stage' => 'itinerary',
                'headline' => 'ครบทุกจุดในกำหนดการแล้ว',
                'detail' => 'เดินทางกลับโดยสวัสดิภาพ 🎒',
                'progress' => 1.0,
            ];
        }

        $lastTick = $progress['last_update_at'] ? Carbon::parse($progress['last_update_at']) : null;
        $ticksFresh = $reached > 0
            && $lastTick !== null
            && $lastTick->gt(now()->subHours(self::TICK_TRUST_HOURS));

        if (! $ticksFresh) {
            $plan = $this->planPosition($schedule, $progress['items']);

            if ($plan !== null) {
                if ($plan['next'] === null) {
                    return null;
                }

                $position = $plan['position'];

                return [
                    'stage' => 'itinerary',
                    'headline' => $this->itemHeadline($schedule, $plan['next']),
                    'detail' => $reached > 0
                        ? "ถัดไปตามแผน · ทีมงานยืนยันแล้ว {$reached} จาก {$total} จุด"
                        : "ถัดไปตามแผนการเดินทาง · จุดที่ {$position} จาก {$total}",
                    'progress' => round(max($reached, $position - 1) / $total, 2),
                ];
            }

            // ไม่มีเวลาในแผนให้เดินตาม และไม่มีใครกดเลย — จุดแรกของแผนจะค้างอยู่
            // บนจอทั้งทริป ซึ่งก็คืออาการเดิมในชื่อใหม่
            if ($reached === 0) {
                return null;
            }
        }

        return [
            'stage' => 'itinerary',
            'headline' => $this->itemHeadline($schedule, $next),
            'detail' => "ถัดไปในกำหนดการ · ผ่านมาแล้ว {$reached} จาก {$total} จุด",
            'progress' => round($reached / $total, 2),
        ];
    }

    /**
     * จุดถัดไปตามเวลาในแผน — จุดแรกที่ยังมาไม่ถึงตามนาฬิกา
     *
     * คืน null เมื่อไม่มีจุดไหนระบุเวลาได้เลย (เดาไม่ได้ ก็อย่าเดา) และคืน
     * `next => null` เมื่อทุกจุดเลยเวลาไปแล้ว
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{next: array<string, mixed>|null, position: int}|null
     */
    private function planPosition(TripSchedule $schedule, array $items): ?array
    {
        $now = $this->nowThai();
        $timeable = 0;
        $next = null;
        $nextAt = null;
        $position = 0;

        foreach (array_values($items) as $index => $item) {
            $at = $this->itemMoment($schedule, $item);
            if ($at === null) {
                continue;
            }

            $timeable++;

            if ($at->lte($now)) {
                continue;
            }

            if ($nextAt === null || $at->lt($nextAt)) {
                [$next, $nextAt, $position] = [$item, $at, $index + 1];
            }
        }

        return $timeable === 0 ? null : ['next' => $next, 'position' => $position];
    }

    /**
     * วัน+เวลาของจุดในแผน ในกรอบเดียวกับ [nowThai] — null เมื่อระบุไม่ได้
     *
     * จุดที่ไม่ได้ใส่วันถือว่าเป็นวันเดินทางได้เฉพาะทริปวันเดียว ทริปหลายวันเดา
     * ไม่ได้ว่าเป็นวันไหน และการเดาผิดคือบอกว่า "จบกำหนดการแล้ว" ตั้งแต่วันแรก
     *
     * @param  array<string, mixed>  $item
     */
    private function itemMoment(TripSchedule $schedule, array $item): ?Carbon
    {
        $time = $this->itemTime($item);
        $date = $this->itemDate($schedule, $item);

        if ($time === null || $date === null) {
            return null;
        }

        try {
            return Carbon::parse("{$date} {$time}");
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * "10:00" — ตัดวินาทีทิ้ง (คอลัมน์ time ของ MySQL/Postgres คืน "10:00:00")
     *
     * @param  array<string, mixed>  $item
     */
    private function itemTime(array $item): ?string
    {
        return preg_match('/^(\d{1,2}):(\d{2})/', trim((string) ($item['time'] ?? '')), $m)
            ? sprintf('%02d:%s', (int) $m[1], $m[2])
            : null;
    }

    /** @param  array<string, mixed>  $item */
    private function itemDate(TripSchedule $schedule, array $item): ?string
    {
        $date = $item['item_date'] ?? null;
        if ($date) {
            return (string) $date;
        }

        return $this->isSingleDay($schedule) ? $schedule->departure_date?->toDateString() : null;
    }

    /**
     * "10:00 น. · ถึงจุดชมวิวผาตั้ง" / "พรุ่งนี้ 05:30 น. · ดูทะเลหมอก"
     *
     * ทริปหลายวัน จุดถัดไปตอนสามทุ่มคือตีห้าของพรุ่งนี้ — เวลาเปล่า ๆ อ่านแล้ว
     * เหมือนตีห้าที่ผ่านไปแล้วของวันนี้
     *
     * @param  array<string, mixed>  $item
     */
    private function itemHeadline(TripSchedule $schedule, array $item): string
    {
        $time = $this->itemTime($item);
        $when = trim($this->dayPrefix($this->itemDate($schedule, $item)).($time !== null ? "{$time} น." : ''));
        $title = trim((string) ($item['title'] ?? ''));

        return $when !== '' ? "{$when} · {$title}" : $title;
    }

    private function dayPrefix(?string $date): string
    {
        if ($date === null) {
            return '';
        }

        $today = $this->nowThai()->startOfDay();
        $day = Carbon::parse($date)->startOfDay();

        return match (true) {
            $day->equalTo($today) => '',
            $day->equalTo($today->copy()->addDay()) => 'พรุ่งนี้ ',
            default => $day->locale('th')->isoFormat('D MMM').' ',
        };
    }

    private function isSingleDay(TripSchedule $schedule): bool
    {
        return $schedule->return_date === null
            || $schedule->departure_date === null
            || $schedule->return_date->isSameDay($schedule->departure_date);
    }

    /**
     * การ์ดสำรองของรอบที่ไม่มีกำหนดการให้เดินตาม — "ทริปวันที่ 1 จาก 3 · กลับ …"
     *
     * ข้อมูลน้อยแต่เป็นความจริงเสมอ และตอบคำถามที่คนบนดอยถามกันจริง ๆ ว่า
     * "วันนี้วันที่เท่าไหร่ของทริป กลับวันไหน"
     *
     * @return array{stage: string, headline: string, detail: string, progress: float}|null
     */
    private function tripDayLeg(TripSchedule $schedule, ?string $pickupName): ?array
    {
        if (! $schedule->departure_date) {
            return null;
        }

        $today = $this->nowThai()->startOfDay();
        $first = Carbon::parse($schedule->departure_date->toDateString());
        $last = Carbon::parse(($schedule->return_date ?? $schedule->departure_date)->toDateString());
        $days = max(1, (int) $first->diffInDays($last) + 1);
        $chat = 'มีอะไรถามทีมงานในแชทกลุ่มได้เลย';

        // รถออกคืนก่อนวันทริป — ขึ้นรถแล้วแต่วันแรกยังไม่มาถึง
        if ($today->lt($first)) {
            $until = (int) $today->diffInDays($first);

            return [
                'stage' => 'trip_day',
                'headline' => 'อยู่ระหว่างเดินทาง',
                'detail' => ($until <= 1 ? 'ถึงวันแรกของทริปพรุ่งนี้' : 'ทริปเริ่ม '.ThaiDate::short($first))
                    .' · พักผ่อนบนรถได้เลย 😴',
                'progress' => 0.05,
            ];
        }

        $day = min((int) $first->diffInDays($today) + 1, $days);
        $progress = round(($day - 0.5) / $days, 2);

        if ($days === 1) {
            return [
                'stage' => 'trip_day',
                'headline' => 'อยู่ระหว่างทริป',
                'detail' => $pickupName ? "ขากลับส่งที่ {$pickupName} · {$chat}" : $chat,
                'progress' => $progress,
            ];
        }

        if ($day >= $days) {
            return [
                'stage' => 'trip_day',
                'headline' => 'วันนี้เดินทางกลับ',
                'detail' => "ทริปวันที่ {$days} จาก {$days} · เก็บของให้ครบก่อนขึ้นรถ 🎒",
                'progress' => $progress,
            ];
        }

        return [
            'stage' => 'trip_day',
            'headline' => "ทริปวันที่ {$day} จาก {$days}",
            'detail' => ($day === $days - 1 ? 'พรุ่งนี้เดินทางกลับ' : 'เดินทางกลับ '.ThaiDate::short($last))
                ." · {$chat}",
            'progress' => $progress,
        ];
    }

    /**
     * ขากลับ — "ถึงจุดส่งราว 18:40 น." สำหรับคนบนรถ และคนที่บ้านที่รอรับ
     *
     * ไม่มีปุ่มให้ใครกดว่า "เริ่มกลับแล้ว" โดยตั้งใจ (เหตุผลเดียวกับ
     * [TripDepartureService]: นาทีนั้นสตาฟกำลังนับหัวอยู่) ใช้พิกัดรถที่ไหลเข้ามา
     * อยู่แล้วแทน: วันสุดท้ายของทริป + เช็คอินมานานแล้ว + รถเข้าใกล้จุดส่งของใบจอง
     * นี้ติดกันสองช่วง
     *
     * จับได้ครั้งเดียวแล้วจำไว้ทั้งวัน (แวะปั๊มกลางทางแล้วรถหยุด ก็ยังขากลับอยู่)
     * และจำ "ส่งถึงแล้ว" ด้วย เพื่อให้การ์ดปิดตัวเองหลังจากนั้น
     *
     * @param  array{lat: float, lng: float}|null  $pickupCoords
     * @param  array<string, mixed>|null  $location
     * @return array<string, mixed>|null
     */
    private function returnLeg(Booking $booking, TripActivityViewer $viewer, TripSchedule $schedule, ?array $pickupCoords, ?string $pickupName, ?array $location): ?array
    {
        if (! $pickupCoords || ! $schedule->vehicle_id || ! $this->isLastTripDay($schedule)) {
            return null;
        }

        $checkedInAt = $viewer->checkedInAt;
        if (! $checkedInAt || $checkedInAt->gt(now()->subHours(self::RETURN_AFTER_CHECKIN_HOURS))) {
            return null;
        }

        $distanceKm = $location
            ? $this->distanceKm(
                (float) $location['latitude'],
                (float) $location['longitude'],
                $pickupCoords['lat'],
                $pickupCoords['lng'],
            )
            : null;

        $key = $this->returnKey($booking, $viewer);
        $leg = Cache::get($key);

        if (! is_array($leg)) {
            if ($distanceKm === null || ! $this->isHeadingTo((int) $schedule->vehicle_id, $pickupCoords, $distanceKm)) {
                return null;
            }

            $leg = ['start_km' => $distanceKm, 'closest_km' => $distanceKm, 'dropped_at' => null];
        }

        if ($leg['dropped_at'] === null && $distanceKm !== null) {
            $leg['closest_km'] = min((float) $leg['closest_km'], $distanceKm);

            // ถึงหมุดจริง หรือเคยเข้ามาใกล้มากแล้ววิ่งต่อไปส่งคนถัดไป (จอดห่างหมุด
            // ไปนิดหน่อย ซึ่งเกิดบ่อยกว่าจอดตรงหมุดเป๊ะ)
            $arrived = $distanceKm <= self::DROPOFF_KM;
            $passedBy = $leg['closest_km'] <= 1.0 && $distanceKm >= $leg['closest_km'] + 1.5;

            if ($arrived || $passedBy) {
                $leg['dropped_at'] = now()->timestamp;
            }
        }

        Cache::put($key, $leg, now()->addDay());

        if ($leg['dropped_at'] !== null) {
            return [
                'stage' => 'dropoff',
                'headline' => 'ถึงจุดส่งแล้ว',
                'detail' => 'ตรวจของให้ครบก่อนลงรถ · ขอบคุณที่ร่วมทางกับเรา 🎒',
                'progress' => 1.0,
            ];
        }

        $place = $pickupName ? "จุดส่ง {$pickupName}" : 'จุดส่ง';
        $startKm = max((float) $leg['start_km'], 0.1);

        // รถหายจากสัญญาณกลางทาง — ยังขากลับอยู่ แต่อย่าเดาเวลาถึง
        if ($distanceKm === null) {
            return [
                'stage' => 'returning',
                'headline' => 'กำลังเดินทางกลับ',
                'detail' => "มุ่งหน้า{$place}",
                'progress' => round(max(0, 1 - min((float) $leg['closest_km'] / $startKm, 1)), 2),
            ];
        }

        $eta = $this->returnEtaMinutes((int) $schedule->vehicle_id, $location, $pickupCoords, $distanceKm);
        $progress = round(max(0, 1 - min($distanceKm / $startKm, 1)), 2);

        if ($eta <= self::DROPOFF_SOON_MINUTES) {
            return [
                'stage' => 'dropoff_soon',
                'headline' => "อีก {$eta} นาทีถึงจุดส่ง",
                'detail' => ($pickupName ? "ใกล้ถึง {$pickupName} แล้ว" : 'ใกล้ถึงจุดส่งแล้ว')
                    .' เก็บของให้พร้อม 🎒',
                'progress' => max($progress, 0.9),
                'eta_minutes' => $eta,
                'distance_km' => $distanceKm,
            ];
        }

        // ขากลับยาวเป็นชั่วโมง — บอกเป็น "เวลาถึง" ปัดทีละ 5 นาที ไม่ใช่นาทีที่
        // ลดลงทุกนาที ซึ่งจะยิงอัปเดตทุกนาทีตลอดสามชั่วโมงโดยไม่มีอะไรเปลี่ยนจริง
        // (และบรรทัดรองต้องนิ่ง เพราะ [shouldPush] ไม่ได้เทียบบรรทัดรอง)
        if ($eta > 30) {
            return [
                'stage' => 'returning',
                'headline' => 'ขากลับ · ถึงราว '.$this->arrivalClock($eta).' น.',
                'detail' => "มุ่งหน้า{$place} · เวลาถึงขยับได้ตามสภาพจราจร",
                'progress' => $progress,
                'eta_minutes' => null,
                'distance_km' => $distanceKm,
            ];
        }

        return [
            'stage' => 'returning',
            'headline' => "ขากลับ · อีก {$eta} นาที",
            'detail' => "มุ่งหน้า{$place}",
            'progress' => $progress,
            'eta_minutes' => $eta,
            'distance_km' => $distanceKm,
        ];
    }

    /** ส่งถึงจุดส่งมานานพอแล้วหรือยัง — ถึงแล้วการ์ดของใบจองนี้ควรปิด */
    private function returnFinished(Booking $booking, TripActivityViewer $viewer): bool
    {
        if (! $viewer->boarded) {
            return false;
        }

        $leg = Cache::get($this->returnKey($booking, $viewer));

        return is_array($leg)
            && ($leg['dropped_at'] ?? null) !== null
            && now()->timestamp - (int) $leg['dropped_at'] >= self::DROPOFF_LINGER_MINUTES * 60;
    }

    /** ต่อจุดส่ง ไม่ใช่ต่อใบจอง — คนในใบเดียวกันลงคนละจุดได้ */
    private function returnKey(Booking $booking, TripActivityViewer $viewer): string
    {
        return "trip_return_leg:{$booking->id}:{$viewer->dropoffKey()}:".$this->nowThai()->toDateString();
    }

    private function isLastTripDay(TripSchedule $schedule): bool
    {
        $last = $schedule->return_date ?? $schedule->departure_date;

        return $last !== null && $last->toDateString() === $this->nowThai()->toDateString();
    }

    /**
     * รถเข้าใกล้จุดนี้ติดกันสองช่วง (~40 → ~20 นาทีก่อน → ตอนนี้) หรือเปล่า
     *
     * @param  array{lat: float, lng: float}  $coords
     */
    private function isHeadingTo(int $vehicleId, array $coords, float $distanceNowKm): bool
    {
        $track = $this->trackCache[$vehicleId] ??= $this->recentTrack($vehicleId);

        if ($track['mid'] === null || $track['old'] === null) {
            return false;
        }

        $mid = $this->distanceKm($track['mid']['lat'], $track['mid']['lng'], $coords['lat'], $coords['lng']);
        $old = $this->distanceKm($track['old']['lat'], $track['old']['lng'], $coords['lat'], $coords['lng']);

        return $old - $mid >= self::RETURN_CLOSING_KM
            && $mid - $distanceNowKm >= self::RETURN_CLOSING_KM;
    }

    /**
     * พิกัดของรถเมื่อ ~20 และ ~40 นาทีก่อน — จากแถวจริงในฐานข้อมูล
     *
     * @return array{mid: array{lat: float, lng: float}|null, old: array{lat: float, lng: float}|null}
     */
    private function recentTrack(int $vehicleId): array
    {
        // recorded_at เก็บด้วย now() ตามเวลาจริงของแอป ไม่ใช่ wall-clock ไทยแบบ
        // departs_at — เทียบกับ now() ตรง ๆ ได้
        $rows = VehicleLocation::where('vehicle_id', $vehicleId)
            ->whereBetween('recorded_at', [now()->subMinutes(50), now()->subMinutes(12)])
            ->orderByDesc('recorded_at')
            ->get(['latitude', 'longitude', 'recorded_at']);

        $point = fn ($row) => $row
            ? ['lat' => (float) $row->latitude, 'lng' => (float) $row->longitude]
            : null;

        return [
            'mid' => $point($rows->first(fn ($row) => $row->recorded_at->gte(now()->subMinutes(30)))),
            'old' => $point($rows->first(fn ($row) => $row->recorded_at->lte(now()->subMinutes(32)))),
        ];
    }

    /**
     * อีกกี่นาทีถึงจุดส่ง
     *
     * ใกล้ ๆ คิดเองจากความเร็วรถ ไกลออกไปถาม Google (รถติดขาเข้ากรุงเทพฯ วันอาทิตย์
     * คือสิ่งที่เส้นตรงไม่มีทางรู้) แต่ถามไม่เกินทุก 10 นาทีต่อจุดส่ง แล้วจำเป็น
     * "เวลาถึง" ไว้ ระหว่างนั้นนับถอยหลังจากเวลาถึงที่จำไว้
     *
     * @param  array<string, mixed>  $location
     * @param  array{lat: float, lng: float}  $coords
     */
    private function returnEtaMinutes(int $vehicleId, array $location, array $coords, float $distanceKm): int
    {
        $speed = isset($location['speed']) ? (float) $location['speed'] : null;

        if ($distanceKm <= self::RETURN_GOOGLE_MIN_KM) {
            // ถนนในเมืองอ้อมกว่าเส้นตรง
            return max(1, $this->etaMinutes($distanceKm * 1.2, $speed));
        }

        // เร็วกว่า 100 กม./ชม. ตามเส้นตรงเป็นไปไม่ได้ — เวลาถึงที่จำไว้ต่ำกว่านี้
        // แปลว่ารถติดกว่าที่ Google เคยคิด ต้องถามใหม่
        $floor = (int) ceil($distanceKm / 100 * 60);
        $key = sprintf('trip_return_arrival:%d:%.3f,%.3f', $vehicleId, $coords['lat'], $coords['lng']);

        // ไดรเวอร์ Redis คืนตัวเลขกลับมาเป็นสตริง — is_int จะพลาดทุกครั้งแล้วถาม
        // Google ทุกนาที
        $arrival = Cache::get($key);
        $minutes = is_numeric($arrival) ? (int) ceil(((int) $arrival - now()->timestamp) / 60) : null;

        if ($minutes === null || $minutes < $floor) {
            $minutes = max($floor, $this->freshReturnEta($location, $coords, $distanceKm));
            Cache::put($key, now()->timestamp + $minutes * 60, self::RETURN_ETA_REFRESH_SECONDS);
        }

        return max($minutes, $floor, 1);
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array{lat: float, lng: float}  $coords
     */
    private function freshReturnEta(array $location, array $coords, float $distanceKm): int
    {
        try {
            $eta = app(GoogleDistanceService::class)->getETA(
                (float) $location['latitude'],
                (float) $location['longitude'],
                $coords['lat'],
                $coords['lng'],
            );
        } catch (\Throwable $e) {
            $eta = null;
        }

        // ค่าสำรองของ GoogleDistanceService สมมติ 40 กม./ชม. ในเมือง ซึ่งผิดมาก
        // บนทางหลวง — ไม่ใช่คำตอบของ Google ก็คิดเองดีกว่า
        if (is_array($eta) && ($eta['source'] ?? null) !== 'haversine') {
            $seconds = $eta['duration_in_traffic']['value'] ?? $eta['duration']['value'] ?? null;
            if (is_numeric($seconds) && $seconds > 0) {
                return (int) ceil($seconds / 60);
            }
        }

        // ถนนจริงยาวกว่าเส้นตรงราวหนึ่งในสี่ ความเร็วเฉลี่ยรวมแวะพักบนทางหลวง
        return (int) ceil(($distanceKm * 1.25) / ($distanceKm > 40 ? 70 : 50) * 60);
    }

    /** "18:40" — เวลาไทยที่ถึง ปัดขึ้นทีละ 5 นาที */
    private function arrivalClock(int $etaMinutes): string
    {
        $at = $this->nowThai()->addMinutes($etaMinutes)->second(0);
        $pad = (5 - $at->minute % 5) % 5;

        return $at->addMinutes($pad)->format('H:i');
    }

    /**
     * ประกาศจากทีมงานที่เพิ่งโพสต์ แทรกขึ้นการ์ดชั่วคราว
     *
     * "รถออกช้า 30 นาที" ที่อยู่ในแอปอย่างเดียวคือประกาศที่คนบนดอยสัญญาณแย่ไม่เห็น
     * การ์ดบนหน้าจอล็อกคือที่ที่เขามองอยู่แล้ว ไม่สั่น/ไม่เด้งเพราะประกาศมี push ของ
     * มันเองแล้ว — แค่ทำให้ยังเห็นอยู่หลังปัดแจ้งเตือนทิ้งไป
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function withAnnouncement(TripSchedule $schedule, array $state): array
    {
        if (! in_array($state['stage'], self::ANNOUNCEMENT_STAGES, true)) {
            return $state;
        }

        $announcement = $this->recentAnnouncement($schedule);
        if (! $announcement) {
            return $state;
        }

        $title = Str::squish((string) $announcement->title);
        $body = Str::squish((string) $announcement->body);

        return array_merge($state, [
            'stage' => 'announcement',
            'headline' => '📢 '.Str::limit($title !== '' ? $title : 'ประกาศจากทีมงาน', 60),
            'detail' => $body !== '' ? Str::limit($body, 110) : 'แตะเพื่ออ่านประกาศจากทีมงาน',
            // ประกาศต้องอ่านได้เต็มความกว้าง ไม่ใช่เบียดอยู่ข้างรูปรถ — URL รูปคงไว้
            // ให้แอปโหลดเก็บไว้ก่อนรถมาถึง
            'kicker' => null,
            'vehicle_summary' => null,
        ]);
    }

    private function recentAnnouncement(TripSchedule $schedule): ?ScheduleAnnouncement
    {
        if (! array_key_exists($schedule->id, $this->announcementCache)) {
            $this->announcementCache[$schedule->id] = ScheduleAnnouncement::where('schedule_id', $schedule->id)
                ->where('created_at', '>=', now()->subMinutes(self::ANNOUNCEMENT_MINUTES))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
        }

        return $this->announcementCache[$schedule->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function itineraryProgress(TripSchedule $schedule): array
    {
        return $this->progressCache[$schedule->id] ??= $this->tripProgress->forSchedule($schedule);
    }

    /**
     * "23:30 น." — หรือ null เมื่อรอบนี้ไม่ได้กรอกเวลารถออกไว้
     *
     * [TripSchedule::effectiveDepartsAt] เติมเที่ยงคืนให้รอบที่ไม่มี `departs_at`
     * ซึ่งถูกสำหรับการเทียบวัน แต่ห้ามเอาไปพิมพ์ลงการ์ด "รถออกเวลา 00:00 น." เป็น
     * เวลาที่ไม่มีใครตั้งไว้ และลูกค้าที่ยืนอ่านตอนตีสี่ไม่มีทางรู้ว่ามันคือค่าว่าง
     */
    private function departTimeLabel(TripSchedule $schedule): ?string
    {
        return $schedule->departs_at
            ? $schedule->departs_at->format('H:i').' น.'
            : null;
    }

    /** ถึงวันเดินทางแล้วหรือยัง — เกณฑ์สำรองของรอบที่ไม่ได้ระบุเวลารถออก */
    private function departureDayReached(?Carbon $departsAt): bool
    {
        return $departsAt !== null
            && $this->nowThai()->gte($departsAt->copy()->startOfDay());
    }

    /** "ทะเบียน ฮก 8899" — null เมื่อยังไม่ได้ผูกรถหรือยังไม่ได้กรอกทะเบียน */
    private function plateLabel(Booking $booking): ?string
    {
        $plate = trim((string) $booking->schedule?->vehicle?->license_plate);

        return $plate !== '' ? "ทะเบียน {$plate}" : null;
    }

    /**
     * สิ่งที่สตาฟพิมพ์ไว้ตอนกดว่ารถถึงจุดนี้ ("จอดตรงข้าม 7-11") — ของแบบนี้
     * พิกัดบอกไม่ได้ และเป็นประโยคที่มีค่าที่สุดบนหน้าจอล็อก ณ นาทีนั้น
     */
    private function parkingNote(TripActivityViewer $viewer, ?TripSchedule $schedule): ?string
    {
        $note = $schedule ? trim((string) $this->arrivedPickupPoint($viewer, $schedule)?->arrival_note) : '';

        return $note !== '' ? $note : null;
    }

    /** กติกา "รถถึงจุดนี้แล้วจริงไหม" อยู่ที่ PickupArrivalService ที่เดียว */
    private function arrivedPickupPoint(TripActivityViewer $viewer, TripSchedule $schedule): ?SchedulePickupPoint
    {
        // หมุดที่ปักเองไม่มีปุ่ม "รถถึงจุดนี้แล้ว" ให้สตาฟกด
        return $viewer->pin === null
            ? app(PickupArrivalService::class)->freshArrivalOf($viewer->point, (int) $schedule->id)
            : null;
    }

    private function vehicleLabel(TripSchedule $schedule): ?string
    {
        $vehicle = $schedule->vehicle;
        if (! $vehicle) {
            return null;
        }

        return trim(($vehicle->name ?? '').' '.($vehicle->license_plate ?? '')) ?: null;
    }

    /** ขั้นที่ลูกค้ากำลังมองหารถคันจริงข้างถนน — การ์ดวาดรูปรถคู่รูปคนขับ */
    private const VEHICLE_CARD_STAGES = ['preparing', 'enroute', 'approaching', 'arriving', 'arrived'];

    /** ขั้นที่นับนาทีถอยหลังจาก GPS — การ์ดขึ้นเลขนาทีตัวใหญ่ใต้ kicker */
    private const VEHICLE_ETA_STAGES = ['enroute', 'approaching', 'arriving'];

    private const NO_VEHICLE_CARD = [
        'kicker' => null,
        'vehicle_summary' => null,
        'vehicle_image_url' => null,
        'driver_photo_url' => null,
        'driver_name' => null,
    ];

    /**
     * ส่วน "รถคันไหน ใครขับ" ของการ์ด — แบบการ์ดเรียกรถ: "รถจะถึงจุดรับภายใน
     * 10 นาที" แล้วตามด้วย "9กข4835 · สีขาว · Toyota Commuter" ข้างรูปรถกับรูปคนขับ
     *
     * vehicle_summary มีค่าเฉพาะขั้นที่กำลังรอรถ — การ์ดฝั่ง iOS ใช้ "มีหรือไม่มี"
     * ตัดสินว่าจะวาดแบบมีรูปรถ ส่วน URL รูปส่งมาตลอดที่ผูกรถแล้ว (ตั้งแต่วันก่อน
     * เดินทาง) เพราะการ์ดดึงรูปจากเน็ตเองไม่ได้ แอปต้องโหลดเก็บไว้ในเครื่องก่อน
     * ถึงเวลาที่ต้องใช้จริง
     *
     * @return array{kicker: ?string, vehicle_summary: ?string, vehicle_image_url: ?string, driver_photo_url: ?string, driver_name: ?string}
     */
    private function vehicleCard(TripSchedule $schedule, string $stage, ?int $etaMinutes): array
    {
        $vehicle = $schedule->vehicle;
        if (! $vehicle) {
            return self::NO_VEHICLE_CARD;
        }

        $showing = in_array($stage, self::VEHICLE_CARD_STAGES, true);
        $driverName = trim((string) $vehicle->driver_name);

        return [
            'kicker' => $showing && $etaMinutes !== null && in_array($stage, self::VEHICLE_ETA_STAGES, true)
                ? 'รถจะถึงจุดรับภายใน'
                : null,
            'vehicle_summary' => $showing ? $this->vehicleSummary($vehicle) : null,
            'vehicle_image_url' => $this->vehicleImageUrl($vehicle),
            'driver_photo_url' => MediaDisk::url($vehicle->driver_photo),
            'driver_name' => $driverName !== '' ? $driverName : null,
        ];
    }

    /**
     * รูปรถของการ์ด — รูปเฉพาะคัน (ถ้าแอดมินใส่ไว้) ไม่งั้นรถตู้ใช้รูปกลางจาก
     * /admin/settings ร่วมกันทุกคัน รถประเภทอื่นไม่ใช้รูปรถตู้ จะได้ไม่วาดรถตู้ให้คนรอเรือ
     */
    private function vehicleImageUrl(Vehicle $vehicle): ?string
    {
        return MediaDisk::url($vehicle->cutout_image)
            ?? ($vehicle->type === 'van' ? SiteSettings::vanCardImageUrl() : null);
    }

    /** "9กข4835 · สีขาว · Toyota Commuter" — สิ่งที่ใช้มองหารถคันจริง เรียงตามที่ตาเห็นก่อน */
    private function vehicleSummary(Vehicle $vehicle): ?string
    {
        $color = trim((string) $vehicle->color);
        if ($color !== '' && ! str_starts_with($color, 'สี')) {
            $color = "สี{$color}";
        }

        $parts = array_filter(
            [trim((string) $vehicle->license_plate), $color, trim((string) $vehicle->model)],
            fn (string $part) => $part !== '',
        );

        return $parts ? implode(' · ', $parts) : null;
    }

    /**
     * ตำแหน่งรถล่าสุดจาก Redis — เหมือน [\App\Console\Commands\NotifyPickupEtaCommand]
     * ตำแหน่งเก่าเกินไปถือว่าไม่มี ดีกว่าวาด ETA จากจุดที่รถไม่ได้อยู่แล้ว
     *
     * @return array<string, mixed>|null
     */
    /**
     * ตำแหน่งรถล่าสุด — Redis ก่อน แล้วค่อยถอยไปหาแถวจริงในฐานข้อมูล
     *
     * เดิมอ่านจาก Redis อย่างเดียว แปลว่าวันที่ Redis สะดุด การ์ดบนหน้าจอล็อกจะ
     * ค้างอยู่ที่ "เตรียมตัว" ทั้งวันโดยไม่มีอะไรฟ้อง ทั้งที่พิกัดถูกบันทึกไว้ครบ
     * ทุกจุด นาทีที่รถกำลังจะถึงคือนาทีที่ไม่ควรพึ่งแคชตัวเดียว
     */
    private function vehicleLocation(int $vehicleId): ?array
    {
        $data = app(VehicleLocationService::class)->latestCoordinates($vehicleId);

        if ($data === null) {
            return null;
        }

        $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : null;
        if ($recordedAt && $recordedAt->diffInMinutes(now()) > self::STALE_LOCATION_MINUTES) {
            return null;
        }

        return $data;
    }

    private function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadius = 6371.0; // km
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** ETA ของจุดรับประจำใบจองจาก VehicleEtaService — null = ไม่มี/ไม่สด/ปักหมุดเอง */
    private function sharedEtaMinutes(Booking $booking, TripActivityViewer $viewer): ?int
    {
        if (! ($pointId = $viewer->routePointId())) {
            return null;
        }

        try {
            return app(VehicleEtaService::class)->minutesFor((int) $booking->schedule_id, $pointId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function etaMinutes(float $distanceKm, ?float $speedKmh): int
    {
        $effectiveSpeed = ($speedKmh !== null && $speedKmh >= 8.0) ? $speedKmh : 35.0;

        return max((int) round(($distanceKm / $effectiveSpeed) * 60), 0);
    }
}
