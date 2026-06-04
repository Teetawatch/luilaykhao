<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends a warm in-app + push (FCM) invite to review a trip as soon as it wraps
 * up. The review window opens at 20:00 (Asia/Bangkok) on the trip's last day —
 * see TripSchedule::reviewAvailableAt() — so this job runs just after that and
 * nudges every confirmed traveller who hasn't reviewed yet. Deduplicated per
 * booking so a customer is only ever invited once.
 */
class SendReviewInvitesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(): void
    {
        $today = now(TripSchedule::REVIEW_AVAILABLE_TIMEZONE)->toDateString();

        $bookings = Booking::query()
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->whereDoesntHave('review')
            ->whereHas('schedule', function ($query) use ($today) {
                $query->where('status', '!=', 'cancelled')
                    ->where(function ($q) use ($today) {
                        // Review date = return_date, falling back to departure_date.
                        $q->whereDate('return_date', $today)
                            ->orWhere(function ($q2) use ($today) {
                                $q2->whereNull('return_date')
                                    ->whereDate('departure_date', $today);
                            });
                    });
            })
            ->with('schedule.trip')
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            $schedule = $booking->schedule;

            // Defensive: only invite once the 20:00 review window has opened.
            if (! $schedule || ! $schedule->isReviewAvailable()) {
                continue;
            }

            if ($this->alreadyInvited($booking)) {
                continue;
            }

            $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';

            SmartNotification::send(
                $booking->user_id,
                'review_invite',
                'ทริปจบแล้ว เป็นยังไงบ้างคะ? ✨',
                "หวังว่า {$tripTitle} จะเป็นความทรงจำดี ๆ ของคุณนะคะ 🌿 "
                    .'แวะมารีวิวสัก 1 นาที เล่าให้เราและเพื่อน ๆ นักเดินทางฟังหน่อยน้า เรารออ่านอยู่นะ 💚',
                [
                    'booking_ref' => $booking->booking_ref,
                    'trip_id' => $schedule->trip_id,
                    'schedule_id' => $booking->schedule_id,
                    'route' => 'booking',
                ],
            );

            $sent++;
        }

        Log::info('SendReviewInvitesJob completed', ['sent' => $sent]);
    }

    /**
     * Guard against double-sending if the job runs more than once.
     */
    private function alreadyInvited(Booking $booking): bool
    {
        return SmartNotification::where('user_id', $booking->user_id)
            ->where('type', 'review_invite')
            ->where('data->booking_ref', $booking->booking_ref)
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendReviewInvitesJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
