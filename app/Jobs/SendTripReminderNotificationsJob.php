<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends in-app + push (FCM) pre-departure reminders 7 and 1 days before the
 * trip, deep-linking to the booking detail (which carries the checklist).
 * SMS reminders are handled separately by SendBookingRemindersJob.
 */
class SendTripReminderNotificationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    private const REMIND_DAYS_BEFORE = [7, 1];

    public function handle(): void
    {
        $sent = 0;

        foreach (self::REMIND_DAYS_BEFORE as $daysBefore) {
            $bookings = Booking::where('status', 'confirmed')
                ->whereNotNull('user_id')
                ->whereHas('schedule', function ($query) use ($daysBefore) {
                    $query->whereDate('departure_date', now()->addDays($daysBefore)->toDateString())
                        ->where('status', '!=', 'cancelled');
                })
                ->with('schedule.trip')
                ->get();

            foreach ($bookings as $booking) {
                if ($this->alreadySent($booking, $daysBefore)) {
                    continue;
                }

                $tripTitle = $booking->schedule?->trip?->title ?? 'ทริปของคุณ';

                $title = $daysBefore === 1
                    ? 'พรุ่งนี้ออกเดินทางแล้ว! 🎒'
                    : "อีก {$daysBefore} วันจะถึงวันเดินทาง";

                $body = $daysBefore === 1
                    ? "{$tripTitle} ออกเดินทางพรุ่งนี้ อย่าลืมเช็กรายการสิ่งที่ต้องเตรียมให้พร้อม"
                    : "{$tripTitle} ใกล้ถึงแล้ว เริ่มเตรียมของตามเช็กลิสต์ได้เลย";

                SmartNotification::send(
                    $booking->user_id,
                    'trip_reminder',
                    $title,
                    $body,
                    [
                        'booking_ref' => $booking->booking_ref,
                        'trip_id' => $booking->schedule?->trip_id,
                        'schedule_id' => $booking->schedule_id,
                        'days_before' => $daysBefore,
                        'route' => 'booking',
                    ],
                );

                $sent++;
            }
        }

        Log::info('SendTripReminderNotificationsJob completed', ['sent' => $sent]);
    }

    /**
     * Guard against double-sending if the daily job runs more than once.
     */
    private function alreadySent(Booking $booking, int $daysBefore): bool
    {
        return SmartNotification::where('user_id', $booking->user_id)
            ->where('type', 'trip_reminder')
            ->where('data->booking_ref', $booking->booking_ref)
            ->where('data->days_before', $daysBefore)
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTripReminderNotificationsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
