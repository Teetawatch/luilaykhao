<?php

namespace App\Services;

use App\Jobs\SendBroadcastNotificationJob;
use App\Models\BroadcastDispatch;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Support\SiteSettings;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Drives automatic, sales-driving push broadcasts to the whole customer base —
 * no manual "post an announcement" step. Each event (a freshly published trip,
 * a round running low on seats) fires once thanks to a dedupe ledger, and is
 * held until daytime so customers aren't woken at 3am.
 *
 * Delivery itself is fanned out by [SendBroadcastNotificationJob].
 */
class BroadcastNotificationService
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** Don't push before this hour (local time). */
    public const QUIET_START_HOUR = 21;

    /** …or after midnight until this hour — held messages flush at this time. */
    public const QUIET_END_HOUR = 8;

    /**
     * Seats at or below this (and above zero) trigger an "almost sold out" blast.
     * Default only — read it through [lowSeatThreshold()], which honours the
     * admin-editable site settings.
     */
    public const LOW_SEAT_THRESHOLD = 3;

    /**
     * ส่วนลดอัตโนมัติที่เปิดให้ทันทีเมื่อรอบเดินทางขยับเป็น 🟡 Almost Ready —
     * เป็นแรงจูงใจให้คนที่ลังเลรีบจองปิดรอบให้ครบการันตี (ผูกกับ flash sale
     * รายรอบที่มีอยู่แล้ว จึงได้ระบบราคาตัด/นับถอยหลังมาฟรี)
     */
    public const ALMOST_READY_DISCOUNT_PERCENT = 5;

    /** ระยะเวลาที่ดีลอัตโนมัติของสถานะ Almost Ready มีผล (ชั่วโมง). */
    public const ALMOST_READY_DEAL_HOURS = 48;

    /**
     * Time-critical events that must reach customers immediately — they are
     * exempt from quiet-hours deferral (a held flash-sale/last-seats push is
     * useless once the round fills up or the sale ends overnight).
     */
    public const URGENT_EVENTS = ['flash_sale', 'low_seats', 'sold_out'];

    /**
     * Announce a newly published trip to everyone.
     */
    public function broadcastNewTrip(Trip $trip): void
    {
        $this->broadcast(
            'new_trip',
            "new_trip:{$trip->id}",
            'ทริปใหม่มาแล้ว! 🌿',
            "{$trip->title} เปิดให้จองแล้ววันนี้ มาเป็นกลุ่มแรกที่ได้ออกเดินทางกัน!",
            [
                'route' => 'trip',
                'trip_slug' => $trip->slug,
                'trip_id' => $trip->id,
            ],
        );
    }

    /**
     * Announce a brand-new departure round of an already-published trip to
     * everyone. Skipped for the trip's very first round, since [broadcastNewTrip]
     * already covers that — otherwise customers would get two near-identical
     * pushes when a fresh trip and its first round are created together.
     */
    public function broadcastNewSchedule(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip || $trip->status !== 'active' || $schedule->departure_date === null) {
            return;
        }

        // Only treat this as a "new round" when the trip already had another
        // bookable round before this one.
        $hasOtherBookableRound = $trip->schedules()
            ->where('id', '!=', $schedule->id)
            ->where('status', 'open')
            ->whereDate('departure_date', '>=', now(self::TIMEZONE)->startOfDay())
            ->exists();
        if (! $hasOtherBookableRound) {
            return;
        }

        $this->broadcast(
            'new_schedule',
            "new_schedule:{$schedule->id}",
            'เปิดรอบใหม่แล้ว! 📅',
            "{$trip->title} เปิดจองรอบวันที่ ".ThaiDate::full($schedule->departure_date)
                .' แล้ว รีบจองก่อนเต็มนะ!',
            [
                'route' => 'trip',
                'trip_slug' => $trip->slug,
                'trip_id' => $trip->id,
                'schedule_id' => $schedule->id,
            ],
        );
    }

    /**
     * Announce a flash sale on a round to the whole customer base. Self-guarding
     * (only fires while the sale is live). The dedupe key carries the end time
     * and price, so re-opening a sale or changing its terms re-notifies, while a
     * no-op re-save does not. Safe to call from the schedule observer.
     */
    public function broadcastFlashSale(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip || $schedule->departure_date === null || ! $schedule->flashSaleActive()) {
            return;
        }

        $price = (float) $schedule->flash_sale_price;
        $original = $schedule->original_price;
        $endsClause = $schedule->flash_sale_ends_at
            ? ' ถึง '.$schedule->flash_sale_ends_at->timezone(self::TIMEZONE)->format('d/m H:i').' น.'
            : '';

        $endsKey = $schedule->flash_sale_ends_at?->timestamp ?? 'open';

        $this->broadcast(
            'flash_sale',
            "flash_sale:{$schedule->id}:{$endsKey}:".(int) $price,
            '⚡ Flash Sale ราคาพิเศษ!',
            "{$trip->title} รอบ ".ThaiDate::full($schedule->departure_date)
                .' ลดเหลือ ฿'.number_format($price)
                .($original > $price ? ' (จาก ฿'.number_format($original).')' : '')
                .$endsClause.' รีบจองก่อนหมดเวลา!',
            [
                'route' => 'trip',
                'trip_slug' => $trip->slug,
                'trip_id' => $trip->id,
                'schedule_id' => $schedule->id,
            ],
        );
    }

    /**
     * Announce a round that's almost sold out, to create urgency. Blasts once
     * per seat level (…:3, …:2, …:1) so each step toward sold-out re-creates
     * urgency. Self-guarding, so it's safe to call directly from a booking.
     */
    public function broadcastLowSeats(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip || $schedule->departure_date === null) {
            return;
        }

        $available = $schedule->available_seats;

        // Only the low band above zero blasts (a full round is handled elsewhere).
        if ($available <= 0 || $available > self::lowSeatThreshold()) {
            return;
        }

        $this->broadcast(
            'low_seats',
            "low_seats:{$schedule->id}:{$available}",
            'ที่นั่งใกล้เต็มแล้ว! ⏳',
            "{$trip->title} รอบ ".ThaiDate::full($schedule->departure_date)
                ." เหลือเพียง {$available} ที่นั่ง รีบจองก่อนเต็มนะ!",
            [
                'route' => 'trip',
                'trip_slug' => $trip->slug,
                'trip_id' => $trip->id,
                'schedule_id' => $schedule->id,
            ],
        );
    }

    /**
     * ระบบสถานะการันตีออกเดินทาง — ยิงเมื่อรอบขยับขึ้นเป็น 🟡 Almost Ready
     * (จอง 5-7 ที่นั่ง ขาดอีกนิดเดียวรถออกชัวร์) พร้อม "เปิดส่วนลดอัตโนมัติ"
     * เพื่อกระตุ้นให้ปิดรอบครบการันตี ยิงครั้งเดียวต่อรอบ (dedupe ledger)
     * self-guarding — เรียกจากการจองได้ทันที
     */
    public function broadcastAlmostReady(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip || $schedule->departure_date === null) {
            return;
        }

        // ต้องอยู่ในแถบ Almost Ready จริง ๆ (กัน race / เรียกซ้ำ)
        if ($schedule->departureStatus() !== TripSchedule::STATUS_ALMOST_READY) {
            return;
        }

        // เปิดส่วนลดอัตโนมัติ (ถ้ายังไม่มีดีลอยู่) ก่อนแต่งข้อความ เพื่อให้ push
        // โฆษณาราคาพิเศษได้ในครั้งเดียว
        $this->applyAlmostReadyDiscount($schedule);

        $seatsLeft = $schedule->seatsToGuarantee();
        $dealClause = $schedule->flashSaleActive()
            ? ' จองตอนนี้รับราคาพิเศษเพียง ฿'.number_format((float) $schedule->flash_sale_price).'/คน'
            : '';

        $this->broadcast(
            'almost_ready',
            "almost_ready:{$schedule->id}",
            '🟡 อีกนิดเดียว รถตู้การันตีออก!',
            "{$trip->title} รอบ ".ThaiDate::full($schedule->departure_date)
                ." ขาดอีกเพียง {$seatsLeft} ที่นั่ง รถตู้ก็การันตีออกเดินทางทันที!".$dealClause,
            [
                'route' => 'trip',
                'trip_slug' => $trip->slug,
                'trip_id' => $trip->id,
                'schedule_id' => $schedule->id,
            ],
        );
    }

    /**
     * ระบบสถานะการันตีออกเดินทาง — ยิงเมื่อรอบขยับขึ้นเป็น 🟢 Guaranteed Departure
     * (จองครบ 8 ที่นั่งขึ้นไป การันตีออกแน่นอน 100%) สร้าง FOMO ให้คนที่ยังลังเล
     * รีบจองที่นั่งที่เหลือ ยิงครั้งเดียวต่อรอบ self-guarding
     */
    public function broadcastGuaranteed(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip || $schedule->departure_date === null) {
            return;
        }

        if ($schedule->departureStatus() !== TripSchedule::STATUS_GUARANTEED) {
            return;
        }

        // รอบที่เต็มแล้วปล่อยให้ broadcastSoldOut จัดการ (ชวนเข้าคิว waitlist)
        if ($schedule->available_seats <= 0) {
            return;
        }

        $this->broadcast(
            'guaranteed',
            "guaranteed:{$schedule->id}",
            '🟢 การันตีออกเดินทางแน่นอน!',
            "{$trip->title} รอบ ".ThaiDate::full($schedule->departure_date)
                .' มีผู้ร่วมทางครบแล้ว ออกเดินทางแน่นอน 100% เหลืออีก '
                ."{$schedule->available_seats} ที่นั่ง รีบจองก่อนเต็ม!",
            [
                'route' => 'trip',
                'trip_slug' => $trip->slug,
                'trip_id' => $trip->id,
                'schedule_id' => $schedule->id,
            ],
        );
    }

    /**
     * เปิดส่วนลดอัตโนมัติสำหรับรอบที่ขยับเป็น Almost Ready — ผูกกับ flash sale
     * รายรอบที่มีอยู่แล้ว แต่บันทึกแบบเงียบ (saveQuietly) เพื่อไม่ให้ observer
     * ยิง broadcastFlashSale ซ้ำ (push ของ almost_ready โฆษณาดีลนี้ให้แล้ว)
     * เคารพดีลของแอดมินที่เปิดไว้ก่อน — ไม่ override
     */
    private function applyAlmostReadyDiscount(TripSchedule $schedule): void
    {
        if ($schedule->flash_sale_enabled) {
            return;
        }

        $base = $schedule->original_price;
        if ($base <= 0) {
            return;
        }

        $salePrice = floor($base * (100 - self::ALMOST_READY_DISCOUNT_PERCENT) / 100);
        if ($salePrice <= 0 || $salePrice >= $base) {
            return;
        }

        $endsAt = CarbonImmutable::now(self::TIMEZONE)->addHours(self::ALMOST_READY_DEAL_HOURS);
        $departsAt = $schedule->effectiveDepartsAt();
        if ($departsAt !== null && $endsAt->greaterThan($departsAt)) {
            $endsAt = $departsAt;
        }

        $schedule->forceFill([
            'flash_sale_enabled' => true,
            'flash_sale_price' => $salePrice,
            'flash_sale_ends_at' => $endsAt,
        ])->saveQuietly();
        $schedule->refresh();
    }

    /**
     * Announce a round that's just sold out — nudges customers onto the waitlist
     * and creates FOMO. Once per round. Self-guarding, safe to call from a booking.
     */
    public function broadcastSoldOut(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip || $schedule->departure_date === null) {
            return;
        }

        // Only blast when the round is genuinely full.
        if ($schedule->available_seats > 0) {
            return;
        }

        $this->broadcast(
            'sold_out',
            "sold_out:{$schedule->id}",
            'ที่นั่งเต็มแล้ว 🔴',
            "{$trip->title} รอบ ".ThaiDate::full($schedule->departure_date)
                .' เต็มทุกที่นั่งแล้ว กดเข้าคิว waitlist เผื่อมีที่ว่าง!',
            [
                'route' => 'trip',
                'trip_slug' => $trip->slug,
                'trip_id' => $trip->id,
                'schedule_id' => $schedule->id,
            ],
        );
    }

    /**
     * Sweep all bookable rounds and blast the ones that just dipped to a low
     * seat count. Idempotent — the dedupe ledger keeps each round to one blast
     * per seat level. Scheduled to run periodically.
     */
    public function sweepLowSeats(): void
    {
        TripSchedule::with('trip')
            ->where('status', 'open')
            ->whereDate('departure_date', '>=', now(self::TIMEZONE)->startOfDay())
            ->chunkById(200, function ($schedules) {
                foreach ($schedules as $schedule) {
                    // ทุกตัว self-guard สถานะ/threshold และ dedupe ต่อรอบเอง
                    // — จึงเรียกซ้ำได้ปลอดภัย เผื่อการจองพลาดการยิงเรียลไทม์ไป
                    $this->broadcastAlmostReady($schedule);
                    $this->broadcastGuaranteed($schedule);
                    $this->broadcastLowSeats($schedule);
                    // ตาข่ายกันพลาดสำหรับรอบที่เต็มจากทางอื่นที่ไม่ใช่การจอง
                    // (แอดมินย้ายผู้โดยสารเข้ามา ฯลฯ) — self-guard ว่าเต็มจริงและ dedupe แล้ว
                    $this->broadcastSoldOut($schedule);
                }
            });
    }

    /**
     * Claim the dedupe key, then queue delivery (deferred out of quiet hours).
     * Returns false when this event was already broadcast.
     *
     * @param  array<string, mixed>  $data
     */
    public function broadcast(
        string $eventType,
        string $dedupeKey,
        string $title,
        string $body,
        array $data = [],
    ): bool {
        // Atomically claim the key — a unique index makes a duplicate insert
        // throw, which we treat as "already sent" and swallow.
        try {
            $dispatch = BroadcastDispatch::create([
                'event_type' => $eventType,
                'dedupe_key' => $dedupeKey,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'audience' => BroadcastDispatch::AUDIENCE_ALL,
                'audience_label' => 'ลูกค้าทั้งหมด',
            ]);
        } catch (QueryException $e) {
            return false;
        }

        $job = new SendBroadcastNotificationJob($eventType, $title, $body, $data, $dispatch->id);

        // Urgency pushes (flash sale, low seats, sold out) lose all their value if
        // held overnight — the round can sell out or the sale end before morning —
        // so they bypass quiet hours and fire immediately, any hour.
        $delay = in_array($eventType, self::URGENT_EVENTS, true)
            ? null
            : $this->quietHoursDelay();
        dispatch($delay !== null ? $job->delay($delay) : $job);

        return true;
    }

    /**
     * If we're inside quiet hours, return the delay until the next allowed send
     * time; otherwise null (send now).
     */
    public function quietHoursDelay(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        // Quiet hours can be switched off entirely (send immediately, any hour) —
        // either at deploy time via config, or at runtime from the settings page.
        if (! config('services.broadcast_notifications.quiet_hours', true)
            || ! SiteSettings::bool('quiet_hours_enabled')) {
            return null;
        }

        $startHour = self::quietStartHour();
        $endHour = self::quietEndHour();

        $now = $now ?? CarbonImmutable::now(self::TIMEZONE);
        $hour = $now->hour;

        $inQuietHours = $hour >= $startHour || $hour < $endHour;
        if (! $inQuietHours) {
            return null;
        }

        // Next quiet-end hour — today if we're past midnight, tomorrow if it's
        // still the late-evening window.
        $target = $now->setTime($endHour, 0);
        if ($hour >= $startHour) {
            $target = $target->addDay();
        }

        return $target;
    }

    /** ที่นั่งเหลือเท่าไหร่จึงยิง "ใกล้เต็ม" — แอดมินปรับได้ที่หน้าตั้งค่าระบบ */
    public static function lowSeatThreshold(): int
    {
        return max(1, SiteSettings::int('low_seat_threshold'));
    }

    public static function quietStartHour(): int
    {
        return min(23, max(0, SiteSettings::int('quiet_start_hour')));
    }

    public static function quietEndHour(): int
    {
        return min(23, max(0, SiteSettings::int('quiet_end_hour')));
    }
}
