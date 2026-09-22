<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\VehicleLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * "รถออกเดินทางแล้ว" — ประกาศเองจากการที่รถเริ่มวิ่ง ไม่ต้องมีใครกดปุ่ม
 *
 * ข้อความนี้มีมาตั้งแต่แรกแต่ไม่เคยถึงลูกค้าสักรอบ เพราะคนเดียวที่ยิงมันได้คือ
 * แอปคนขับ ซึ่งคนขับไม่ได้ใช้ และการย้ายปุ่มมาให้สตาฟกดก็ไม่ใช่คำตอบ — นาทีที่
 * รถออกคือนาทีที่สตาฟกำลังนับหัว เช็คอิน และตอบคำถามลูกค้าอยู่พอดี
 *
 * สิ่งที่เชื่อได้แทนคือพิกัดที่ไหลเข้ามาอยู่แล้ว: รถที่ "ออกเดินทาง" คือรถที่
 * เคลื่อนไปไกลจากจุดที่มันจอดอยู่ หรือกำลังวิ่งด้วยความเร็วที่เดินไม่ได้
 *
 * เกณฑ์ตั้งใจให้แน่น เพราะมือถือของสตาฟคือ GPS ของรถ: สตาฟขี่มอเตอร์ไซค์ไปขึ้นรถ
 * ที่อู่ก็ขยับเหมือนกัน การประกาศผิดแปลว่าลูกค้ารีบลงมายืนรอข้างถนนก่อนเวลา
 */
class TripDepartureService
{
    /** ข้อความประจำห้องแชท — กันประกาศซ้ำระดับฐานข้อมูล */
    public const CHAT_KEY = 'trip_departed';

    /** ต้องเคลื่อนจากจุดตั้งต้นเกินกี่เมตรถึงนับว่าออกเดินทาง */
    public const DEPARTED_METERS = 1500;

    /** หรือกำลังวิ่งเร็วกว่านี้ (กม./ชม.) — เร็วเกินกว่าจะเป็นคนเดิน */
    public const DEPARTED_SPEED_KMH = 30;

    /** เริ่มจับการเคลื่อนไหวก่อนเวลารถออกกี่นาที */
    public const WATCH_BEFORE_MINUTES = 45;

    /** หลังเวลารถออกกี่ชั่วโมงเลิกจับ (ออกช้าได้ แต่ไม่ทั้งวัน) */
    public const WATCH_AFTER_HOURS = 6;

    /** พิกัดเก่ากว่านี้ไม่ใช่ "ตอนนี้" (นาที) */
    public const FRESH_MINUTES = 20;

    private const TIMEZONE = 'Asia/Bangkok';

    public function __construct(private ChatService $chatService) {}

    /**
     * ประกาศให้ผู้โดยสารรู้ว่ารถออกแล้ว — เรียกซ้ำไม่ส่งซ้ำ
     *
     * @return int จำนวนใบจองที่ได้รับแจ้ง (0 = เคยประกาศไปแล้ว)
     */
    public function announce(TripSchedule $schedule): int
    {
        if ($this->alreadyAnnounced($schedule)) {
            return 0;
        }

        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';

        $bookings = Booking::where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->get(['id', 'booking_ref', 'user_id']);

        foreach ($bookings as $booking) {
            SmartNotification::send(
                $booking->user_id,
                'vehicle_departed',
                'รถออกเดินทางแล้ว 🚐',
                "รถทริป \"{$tripTitle}\" ออกเดินทางแล้ว ติดตามตำแหน่งรถแบบเรียลไทม์ได้เลย",
                [
                    'booking_ref' => $booking->booking_ref,
                    'vehicle_id' => $schedule->vehicle_id,
                    'schedule_id' => $schedule->id,
                    'route' => 'booking',
                ],
            );
        }

        // ลงห้องแชทด้วย — คนที่ปิดแจ้งเตือนไว้ยังเห็นย้อนหลังได้ และ system_key
        // นี่เองที่ทำหน้าที่กันประกาศซ้ำ (unique ระดับ DB) แทนแคชที่หายได้
        $this->chatService->ensureWelcome($schedule);
        $this->chatService->postSystem(
            $schedule,
            'รถออกเดินทางแล้ว 🚐 ติดตามตำแหน่งรถแบบเรียลไทม์ได้จากหน้า “วันเดินทาง” เลยครับ',
            self::CHAT_KEY,
        );

        Log::info('TripDepartureService: ประกาศรถออกเดินทาง', [
            'schedule_id' => $schedule->id,
            'notified' => $bookings->count(),
        ]);

        return $bookings->count();
    }

    public function alreadyAnnounced(TripSchedule $schedule): bool
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->where('system_key', self::CHAT_KEY)
            ->exists();
    }

    /**
     * รถของรอบนี้เริ่มวิ่งแล้วหรือยัง ตามพิกัดที่ไหลเข้ามา
     */
    public function hasDeparted(TripSchedule $schedule, ?Carbon $now = null): bool
    {
        if (! $schedule->vehicle_id) {
            return false;
        }

        $now ??= $this->nowThai();
        $departsAt = $this->departsAtThai($schedule);

        if (! $departsAt) {
            return false;
        }

        $from = $departsAt->copy()->subMinutes(self::WATCH_BEFORE_MINUTES);
        $until = $departsAt->copy()->addHours(self::WATCH_AFTER_HOURS);

        if ($now->lt($from) || $now->gt($until)) {
            return false;
        }

        // พิกัดเก็บด้วย now() (เวลาจริงตาม app tz) ไม่ใช่เวลาไทยแบบ departs_at —
        // ต้องแปลงกรอบสองชั้น: ตีความ wall-clock เป็นเวลาไทยก่อน แล้วค่อยย้ายเข้า
        // เขตเวลาของแอป ถ้าส่ง Carbon ที่ยังเป็นเขตไทยเข้า query ไปตรง ๆ Laravel
        // จะ format ตามเขตของมันเอง กลายเป็นเทียบ "20:22 ไทย" กับ "14:02 UTC"
        $windowStart = Carbon::parse($from->format('Y-m-d H:i:s'), self::TIMEZONE)
            ->setTimezone(config('app.timezone', 'UTC'));

        $locations = VehicleLocation::where('vehicle_id', $schedule->vehicle_id)
            ->where('recorded_at', '>=', $windowStart)
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'speed', 'recorded_at']);

        if ($locations->count() < 2) {
            return false;
        }

        $latest = $locations->last();

        // พิกัดล่าสุดต้องสด ไม่งั้นคือรอบที่ปิดแชร์ไปแล้ว
        if ($latest->recorded_at->lt(now()->subMinutes(self::FRESH_MINUTES))) {
            return false;
        }

        if ((float) ($latest->speed ?? 0) >= self::DEPARTED_SPEED_KMH) {
            return true;
        }

        $first = $locations->first();

        return $this->metresBetween(
            (float) $first->latitude,
            (float) $first->longitude,
            (float) $latest->latitude,
            (float) $latest->longitude,
        ) >= self::DEPARTED_METERS;
    }

    /**
     * รอบที่ควรตรวจวันนี้ แล้วประกาศให้รอบที่ออกเดินทางแล้ว
     *
     * @return int จำนวนรอบที่เพิ่งประกาศไป
     */
    public function sweep(?Carbon $now = null): int
    {
        $now ??= $this->nowThai();
        $announced = 0;

        $schedules = TripSchedule::query()
            ->departingOn(now(self::TIMEZONE))
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('vehicle_id')
            ->with('trip')
            ->get();

        foreach ($schedules as $schedule) {
            if ($this->alreadyAnnounced($schedule) || ! $this->hasDeparted($schedule, $now)) {
                continue;
            }

            $this->announce($schedule);
            $announced++;
        }

        return $announced;
    }

    /** เวลารถออกในกรอบเดียวกับที่คอลัมน์เก็บ — รอบที่ไม่ได้กรอกใช้ 06:00 เป็นตัวแทน */
    private function departsAtThai(TripSchedule $schedule): ?Carbon
    {
        return $schedule->departs_at?->copy()
            ?: $schedule->departure_date?->copy()->setTime(6, 0);
    }

    /** ดู TripActivityService::nowThai — departs_at เก็บเป็นเวลาไทยในคอลัมน์ชนิด UTC */
    private function nowThai(): Carbon
    {
        return Carbon::parse(now(self::TIMEZONE)->format('Y-m-d H:i:s'));
    }

    private function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
