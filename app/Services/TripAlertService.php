<?php

namespace App\Services;

use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\TripAlertDispatch;
use App\Models\TripSchedule;
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
                'เปิดให้จองรอบวันที่ '.$schedule->departure_date->format('d/m/Y').' แล้ว รีบจองก่อนเต็ม!',
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

    private function processLowSeats(TripAlert $alert): void
    {
        if (! $alert->alert_low_seats) {
            return;
        }

        $trip = $alert->trip;
        if (! $trip) {
            return;
        }

        $threshold = $alert->low_seat_threshold ?: 5;

        foreach ($trip->schedules as $schedule) {
            if (! $this->isBookable($schedule)) {
                continue;
            }

            $available = $schedule->available_seats;
            if ($available <= 0 || $available > $threshold) {
                continue;
            }

            if ($this->alreadyDispatched($alert, $schedule->id, 'low_seats')) {
                continue;
            }

            $this->dispatch(
                $alert,
                $schedule->id,
                'low_seats',
                "ที่นั่งใกล้เต็ม: {$trip->title}",
                'รอบวันที่ '.$schedule->departure_date->format('d/m/Y')." เหลือเพียง {$available} ที่นั่ง รีบจองก่อนหมด!",
                $trip,
                $schedule->id,
            );
        }
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
    ): void {
        TripAlertDispatch::create([
            'trip_alert_id' => $alert->id,
            'schedule_id' => $scheduleId,
            'type' => $type,
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
