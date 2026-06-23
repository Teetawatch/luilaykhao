<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Review;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends a warm in-app + push (FCM) invite to review a trip as soon as it wraps
 * up. The review window opens at 20:00 (Asia/Bangkok) on the trip's last day —
 * see TripSchedule::reviewAvailableAt() — so this job runs just after that and
 * nudges everyone on the booking who hasn't reviewed yet: the owner AND every
 * companion who accepted the invite. Deduplicated per traveller so each person
 * is only ever invited once.
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

            $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';

            // Invite the owner AND every companion who accepted the invite —
            // each traveller reviews on their own account, once.
            foreach ($booking->accessUserIds() as $userId) {
                if ($this->alreadyReviewed($booking->id, $userId)) {
                    continue;
                }

                if ($this->alreadyInvited($booking, $userId)) {
                    continue;
                }

                SmartNotification::send(
                    $userId,
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
        }

        Log::info('SendReviewInvitesJob completed', ['sent' => $sent]);
    }

    /**
     * This traveller already reviewed this booking — don't nudge them again.
     */
    private function alreadyReviewed(int $bookingId, int $userId): bool
    {
        return Review::where('booking_id', $bookingId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Guard against double-sending if the job runs more than once.
     */
    private function alreadyInvited(Booking $booking, int $userId): bool
    {
        return SmartNotification::where('user_id', $userId)
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
