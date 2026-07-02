<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fires a push (FCM) reminder ~2–3 hours before a booking's *actual* departure
 * time (`departs_at`). This fills the gap between the day-before reminder and
 * the minute-level pickup ETA push.
 *
 * Keyed on `departs_at`, so it naturally covers rounds whose vehicle leaves the
 * night before the trip date. Only rounds with an explicit departure time
 * qualify — day-only rounds can't be timed to the hour and keep the day-before
 * reminder instead. Sent once per booking (dedupe via SmartNotification).
 *
 * Timezone note: `departs_at` is stored as Thai wall-clock (never converted),
 * while the app timezone is UTC — so the window is built from now('Asia/Bangkok')
 * whose formatted value matches the stored digits.
 */
class SendDepartureSoonRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    private const TIMEZONE = 'Asia/Bangkok';

    /** Remind once the real departure is within this many hours. */
    private const LEAD_HOURS = 3;

    public function handle(): void
    {
        $now = Carbon::now(self::TIMEZONE);
        $windowEnd = $now->copy()->addHours(self::LEAD_HOURS);

        $bookings = Booking::where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->whereHas('schedule', function ($query) use ($now, $windowEnd) {
                // เวลาออกรถจริง (departs_at, เวลาไทย) — รองรับรถที่ออกคืนก่อนวันทริป
                $query->whereNotNull('departs_at')
                    ->where('departs_at', '>=', $now)
                    ->where('departs_at', '<=', $windowEnd)
                    ->where('status', '!=', 'cancelled');
            })
            ->with('schedule.trip')
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            if ($this->alreadySent($booking)) {
                continue;
            }

            $schedule = $booking->schedule;
            $departsAt = $schedule?->departs_at;
            if (! $departsAt) {
                continue;
            }

            $tripTitle = $schedule?->trip?->title ?? 'ทริปของคุณ';
            $timeLabel = $departsAt->format('H:i');

            // รถที่ออกก่อนวันทริป (เช่น ทริปเสาร์ แต่รถออกศุกร์คืน)
            $nightBefore = $schedule->departure_date
                && $departsAt->toDateString() < $schedule->departure_date->toDateString();
            $whenNote = $nightBefore ? ' (รถออกก่อนวันทริป)' : '';

            SmartNotification::send(
                $booking->user_id,
                'trip_departure_soon',
                'ใกล้เวลาออกเดินทางแล้ว! 🚐',
                "{$tripTitle} ออกเดินทางเวลา {$timeLabel} น.{$whenNote} "
                    .'เตรียมของให้พร้อมและมาถึงจุดนัดก่อนเวลานะ',
                [
                    'booking_ref' => $booking->booking_ref,
                    'trip_id' => $schedule?->trip_id,
                    'schedule_id' => $booking->schedule_id,
                    'route' => 'booking',
                ],
            );

            $sent++;
        }

        Log::info('SendDepartureSoonRemindersJob completed', ['sent' => $sent]);
    }

    /**
     * One reminder per booking, even if the job runs repeatedly while the
     * booking sits inside the pre-departure window.
     */
    private function alreadySent(Booking $booking): bool
    {
        return SmartNotification::where('user_id', $booking->user_id)
            ->where('type', 'trip_departure_soon')
            ->where('data->booking_ref', $booking->booking_ref)
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendDepartureSoonRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
