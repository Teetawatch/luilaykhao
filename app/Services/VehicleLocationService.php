<?php

namespace App\Services;

use App\Events\VehicleLocationUpdated;
use App\Models\Booking;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * ทางเข้าออกทางเดียวของ "ตอนนี้รถอยู่ไหน"
 *
 * เดิมตรรกะนี้อยู่ในคอนโทรลเลอร์ของ endpoint สาธารณะที่ออกแบบไว้ให้กล่อง GPS /
 * แอปคนขับยิงเข้ามา แต่จริง ๆ แล้วคนขับไม่ได้ใช้แอป คนที่นั่งไปกับรถทุกรอบคือ
 * สตาฟ มือถือของสตาฟจึงเป็นแหล่งพิกัดของรถ และต้องเข้าทางที่ยืนยันตัวตนได้
 * (ดู StaffController::updateVehicleLocation) ไม่ใช่ทางสาธารณะเดิม
 *
 * ทุกเส้นทางที่บันทึกพิกัดต้องผ่าน [record] เพื่อให้สามอย่างนี้เกิดพร้อมกันเสมอ:
 * เก็บประวัติลงฐานข้อมูล, ปักตำแหน่งล่าสุดไว้ใน Redis (คนอ่านมากกว่าคนเขียนมาก)
 * และกระจาย event ให้แผนที่ของลูกค้าขยับเดี๋ยวนั้น
 */
class VehicleLocationService
{
    /** departs_at/departure_date เก็บเป็นเวลาไทย ส่วน app tz เป็น UTC */
    public const TIMEZONE = 'Asia/Bangkok';

    /** ตำแหน่งล่าสุดใน Redis อยู่ได้นานแค่ไหน (วินาที) */
    public const CACHE_TTL = 3600;

    /** แอปเริ่มแชร์เองก่อนรถออกกี่นาที (คนละเรื่องกับกรอบที่เซิร์ฟเวอร์ยอมรับพิกัด) */
    public const AUTO_START_BEFORE_MINUTES = 90;

    /**
     * คนที่ส่งพิกัดล่าสุดถือสิทธิ์เป็น "รถคันนี้" นานกี่นาที
     *
     * รอบหนึ่งมีสตาฟได้หลายคน ถ้าทุกเครื่องส่งพิกัดของรถคันเดียวกัน หมุดบนแผนที่
     * ลูกค้าจะกระโดดไปมาระหว่างคนที่อยู่หัวรถกับคนที่ยืนอยู่ท้ายแถว (หรือคนละ
     * จังหวัดกันเลยถ้าคนหนึ่งไม่ได้ไปกับรถ) เครื่องแรกที่ส่งจึงถือสิทธิ์ไว้ และ
     * เครื่องอื่นอยู่เฉย ๆ — สิทธิ์หมดอายุเองถ้าเจ้าของเงียบไป เครื่องถัดไปจะ
     * รับช่วงต่อได้เองโดยไม่ต้องมีใครสั่ง
     */
    public const CLAIM_MINUTES = 5;

    /** พิกัดใหม่กว่านี้ (นาที) ถือว่ารถ "กำลังแชร์อยู่" — อุโมงค์/ทางเขาเงียบได้ราวนี้ */
    public const LIVE_MINUTES = 20;

    /**
     * รถออกไปแล้วกี่นาทีโดยยังไม่มีพิกัดของรอบนี้เลย จึงนับว่า "ขาด" ไม่ใช่ "กำลังจะเปิด"
     *
     * สิบห้านาทีเผื่อรอบที่รถออกช้ากว่ากำหนดนิดหน่อย และสตาฟที่เพิ่งเปิดแอป
     * ตอนขึ้นรถ ซึ่งเป็นเรื่องปกติ ไม่ใช่เรื่องที่ต้องโทรตาม
     */
    public const MISSING_AFTER_MINUTES = 15;

    /** เคยแชร์แล้วเงียบนานเท่านี้ (นาที) ถือว่าการแชร์หลุด — เครื่องรีสตาร์ต/แอปถูกปิด */
    public const STALLED_MINUTES = 45;

    /**
     * หลังรถออกกี่ชั่วโมงที่การไม่มีพิกัดยังเป็นเรื่องต้องตาม
     *
     * เลยจากนี้ส่วนใหญ่รถถึงที่พักและจอดค้างคืน มือถือสตาฟในโหมดประหยัดแบต
     * กรองระยะ 300 เมตร รถที่จอดนิ่งจึงเงียบไปทั้งคืนเป็นเรื่องปกติ
     */
    public const ATTENTION_HOURS = 10;

    /**
     * บันทึกพิกัดหนึ่งจุด + แคช + กระจาย event
     *
     * @param  array<string, mixed>  $data  latitude/longitude และของแถมที่มีบ้างไม่มีบ้าง
     */
    public function record(Vehicle $vehicle, array $data, ?int $userId = null): VehicleLocation
    {
        $location = VehicleLocation::create([
            'vehicle_id' => $vehicle->id,
            'user_id' => $userId,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'recorded_at' => $data['recorded_at'] ?? now(),
        ]);

        $this->broadcastLatest($vehicle, $location);

        return $location;
    }

    /** แคช + ยิง event ของพิกัดล่าสุด — แยกไว้เพื่อให้ batch ยิงแค่จุดท้ายสุดของแต่ละคัน */
    public function broadcastLatest(Vehicle $vehicle, VehicleLocation $location): void
    {
        $schedule = $this->activeScheduleFor($vehicle->id);

        $this->cache($vehicle, $location, $schedule);

        broadcast(new VehicleLocationUpdated(
            vehicleId: $vehicle->id,
            latitude: (float) $location->latitude,
            longitude: (float) $location->longitude,
            speed: $location->speed !== null ? (float) $location->speed : null,
            heading: $location->heading !== null ? (float) $location->heading : null,
            vehicleName: $vehicle->name,
            licensePlate: $vehicle->license_plate ?? '',
            type: $vehicle->type,
            recordedAt: $location->recorded_at->toIso8601String(),
            driverName: $vehicle->driver_name,
            driverPhone: $vehicle->driver_phone,
            destLat: $schedule?->trip?->latitude,
            destLng: $schedule?->trip?->longitude,
            tripTitle: $schedule?->trip?->title,
        ));
    }

    /**
     * รอบที่รถคันนี้กำลังวิ่งอยู่ตอนนี้ — ใช้ทั้งตอน broadcast, cache และหน้า dashboard
     * เพื่อให้ทุกทางเห็น "รอบที่กำลังเดินทาง" ตรงกัน (ทริปหลายวันก็ยังนับ)
     */
    public function activeScheduleFor(int $vehicleId): ?TripSchedule
    {
        return TripSchedule::with('trip')
            ->where('vehicle_id', $vehicleId)
            ->where('status', '!=', 'cancelled')
            ->inProgressOn(now(self::TIMEZONE))
            ->orderBy('departure_date')
            ->first();
    }

    /**
     * รอบที่หน้าติดตามรถควรใช้วัด "พิกัดนี้ของรอบไหน"
     *
     * แอปรู้ว่าตัวเองติดตามรอบไหนจึงส่ง [scheduleId] มา (ต้องเป็นรอบของรถคันนี้)
     * ถ้าไม่ส่งมาใช้รอบที่กำลังวิ่งอยู่ ไม่มีก็รอบถัดไป — เช้ามืดวันเดินทางที่รถ
     * ยังไม่ออก รอบของวันนี้ยังไม่ "กำลังวิ่ง" แต่เป็นรอบที่ลูกค้ารออยู่
     */
    public function scheduleForTracking(int $vehicleId, ?int $scheduleId = null): ?TripSchedule
    {
        if ($scheduleId) {
            $schedule = TripSchedule::with('trip')
                ->where('vehicle_id', $vehicleId)
                ->find($scheduleId);

            if ($schedule) {
                return $schedule;
            }
        }

        return $this->activeScheduleFor($vehicleId)
            ?? TripSchedule::with('trip')
                ->where('vehicle_id', $vehicleId)
                ->where('status', '!=', 'cancelled')
                ->whereDate('departure_date', '>=', $this->nowThai()->toDateString())
                ->orderBy('departure_date')
                ->first();
    }

    /**
     * พิกัดของรถนับเป็นของรอบนี้ตั้งแต่เมื่อไหร่ — 6 ชม. ก่อนวันเดินทาง (หรือก่อน
     * เวลารถออก ถ้ารถออกตั้งแต่คืนก่อนวันทริป)
     *
     * คืนเป็นเวลาจริงแบบ UTC เพื่อเทียบกับ recorded_at ได้ตรง ๆ: departure_date /
     * departs_at เก็บตัวเลขนาฬิกาไทยในคอลัมน์ชนิด UTC ถ้าเทียบดิบ ๆ จุดเริ่มจะช้า
     * กว่าจริง 7 ชม. รอบที่รถออก 22:30 คืนก่อนจะไม่มีพิกัดให้เห็นชั่วโมงแรก
     */
    public function trackingSince(TripSchedule $schedule): Carbon
    {
        $departureDay = $schedule->departure_date
            ? $schedule->departure_date->copy()->startOfDay()
            : $this->nowThai()->startOfDay();

        $start = $schedule->departs_at
            ? $schedule->departs_at->copy()->min($departureDay)
            : $departureDay;

        return Carbon::parse($start->subHours(6)->format('Y-m-d H:i:s'), self::TIMEZONE)->utc();
    }

    /**
     * พิกัดล่าสุดของรถที่เป็นของรอบนี้จริง — null เมื่อรอบนี้ยังไม่มีใครส่งพิกัดมา
     *
     * รถคันเดียววิ่งหลายรอบ ถ้าหยิบ "จุดล่าสุด" เฉย ๆ เช้าวันเดินทางก่อนสตาฟเปิดแอป
     * ลูกค้าจะเห็นรถจอดอยู่ปลายทางของทริปเมื่อวานเหมือนเป็นตำแหน่งสด และ ETA ก็
     * คำนวณจากจุดนั้นไปด้วย
     *
     * @return array<string, mixed>|null รูปแบบเดียวกับ [format]
     */
    public function latestForSchedule(Vehicle $vehicle, TripSchedule $schedule): ?array
    {
        $since = $this->trackingSince($schedule);

        $cached = $this->cached($vehicle->id);
        if (
            $cached
            && isset($cached['latitude'], $cached['longitude'], $cached['recorded_at'])
            && Carbon::parse($cached['recorded_at'])->gte($since)
        ) {
            return $cached;
        }

        $latest = VehicleLocation::where('vehicle_id', $vehicle->id)
            ->where('recorded_at', '>=', $since)
            ->orderByDesc('recorded_at')
            ->first();

        return $latest ? $this->format($vehicle, $latest, $schedule, $since) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function format(
        Vehicle $vehicle,
        ?VehicleLocation $latest,
        ?TripSchedule $schedule = null,
        ?\DateTimeInterface $trackingSince = null,
    ): array {
        return [
            'vehicle_id' => $vehicle->id,
            'vehicle_name' => $vehicle->name,
            'license_plate' => $vehicle->license_plate,
            'type' => $vehicle->type,
            'driver_phone' => $vehicle->driver_phone,
            'driver_name' => $vehicle->driver_name,
            'latitude' => $latest?->latitude,
            'longitude' => $latest?->longitude,
            'speed' => $latest?->speed,
            'heading' => $latest?->heading,
            'recorded_at' => $latest?->recorded_at->toIso8601String(),
            'dest_lat' => $schedule?->trip?->latitude,
            'dest_lng' => $schedule?->trip?->longitude,
            'trip_title' => $schedule?->trip?->title,
            'schedule_id' => $schedule?->id,
            'departure_date' => $schedule?->departure_date?->toDateString(),
            'return_date' => $schedule?->return_date?->toDateString(),
            'departs_at' => $schedule?->departs_at?->toIso8601String(),
            'tracking_since' => $trackingSince?->format('c'),
        ];
    }

    public function cache(Vehicle $vehicle, VehicleLocation $location, ?TripSchedule $schedule = null): void
    {
        $data = $this->format($vehicle, $location, $schedule ?? $this->activeScheduleFor($vehicle->id));

        try {
            Redis::setex("vehicle:location:{$vehicle->id}", self::CACHE_TTL, json_encode($data));
        } catch (\Exception $e) {
            // Redis ล่ม — ยังอ่านจากฐานข้อมูลได้ ไม่ต้องทำให้การบันทึกพัง
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cached(int $vehicleId): ?array
    {
        try {
            $data = Redis::get("vehicle:location:{$vehicleId}");

            return $data ? json_decode($data, true) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * ช่วงเวลาที่ยอมให้สตาฟแชร์ตำแหน่งรถของรอบนี้ (เวลาไทย)
     *
     * เปิดก่อนรถออก 12 ชม. เพราะหลายรอบสตาฟไปถึงจุดรวมพลตั้งแต่กลางดึก และปิด
     * หลังวันกลับ 6 ชม. — ตำแหน่งของคนที่นั่งรถกลับบ้านไปแล้วไม่ใช่ตำแหน่งของรถ
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function sharingWindow(TripSchedule $schedule): array
    {
        $departureDay = $schedule->departure_date
            ? $schedule->departure_date->copy()->startOfDay()
            : $this->nowThai()->startOfDay();

        $start = ($schedule->departs_at ? $schedule->departs_at->copy()->min($departureDay) : $departureDay)
            ->subHours(12);

        $end = ($schedule->return_date ?: $schedule->departure_date)
            ? ($schedule->return_date ?: $schedule->departure_date)->copy()->endOfDay()->addHours(6)
            : $departureDay->copy()->endOfDay()->addHours(6);

        return [$start, $end];
    }

    /**
     * โหมดการแชร์ที่ควรเป็นตอนนี้ของรอบนี้ — null = ไม่ต้องแชร์
     *
     * แยกจาก [withinSharingWindow] ตั้งใจ: กรอบนั้นคือ "เซิร์ฟเวอร์ยอมรับพิกัด
     * ไหม" ซึ่งเปิดกว้าง (12 ชม. ก่อนรถออก) ส่วนอันนี้คือ "มือถือของสตาฟควรเริ่ม
     * ส่งเองเมื่อไหร่" ซึ่งต้องแคบกว่ามาก — เปิดเองตั้งแต่เย็นวันก่อนแปลว่าเรา
     * ออกอากาศตำแหน่งส่วนตัวของสตาฟทั้งคืนในนามของรถ แถมกินแบตจนถึงเช้า
     *
     * - pickup  = ยังวิ่งเก็บคนอยู่ ลูกค้าที่ยืนรอต้องการตำแหน่งถี่ ๆ
     * - onboard = รับครบทุกจุดแล้ว คนที่ยังดูอยู่คือคนที่บ้าน ส่งห่างขึ้นได้
     */
    public function autoShareMode(
        TripSchedule $schedule,
        ?Carbon $now = null,
        ?int $forUserId = null,
    ): ?string {
        if (! $schedule->vehicle_id || $schedule->status === 'cancelled') {
            return null;
        }

        // มีเครื่องอื่นถือสิทธิ์อยู่ — เครื่องนี้ไม่ต้องเปิดเอง
        $sharer = $this->currentSharerId((int) $schedule->vehicle_id);
        if ($forUserId !== null && $sharer !== null && $sharer !== $forUserId) {
            return null;
        }

        $now ??= $this->nowThai();
        [, $end] = $this->sharingWindow($schedule);

        $departsAt = $this->departsAtThai($schedule);

        if (! $departsAt) {
            return null;
        }

        $start = $departsAt->subMinutes(self::AUTO_START_BEFORE_MINUTES);

        if ($now->lt($start) || $now->gt($end)) {
            return null;
        }

        return $this->everyoneAboard($schedule) ? 'onboard' : 'pickup';
    }

    /**
     * รับคนครบแล้วหรือยัง — ยึด "จุดรับถูกปิดครบ" เป็นหลัก เพราะเป็นสิ่งที่สตาฟ
     * ติ๊ก (และการเช็คอินคนสุดท้ายของจุดก็ปิดให้เองอยู่แล้ว) รอบที่ไม่มีจุดรับ
     * ตายตัวใช้ "ไม่มีใครค้างเช็คอิน" แทน
     */
    private function everyoneAboard(TripSchedule $schedule): bool
    {
        $points = $schedule->pickupPoints()->count();

        if ($points > 0) {
            return $schedule->pickupPoints()->whereNull('completed_at')->doesntExist();
        }

        $confirmed = Booking::where('schedule_id', $schedule->id)->where('status', 'confirmed');

        return $confirmed->clone()->exists()
            && $confirmed->clone()->where('checked_in', false)->doesntExist();
    }

    /**
     * ใครกำลังเป็น "รถคันนี้" อยู่ตอนนี้ — null เมื่อไม่มีใครส่งมาสักพักแล้ว
     */
    public function currentSharerId(int $vehicleId): ?int
    {
        $latest = VehicleLocation::where('vehicle_id', $vehicleId)
            ->where('recorded_at', '>=', now()->subMinutes(self::CLAIM_MINUTES))
            ->whereNotNull('user_id')
            ->orderByDesc('recorded_at')
            ->first(['user_id']);

        return $latest ? (int) $latest->user_id : null;
    }

    /**
     * เวลารถออก (นาฬิกาไทย) — รอบที่แอดมินไม่ได้กรอกเวลาใช้ 06:00 ของวันเดินทาง
     *
     * ค่าตัวแทน 06:00 มีไว้คิดว่า "ถึงเวลาเปิดแชร์หรือยัง" เท่านั้น ห้ามพิมพ์ออกไป
     * ให้ใครเห็นเป็นเวลารถออก
     */
    public function departsAtThai(TripSchedule $schedule): ?Carbon
    {
        return $schedule->departs_at?->copy()
            ?: $schedule->departure_date?->copy()->setTime(6, 0);
    }

    /**
     * เวลาที่มือถือสตาฟจะเริ่มแชร์เอง (นาฬิกาไทย) — null เมื่อรอบไม่ได้กรอกเวลารถออก
     * เพราะเวลาที่คิดจาก 06:00 ตัวแทนไม่ใช่เวลาที่ควรบอกใคร
     */
    public function autoShareStartsAt(TripSchedule $schedule): ?Carbon
    {
        return $schedule->departs_at?->copy()->subMinutes(self::AUTO_START_BEFORE_MINUTES);
    }

    /**
     * สถานะการแชร์ตำแหน่งรถของรอบนี้ — คำตอบเดียวกันทั้งหน้าแอดมิน งานเตือน และ
     * "สิ่งที่รอคุณ"
     *
     * - upcoming = ยังไม่ถึงเวลาที่แอปจะเปิดแชร์เอง
     * - waiting  = ถึงเวลาแล้ว แต่รถยังไม่ออก (หรือเพิ่งออกไม่ถึง 15 นาที)
     * - missing  = รถออกแล้วแต่รอบนี้ยังไม่มีพิกัดเลย — ลูกค้ามองไม่เห็นรถ
     * - live     = มีพิกัดใหม่
     * - stalled  = เคยมีพิกัด แต่เงียบไปนานแล้ว
     *
     * needs_attention = ต้องมีคนโทรตามสตาฟเดี๋ยวนี้ (เฉพาะช่วงวันเดินทางที่รถวิ่งจริง)
     *
     * @return array{state: string, needs_attention: bool, last_recorded_at: ?string, minutes_since: ?int, sharer: ?array{id: int, name: string}}
     */
    public function sharingStatus(TripSchedule $schedule, ?Carbon $now = null): array
    {
        $now ??= $this->nowThai();
        $departsAt = $this->departsAtThai($schedule);

        $latest = $schedule->vehicle_id
            ? VehicleLocation::with('user:id,name')
                ->where('vehicle_id', $schedule->vehicle_id)
                ->where('recorded_at', '>=', $this->trackingSince($schedule))
                ->orderByDesc('recorded_at')
                ->first()
            : null;

        // อายุของพิกัดเทียบกับเวลาจริง (recorded_at เป็น UTC จริง) ไม่ใช่นาฬิกาไทย
        $minutesSince = $latest
            ? (int) floor(abs($latest->recorded_at->diffInMinutes(now())))
            : null;

        if ($latest) {
            $state = $minutesSince <= self::LIVE_MINUTES ? 'live' : 'stalled';
        } elseif (! $departsAt || $now->lt($departsAt->copy()->subMinutes(self::AUTO_START_BEFORE_MINUTES))) {
            $state = 'upcoming';
        } elseif ($now->lt($departsAt->copy()->addMinutes(self::MISSING_AFTER_MINUTES))) {
            $state = 'waiting';
        } else {
            $state = 'missing';
        }

        $onTheRoad = $departsAt
            && $now->gte($departsAt->copy()->addMinutes(self::MISSING_AFTER_MINUTES))
            && $now->lte($departsAt->copy()->addHours(self::ATTENTION_HOURS));

        $needsAttention = $onTheRoad && (
            $state === 'missing'
            || ($state === 'stalled' && $minutesSince >= self::STALLED_MINUTES)
        );

        return [
            'state' => $state,
            'needs_attention' => $needsAttention,
            'last_recorded_at' => $latest?->recorded_at?->toIso8601String(),
            'minutes_since' => $minutesSince,
            'sharer' => $latest?->user
                ? ['id' => (int) $latest->user->id, 'name' => (string) $latest->user->name]
                : null,
        ];
    }

    /**
     * สถานะการแชร์ของทุกรอบที่มีรถและกำลังเดินทาง/ออกวันนี้ พร้อมสตาฟที่ต้องโทรตาม
     * — ข้อมูลของหน้าติดตามรถฝั่งแอดมิน และกอง "สิ่งที่รอคุณ"
     *
     * มีชื่อและเบอร์โทรสตาฟ ห้ามส่งออกทาง endpoint สาธารณะ
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sharingBoard(): Collection
    {
        $today = now(self::TIMEZONE);

        // รอบที่ออกพรุ่งนี้มืด ๆ ก็เปิดแชร์เองตั้งแต่ก่อนเที่ยงคืนได้ — ตัดทิ้งทีหลัง
        // ถ้ายังไม่ถึงเวลา (state = upcoming)
        $schedules = TripSchedule::with(['trip:id,title', 'vehicle', 'activeStaff:id,name,phone'])
            ->whereNotNull('vehicle_id')
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q
                ->inProgressOn($today)
                ->orWhere(fn ($q2) => $q2->departingOn($today->copy()->addDay())))
            ->orderBy('departure_date')
            ->get()
            ->filter(fn (TripSchedule $s) => $s->vehicle !== null);

        $order = ['missing' => 0, 'stalled' => 1, 'waiting' => 2, 'live' => 3, 'upcoming' => 4];

        return $schedules
            ->map(function (TripSchedule $s) {
                $status = $this->sharingStatus($s);

                return [
                    'schedule_id' => $s->id,
                    'vehicle_id' => (int) $s->vehicle_id,
                    'vehicle_name' => $s->vehicle->name,
                    'license_plate' => $s->vehicle->license_plate,
                    'trip_title' => $s->trip?->title,
                    'departure_date' => $s->departure_date?->toDateString(),
                    // เวลาจริงที่แอดมินกรอกเท่านั้น — 06:00 ตัวแทนห้ามพิมพ์
                    'departs_time' => $s->departs_at?->format('H:i'),
                    'departs_sort' => $this->departsAtThai($s)?->format('Y-m-d H:i:s'),
                    'staff' => $s->activeStaff
                        ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name, 'phone' => $u->phone])
                        ->values()
                        ->all(),
                ] + $status;
            })
            // ยังไม่ถึงเวลาเปิดแชร์ — ยังไม่มีอะไรต้องดู
            ->reject(fn (array $row) => $row['state'] === 'upcoming')
            ->sortBy(fn (array $row) => sprintf(
                '%d-%d-%s',
                $row['needs_attention'] ? 0 : 1,
                $order[$row['state']] ?? 9,
                $row['departs_sort'] ?? '',
            ))
            ->map(function (array $row) {
                unset($row['departs_sort']);

                return $row;
            })
            ->values();
    }

    /** อยู่ในช่วงที่รถของรอบนี้ควรมีตำแหน่งให้ติดตามไหม */
    public function withinSharingWindow(TripSchedule $schedule, ?Carbon $now = null): bool
    {
        [$start, $end] = $this->sharingWindow($schedule);

        return ($now ?? $this->nowThai())->betweenIncluded($start, $end);
    }

    /**
     * "ตอนนี้" ในกรอบเดียวกับที่ departs_at/departure_date ถูกเก็บ
     *
     * คอลัมน์พวกนี้เก็บตัวเลขนาฬิกาไทยตรง ๆ ในชนิด UTC (ดู SendDepartureSoonRemindersJob)
     * การเทียบกับ now('Asia/Bangkok') ซึ่งเป็น "ขณะเดียวกัน" จะคลาดไป 7 ชั่วโมงเสมอ
     * — รอบที่รถออก 23:30 คืนก่อนหน้าเคยเปิดแชร์ไม่ได้จนถึง 18:30 แทนที่จะเป็น 11:30
     */
    private function nowThai(): Carbon
    {
        return Carbon::parse(now(self::TIMEZONE)->format('Y-m-d H:i:s'));
    }
}
