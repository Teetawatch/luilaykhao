<?php

namespace App\Services;

use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\TripAlertDispatch;
use App\Models\TripSchedule;
use App\Support\ThaiDate;
use Illuminate\Support\Facades\Log;

/**
 * Drives "แจ้งเตือนฉัน" (Price & Availability Alerts) — turns a per-trip
 * subscription into push notifications when price drops, a new schedule opens,
 * or seats run low. Delivery goes through SmartNotification (DB row + FCM).
 */
class TripAlertService
{
    /**
     * Fan out a "new schedule opened" alert to everyone watching this trip.
     * Called from the TripSchedule observer when a schedule becomes bookable.
     */
    public function notifyNewSchedule(TripSchedule $schedule): void
    {
        if (! $this->isBookable($schedule)) {
            return;
        }

        $trip = $schedule->trip;
        if (! $trip) {
            return;
        }

        $alerts = TripAlert::where('trip_id', $trip->id)
            ->where('alert_new_schedule', true)
            ->get();

        foreach ($alerts as $alert) {
            if ($this->alreadyDispatched($alert, $schedule->id, 'new_schedule')) {
                continue;
            }

            $this->dispatch(
                $alert,
                $schedule->id,
                'new_schedule',
                "เปิดรอบใหม่: {$trip->title}",
                'เปิดให้จองรอบวันที่ '.ThaiDate::full($schedule->departure_date).' แล้ว รีบจองก่อนเต็ม!',
                $trip,
                $schedule->id,
            );
        }
    }

    /**
     * Periodic sweep (scheduled job): price-drop and low-seat alerts across all
     * active subscriptions. Idempotent — dedupes via last_notified_price and the
     * dispatch log so each event fires at most once.
     */
    public function processAll(): void
    {
        TripAlert::with(['trip.schedules'])->chunkById(200, function ($alerts) {
            foreach ($alerts as $alert) {
                try {
                    $this->processPriceDrop($alert);
                    $this->processLowSeats($alert);
                } catch (\Throwable $e) {
                    Log::warning('Trip alert processing failed', [
                        'trip_alert_id' => $alert->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    private function processPriceDrop(TripAlert $alert): void
    {
        $trip = $alert->trip;
        if (! $trip) {
            return;
        }

        $currentMin = $this->minBookablePrice($trip);
        if ($currentMin === null) {
            return;
        }

        $previous = $alert->last_notified_price !== null ? (float) $alert->last_notified_price : null;

        // First observation just sets the baseline — no alert.
        if ($previous === null) {
            $alert->update(['last_notified_price' => $currentMin]);

            return;
        }

        if ($alert->alert_price_drop && $currentMin < $previous) {
            $this->dispatch(
                $alert,
                null,
                'price_drop',
                "ราคาลดลง: {$trip->title}",
                'ราคาลดเหลือ '.number_format($currentMin).' บาท/คน (จาก '.number_format($previous).' บาท) จองเลย!',
                $trip,
                null,
            );
        }

        // Track the latest price either way so a later drop re-triggers correctly.
        if ($currentMin != $previous) {
            $alert->update(['last_notified_price' => $currentMin]);
        }
    }

    /**
     * Real-time low-seat fan-out for one round to everyone watching its trip.
     * Called from BookingService the moment a booking drops the round into the
     * low band, so fast-selling rounds don't slip past the periodic sweep.
     */
    public function notifyLowSeats(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip) {
            return;
        }

        TripAlert::where('trip_id', $trip->id)
            ->where('alert_low_seats', true)
            ->each(fn (TripAlert $alert) => $this->maybeNotifyLowSeats($alert, $schedule, $trip));
    }

    /**
     * Real-time "sold out" fan-out to everyone watching this trip — the terminal
     * seat event, so it rides on the same low-seat subscription flag. Once per round.
     */
    public function notifySoldOut(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip || ! $this->isBookable($schedule) || $schedule->available_seats > 0) {
            return;
        }

        TripAlert::where('trip_id', $trip->id)
            ->where('alert_low_seats', true)
            ->each(function (TripAlert $alert) use ($schedule, $trip) {
                if ($this->alreadyDispatched($alert, $schedule->id, 'sold_out')) {
                    return;
                }

                $this->dispatch(
                    $alert,
                    $schedule->id,
                    'sold_out',
                    "เต็มแล้ว: {$trip->title}",
                    'รอบวันที่ '.ThaiDate::full($schedule->departure_date).' เต็มทุกที่นั่งแล้ว กดเข้าคิว waitlist เผื่อมีที่ว่าง!',
                    $trip,
                    $schedule->id,
                );
            });
    }

    /**
     * ที่นั่งว่างคืนมา (ยกเลิก/ลบการจอง) ในรอบที่ยังตึงอยู่ — บอกคนที่ติดตามทริปนี้
     * ใช้แฟล็กเดียวกับ low-seats เพราะเป็นเรื่อง "ความว่างของที่นั่ง" เหมือนกัน
     * และ dedupe แยกตามจำนวนที่ว่าง เพื่อไม่ยิงซ้ำเวลาลบหลายรายการติดกัน
     */
    public function notifySeatsFreed(TripSchedule $schedule): void
    {
        $trip = $schedule->trip;
        if (! $trip || ! $this->isBookable($schedule)) {
            return;
        }

        $available = $schedule->available_seats;
        if ($available <= 0) {
            return;
        }

        TripAlert::where('trip_id', $trip->id)
            ->where('alert_low_seats', true)
            ->each(function (TripAlert $alert) use ($schedule, $trip, $available) {
                // เคารพเกณฑ์ที่ผู้ใช้ตั้งเอง — รอบที่ว่างเยอะไม่ใช่ข่าวด่วนสำหรับเขา
                if ($available > ($alert->low_seat_threshold ?: 5)) {
                    return;
                }

                $dedupeType = "seats_freed:{$available}";
                if ($this->alreadyDispatched($alert, $schedule->id, $dedupeType)) {
                    return;
                }

                $this->dispatch(
                    $alert,
                    $schedule->id,
                    'seats_freed',
                    "มีที่นั่งว่าง: {$trip->title}",
                    'รอบวันที่ '.ThaiDate::full($schedule->departure_date)
                        ." มีคนสละสิทธิ์ เหลือที่ว่าง {$available} ที่นั่ง รีบจองก่อนเต็มอีกรอบ!",
                    $trip,
                    $schedule->id,
                    $dedupeType,
                );
            });
    }

    private function processLowSeats(TripAlert $alert): void
    {
        if (! $alert->alert_low_seats) {
            return;
        }

        $trip = $alert->trip;
        if (! $trip) {
            return;
        }

        foreach ($trip->schedules as $schedule) {
            $this->maybeNotifyLowSeats($alert, $schedule, $trip);
        }
    }

    /**
     * Fire a low-seat alert for one (subscription, round) pair if the round is
     * inside this subscriber's threshold and that seat level hasn't fired yet.
     * Dedupe is per seat level ("low_seats:3" / ":2" / ":1") so each step down
     * toward sold-out re-alerts exactly once.
     */
    private function maybeNotifyLowSeats(TripAlert $alert, TripSchedule $schedule, Trip $trip): void
    {
        if (! $alert->alert_low_seats || ! $this->isBookable($schedule)) {
            return;
        }

        $available = $schedule->available_seats;
        $threshold = $alert->low_seat_threshold ?: 5;
        if ($available <= 0 || $available > $threshold) {
            return;
        }

        $dedupeType = "low_seats:{$available}";
        if ($this->alreadyDispatched($alert, $schedule->id, $dedupeType)) {
            return;
        }

        $this->dispatch(
            $alert,
            $schedule->id,
            'low_seats',
            "ที่นั่งใกล้เต็ม: {$trip->title}",
            'รอบวันที่ '.ThaiDate::full($schedule->departure_date)." เหลือเพียง {$available} ที่นั่ง รีบจองก่อนหมด!",
            $trip,
            $schedule->id,
            $dedupeType,
        );
    }

    private function minBookablePrice(Trip $trip): ?float
    {
        $prices = $trip->schedules
            ->filter(fn (TripSchedule $s) => $this->isBookable($s))
            ->map(fn (TripSchedule $s) => (float) $s->effective_price)
            ->filter(fn (float $p) => $p > 0);

        return $prices->isEmpty() ? null : (float) $prices->min();
    }

    private function isBookable(TripSchedule $schedule): bool
    {
        return $schedule->status === 'open'
            && $schedule->departure_date !== null
            && $schedule->departure_date->gte(now()->startOfDay());
    }

    private function alreadyDispatched(TripAlert $alert, ?int $scheduleId, string $type): bool
    {
        return TripAlertDispatch::where('trip_alert_id', $alert->id)
            ->where('type', $type)
            ->where('schedule_id', $scheduleId)
            ->exists();
    }

    private function dispatch(
        TripAlert $alert,
        ?int $scheduleId,
        string $type,
        string $title,
        string $body,
        Trip $trip,
        ?int $notificationScheduleId,
        ?string $dedupeType = null,
    ): void {
        // Ledger row dedupes on the (optionally per-level) key; the in-app
        // notification keeps the plain `alert_type` so the app routing stays simple.
        TripAlertDispatch::create([
            'trip_alert_id' => $alert->id,
            'schedule_id' => $scheduleId,
            'type' => $dedupeType ?? $type,
        ]);

        SmartNotification::send(
            $alert->user_id,
            'trip_alert',
            $title,
            $body,
            [
                'route' => 'trip',
                'alert_type' => $type,
                'trip_slug' => $trip->slug,
                'trip_id' => $trip->id,
                'schedule_id' => $notificationScheduleId,
            ],
        );
    }
}
