<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Services\MailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A few days before departure, warn customers whose round still hasn't reached
 * the guaranteed minimum number of booked seats — the trip may be cancelled.
 *
 * Runs once a day and targets rounds departing exactly DAYS_BEFORE days out, so
 * each booking is naturally notified at most once (mirrors SendBalanceDueRemindersJob).
 */
class SendUnderfilledTripWarningsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** How many days before departure the warning is sent. */
    private const DAYS_BEFORE = 5;

    /** Minimum booked seats that guarantees the round runs. */
    private const MIN_SEATS = 8;

    public function handle(MailService $mailService): void
    {
        $totals = ['schedules' => 0, 'emailed' => 0];

        $targetDate = now('Asia/Bangkok')->addDays(self::DAYS_BEFORE)->toDateString();

        $schedules = TripSchedule::query()
            ->departingOn($targetDate)
            ->where('status', '!=', 'cancelled')
            ->where('booked_seats', '<', self::MIN_SEATS)
            ->where('booked_seats', '>', 0)
            ->with('trip')
            ->get();

        foreach ($schedules as $schedule) {
            $totals['schedules']++;

            $bookings = Booking::query()
                ->where('schedule_id', $schedule->id)
                ->where('status', 'confirmed')
                ->with(['user', 'passengers', 'pickupPoint', 'schedule.trip'])
                ->get();

            foreach ($bookings as $booking) {
                try {
                    $mailService->sendTripUnderfilledWarningEmail(
                        $booking,
                        self::DAYS_BEFORE,
                        (int) $schedule->booked_seats,
                        self::MIN_SEATS,
                    );
                    $totals['emailed']++;
                } catch (\Throwable $e) {
                    Log::error('Failed to send trip underfilled warning email', [
                        'booking_ref' => $booking->booking_ref,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($booking->user_id) {
                    SmartNotification::send(
                        $booking->user_id,
                        'trip_underfilled_warning',
                        'ทริปอาจถูกยกเลิก',
                        "ทริป{$schedule->trip->title} เหลือเวลาอีก ".self::DAYS_BEFORE.' วัน แต่ยังมีผู้จองไม่ถึง '.self::MIN_SEATS.' ที่นั่ง หากไม่ครบทริปอาจถูกยกเลิกและคืนเงินเต็มจำนวน',
                        ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
                    );
                }
            }
        }

        Log::info('SendUnderfilledTripWarningsJob completed', $totals);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendUnderfilledTripWarningsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
