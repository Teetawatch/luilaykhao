<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FcmToken;
use App\Models\LiveActivity;
use App\Models\TripSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

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

    /** ถึงเวลานี้แล้วยังไม่มีอะไรเปลี่ยน ก็ยิงซ้ำกันหน้าจอค้างข้อมูลเก่า (นาที) */
    private const HEARTBEAT_MINUTES = 20;

    /** หลังเช็คอินกี่นาทีจึงเลิกโชว์ "ขึ้นรถเรียบร้อยแล้ว" แล้วเดินตามกำหนดการต่อ */
    private const ONBOARD_MINUTES = 15;

    /** ขั้นที่ควรทำให้เครื่องสั่น/เด้ง ไม่ใช่แค่เปลี่ยนตัวเลขเงียบ ๆ */
    private const ALERTING_STAGES = ['arriving', 'arrived', 'onboard', 'meetup', 'boarding'];

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
     * อีกกี่ชั่วโมงรถจะออก (ติดลบ = ออกไปแล้ว) — คืน null เมื่อรอบไม่ได้ระบุเวลาออก
     */
    public function hoursUntilDeparture(TripSchedule $schedule): ?float
    {
        $departsAt = $schedule->effectiveDepartsAt();

        return $departsAt === null
            ? null
            : $this->nowThai()->diffInHours($departsAt, false);
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
    public function stateFor(Booking $booking): ?array
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

        $departsAt = $schedule->effectiveDepartsAt();

        // รอบที่บินไปไม่มีทั้งจุดรับและ GPS รถ ขั้นที่ขับเคลื่อนด้วยตำแหน่งรถ
        // (enroute/approaching/arriving/arrived) จึงไม่มีทางเกิดขึ้นเลย การ์ดเคย
        // ค้างอยู่ที่ "อีก N ชั่วโมงออกเดินทาง · จุดรับของคุณ" แล้วกระโดดไป onboard
        // — ไทม์ไลน์ของสนามบินเดินด้วยเวลานัดพบกับเวลาเครื่องออกแทน
        if ($schedule->isFlight()) {
            return $this->flightStateFor($booking, $schedule, $departsAt);
        }

        $pickupCoords = $this->pickupCoords($booking);
        $pickupName = $this->pickupName($booking);
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
            $etaMinutes = $this->etaMinutes(
                $distanceKm,
                isset($location['speed']) ? (float) $location['speed'] : null,
            );
        }

        $departTime = $this->departTimeLabel($schedule);
        $stage = $this->stage($booking, $departsAt, $departTime !== null, $distanceKm, $etaMinutes);
        $copy = $this->copyFor($stage, $departsAt, $departTime, $etaMinutes, $pickupName, $booking);
        $progress = $this->progress($stage, $departsAt, $etaMinutes);

        if ($leg = $this->itineraryLeg($booking, $schedule, $stage)) {
            [$stage, $copy, $progress, $etaMinutes, $distanceKm] = [
                $leg['stage'], $leg, $leg['progress'], null, null,
            ];
        }

        return [
            'stage' => $stage,
            'headline' => $copy['headline'],
            'detail' => $copy['detail'],
            'eta_minutes' => $etaMinutes,
            'distance_km' => $distanceKm !== null ? round($distanceKm, 2) : null,
            'progress' => $progress,
            'departs_at' => $departsAt?->toIso8601String(),
            'pickup_name' => $pickupName,
            'trip_title' => $schedule->trip?->title ?? 'ทริปของคุณ',
            'booking_ref' => $booking->booking_ref,
            'schedule_id' => (int) $schedule->id,
            'vehicle_label' => $this->vehicleLabel($schedule),
            'updated_at' => now()->toIso8601String(),
        ];
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
    private function flightStateFor(Booking $booking, TripSchedule $schedule, ?Carbon $departsAt): array
    {
        $meetingAt = $schedule->meetingAt();
        $meetingPoint = trim((string) $schedule->meeting_point) ?: null;
        $flightLabel = $this->flightLabel($schedule);
        $departTime = $this->departTimeLabel($schedule);

        $stage = $this->flightStage($booking, $meetingAt, $departsAt, $departTime !== null);

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
        // ไม่ใช่จบที่ทริป
        if ($leg = $this->itineraryLeg($booking, $schedule, $stage)) {
            [$stage, $copy, $progress, $etaMinutes] = [$leg['stage'], $leg, $leg['progress'], null];
        }

        return [
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
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /** เจอทีมงานเมื่อไหร่ / ขึ้นเครื่องเมื่อไหร่ — เกณฑ์เวลาของรอบบิน (นาที) */
    private const FLIGHT_MEETUP_MINUTES = 30;

    private const FLIGHT_BOARDING_MINUTES = 60;

    private const FLIGHT_PREPARING_HOURS = 6;

    private function flightStage(Booking $booking, ?Carbon $meetingAt, ?Carbon $departsAt, bool $timeKnown): string
    {
        if ($booking->checked_in) {
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
     */
    public function register(Booking $booking, int $userId, string $pushToken, ?string $activityId, string $platform = 'ios'): LiveActivity
    {
        LiveActivity::live()
            ->where('booking_id', $booking->id)
            ->where('user_id', $userId)
            ->where('push_token', '!=', $pushToken)
            ->update(['ended_at' => now()]);

        return LiveActivity::updateOrCreate(
            ['push_token' => $pushToken],
            [
                'user_id' => $userId,
                'booking_id' => $booking->id,
                'schedule_id' => $booking->schedule_id,
                'platform' => $platform,
                'activity_id' => $activityId,
                'state' => $this->stateFor($booking),
                'stage' => null,
                'started_at' => now(),
                'ended_at' => null,
            ],
        );
    }

    /**
     * ซิงก์ทุก Activity ของรอบเดินทางหนึ่งรอบ — นี่คือสิ่งที่ scheduler เรียกทุกนาที
     *
     * @return int จำนวนที่ยิงออกไปจริง
     */
    public function syncSchedule(TripSchedule $schedule): int
    {
        $activities = LiveActivity::live()
            ->where('schedule_id', $schedule->id)
            ->with(['booking.schedule.trip', 'booking.pickupPoint'])
            ->get();

        $pushed = 0;

        foreach ($activities as $activity) {
            $booking = $activity->booking;
            if (! $booking) {
                $activity->forceFill(['ended_at' => now()])->save();

                continue;
            }

            if ($this->sync($booking, $activity)) {
                $pushed++;
            }
        }

        return $pushed;
    }

    /**
     * ซิงก์ใบจองหนึ่งใบ — เรียกได้จากทั้ง scheduler และจากเหตุการณ์ที่เกิดทันที
     * (เช่นสตาฟสแกนเช็คอิน ซึ่งต้องเห็นบนหน้าจอล็อกเดี๋ยวนั้น ไม่ใช่รออีกนาที)
     */
    public function sync(Booking $booking, ?LiveActivity $only = null): bool
    {
        $state = $this->stateFor($booking);

        $activities = $only
            ? collect([$only])
            : LiveActivity::live()->where('booking_id', $booking->id)->get();

        $pushed = false;

        foreach ($activities as $activity) {
            if ($state === null) {
                $this->apns->end($activity, $this->endState($activity));
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

        if ($this->pushToAndroid($booking, $state)) {
            $pushed = true;
        }

        return $pushed;
    }

    /**
     * เปิด Live Activity จากฝั่งเซิร์ฟเวอร์ให้เครื่องที่ยังไม่มี (iOS 17.2+)
     *
     * นี่คือส่วนที่ทำให้ "ตื่นมาแล้วมันอยู่บนจอแล้ว" เป็นจริง — ลูกค้าที่จองไว้เมื่อ
     * เดือนที่แล้วแล้วไม่ได้เปิดแอปอีกเลย ก็ยังได้เห็น
     *
     * @return int จำนวนเครื่องที่สั่งเปิดไป
     */
    public function pushToStart(Booking $booking): int
    {
        $state = $this->stateFor($booking);
        if ($state === null || ! $booking->user_id) {
            return 0;
        }

        // มีอยู่แล้วบนเครื่องไหนก็ไม่ต้องเปิดซ้ำ — ปล่อยให้ sync() ดูแลต่อ
        if (LiveActivity::live()->where('booking_id', $booking->id)->exists()) {
            return 0;
        }

        $tokens = FcmToken::where('user_id', $booking->user_id)
            ->where('is_active', true)
            ->whereNotNull('live_activity_start_token')
            ->pluck('live_activity_start_token')
            ->unique();

        $started = 0;
        foreach ($tokens as $token) {
            if ($this->apns->start($token, $this->attributesFor($booking), $this->contentState($state))) {
                $started++;
            }
        }

        return $started;
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

        $this->pushToAndroid($booking, null);
    }

    /**
     * Android ไม่มี ActivityKit — ส่ง state ก้อนเดิมไปเป็น data message แล้วให้แอป
     * วาด ongoing notification เอง (state === null แปลว่า "เก็บการ์ดออก")
     */
    private function pushToAndroid(Booking $booking, ?array $state): bool
    {
        if (! $booking->user_id) {
            return false;
        }

        $hasAndroid = FcmToken::where('user_id', $booking->user_id)
            ->where('is_active', true)
            ->where('platform', 'android')
            ->exists();

        if (! $hasAndroid) {
            return false;
        }

        try {
            $this->fcm->sendDataToUser($booking->user_id, [
                'type' => 'trip_activity',
                'event' => $state === null ? 'end' : 'update',
                'booking_ref' => (string) $booking->booking_ref,
                'state' => json_encode($state ?? [], JSON_UNESCAPED_UNICODE),
            ], platform: 'android');
        } catch (\Throwable $e) {
            Log::warning('Trip activity android push failed', ['message' => $e->getMessage()]);

            return false;
        }

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
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    private function stage(Booking $booking, ?Carbon $departsAt, bool $timeKnown, ?float $distanceKm, ?int $etaMinutes): string
    {
        if ($booking->checked_in) {
            return 'onboard';
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
    private function copyFor(string $stage, ?Carbon $departsAt, ?string $departTime, ?int $etaMinutes, ?string $pickupName, Booking $booking): array
    {
        $place = $pickupName ? "จุดรับ $pickupName" : 'จุดรับของคุณ';

        return match ($stage) {
            'arrived' => [
                'headline' => 'รถถึงจุดรับแล้ว',
                'detail' => "รถรออยู่ที่$place แล้ว ขึ้นรถได้เลย",
            ],
            'arriving' => [
                'headline' => 'รถกำลังจะถึง',
                'detail' => "อีกประมาณ {$etaMinutes} นาทีถึง$place",
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
                'headline' => $departTime ? "รถออกเวลา $departTime" : 'ถึงวันเดินทางแล้ว',
                'detail' => "เตรียมตัวไปที่$place",
            ],
            default => [
                'headline' => $this->countdownLabel($departsAt, $departTime !== null),
                'detail' => $departTime
                    ? "ออกเดินทาง $departTime · $place"
                    : $place,
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

    private function pickupName(Booking $booking): ?string
    {
        if ($booking->pickupPoint?->pickup_location) {
            return $booking->pickupPoint->pickup_location;
        }

        return $booking->custom_pickup_label ?: null;
    }

    /**
     * ช่วงกลางทริป — "ต่อไปทำอะไร" แทนที่จะค้างอยู่ที่ "ขึ้นรถเรียบร้อยแล้ว"
     *
     * เดิมทีเช็คอินคือจุดจบของเรื่องเล่า: [stage] คืน `onboard` เป็นบรรทัดแรกสุด
     * แล้วการ์ดก็แช่ข้อความเดียวไปจนจบทริปสองวัน ทั้งที่คำถามของคนบนรถเปลี่ยนไป
     * แล้วตั้งแต่ตอนขึ้นรถ
     *
     * แหล่งความจริงคือหมุดที่ทีมงานกดยืนยันหน้างาน ([TripProgressService] — ตัว
     * เดียวกับที่หน้าวันเดินทางและลิงก์ให้ที่บ้านติดตามใช้) ไม่ใช่นาฬิกา เพราะแผน
     * เลื่อนได้ทุกทริป แต่หมุดที่กดแล้วคือสิ่งที่เกิดขึ้นจริง
     *
     * คืน null เมื่อยังไม่ควรเปลี่ยน — ยังไม่ได้ขึ้นรถ เพิ่งขึ้นรถ หรือรอบนี้ไม่มี
     * กำหนดการ ซึ่งแปลว่าค้างที่ขั้นเดิม ไม่ใช่ขึ้นการ์ดเปล่า
     *
     * @return array{stage: string, headline: string, detail: string, progress: float}|null
     */
    private function itineraryLeg(Booking $booking, TripSchedule $schedule, string $stage): ?array
    {
        if ($stage !== 'onboard') {
            return null;
        }

        // เพิ่งสแกนตั๋วเสร็จ ปล่อยให้ "ขึ้นรถเรียบร้อยแล้ว" ค้างไว้ก่อน — มันคือคำ
        // ยืนยันที่คนเพิ่งยื่นโทรศัพท์ให้ทีมงานกำลังมองหา
        $checkedInAt = $booking->checked_in_at;
        if ($checkedInAt && $checkedInAt->gt(now()->subMinutes(self::ONBOARD_MINUTES))) {
            return null;
        }

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

        return [
            'stage' => 'itinerary',
            'headline' => $next['time']
                ? $next['time'].' น. · '.$next['title']
                : $next['title'],
            'detail' => "ถัดไปในกำหนดการ · ผ่านมาแล้ว {$reached} จาก {$total} จุด",
            'progress' => $total > 0 ? round($reached / $total, 2) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itineraryProgress(TripSchedule $schedule): array
    {
        return $this->progressCache[$schedule->id] ??= $this->tripProgress->forSchedule($schedule);
    }

    /**
     * พิกัดจุดรับของใบจองนี้ — จุดรับของรอบก่อน แล้วค่อยหมุดที่ลูกค้าปักเอง
     *
     * หมุดที่ปักเองมีพิกัดอยู่บนใบจองอยู่แล้ว ([Booking::custom_pickup_lat]) การไม่
     * อ่านมันแปลว่าคนกลุ่มนี้เห็นการ์ดค้างอยู่ที่ "เตรียมตัว" ตลอดเช้า ทั้งที่รถ
     * กำลังวิ่งมาหาเขาจริง ๆ เกณฑ์ "ยังไม่ถูกปฏิเสธ" ใช้ชุดเดียวกับที่แอปคนขับใช้
     * ตัดสินว่าจุดนี้ต้องแวะไหม
     *
     * @return array{lat: float, lng: float}|null
     */
    private function pickupCoords(Booking $booking): ?array
    {
        $point = $booking->pickupPoint;
        if ($point && $point->latitude !== null && $point->longitude !== null) {
            return ['lat' => (float) $point->latitude, 'lng' => (float) $point->longitude];
        }

        if ($booking->pickup_point_id === null
            && $booking->custom_pickup_lat !== null
            && $booking->custom_pickup_lng !== null
            && $booking->custom_pickup_status !== 'rejected'
        ) {
            return [
                'lat' => (float) $booking->custom_pickup_lat,
                'lng' => (float) $booking->custom_pickup_lng,
            ];
        }

        return null;
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

    private function vehicleLabel(TripSchedule $schedule): ?string
    {
        $vehicle = $schedule->vehicle;
        if (! $vehicle) {
            return null;
        }

        return trim(($vehicle->name ?? '').' '.($vehicle->license_plate ?? '')) ?: null;
    }

    /**
     * ตำแหน่งรถล่าสุดจาก Redis — เหมือน [\App\Console\Commands\NotifyPickupEtaCommand]
     * ตำแหน่งเก่าเกินไปถือว่าไม่มี ดีกว่าวาด ETA จากจุดที่รถไม่ได้อยู่แล้ว
     *
     * @return array<string, mixed>|null
     */
    private function vehicleLocation(int $vehicleId): ?array
    {
        try {
            $raw = Redis::get("vehicle:location:{$vehicleId}");
        } catch (\Throwable $e) {
            return null;
        }

        if (! $raw) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['latitude'], $data['longitude'])) {
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

    private function etaMinutes(float $distanceKm, ?float $speedKmh): int
    {
        $effectiveSpeed = ($speedKmh !== null && $speedKmh >= 8.0) ? $speedKmh : 35.0;

        return max((int) round(($distanceKm / $effectiveSpeed) * 60), 0);
    }
}
