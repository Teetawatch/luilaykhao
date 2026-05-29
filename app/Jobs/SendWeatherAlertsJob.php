<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Services\WeatherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Evening-before heads-up: for trips departing tomorrow, look up the
 * departure-day forecast and, when it's rough (advisory/warning), notify every
 * customer booked on the schedule so they can pack accordingly.
 *
 * Informational only — the trip still departs as scheduled; there is no
 * cancellation path here. Scheduled daily at 18:00 (Asia/Bangkok).
 */
class SendWeatherAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(WeatherService $weather): void
    {
        $date = now()->addDay()->toDateString();

        $bookings = Booking::where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->whereHas('schedule', function ($query) use ($date) {
                $query->whereDate('departure_date', $date)
                    ->where('status', '!=', 'cancelled');
            })
            ->whereHas('schedule.trip', function ($query) {
                $query->whereNotNull('latitude')->whereNotNull('longitude');
            })
            ->with('schedule.trip')
            ->get();

        // One forecast lookup per schedule, shared across its bookings.
        $forecasts = [];
        $sent = 0;

        foreach ($bookings as $booking) {
            $schedule = $booking->schedule;
            $trip = $schedule?->trip;
            if (! $schedule || ! $trip) {
                continue;
            }

            $scheduleId = $schedule->id;
            if (! array_key_exists($scheduleId, $forecasts)) {
                $forecasts[$scheduleId] = $weather->forecastFor(
                    (float) $trip->latitude,
                    (float) $trip->longitude,
                    $date,
                );
            }

            $forecast = $forecasts[$scheduleId];
            if (! $forecast || $forecast->severity === 'none') {
                continue;
            }

            if ($this->alreadySent($booking->user_id, $scheduleId, $date)) {
                continue;
            }

            $advice = $weather->thaiAdvice($forecast, $trip->title ?? 'ทริปของคุณ');

            SmartNotification::send(
                $booking->user_id,
                'weather_alert',
                $advice['title'],
                $advice['body'],
                [
                    'schedule_id' => $scheduleId,
                    'trip_id' => $trip->id,
                    'booking_ref' => $booking->booking_ref,
                    'forecast_date' => $date,
                    'severity' => $forecast->severity,
                    'route' => 'booking',
                ],
            );

            $sent++;
        }

        Log::info('SendWeatherAlertsJob completed', ['date' => $date, 'sent' => $sent]);
    }

    /**
     * Guard against double-sending if the daily job runs more than once.
     */
    private function alreadySent(int $userId, int $scheduleId, string $date): bool
    {
        return SmartNotification::where('user_id', $userId)
            ->where('type', 'weather_alert')
            ->where('data->schedule_id', $scheduleId)
            ->where('data->forecast_date', $date)
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendWeatherAlertsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
