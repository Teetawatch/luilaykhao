<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends a warm "เดินทางกลับโดยสวัสดิภาพ" in-app + push (FCM) message to everyone
 * who travelled today, 30 minutes after the 20:00 review invite. Mirrors
 * SendReviewInvitesJob: it targets the same bookings (trips wrapping up today,
 * review window already open) and nudges every traveller on the booking — the
 * owner AND every companion who accepted the invite. Deduplicated per traveller
 * so each person only ever receives it once per booking.
 */
class SendSafeTravelsJob implements ShouldQueue
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
                        // Return date = return_date, falling back to departure_date.
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

            // Defensive: only send once the 20:00 review window has opened, so the
            // trip has genuinely wrapped up.
            if (! $schedule || ! $schedule->isReviewAvailable()) {
                continue;
            }

            foreach ($booking->accessUserIds() as $userId) {
                if ($this->alreadySent($booking, $userId)) {
                    continue;
                }

                SmartNotification::send(
                    $userId,
                    'safe_travels',
                    'เดินทางกลับโดยสวัสดิภาพนะครับ 🙏',
                    'ขอให้ทุกท่านเดินทางกลับโดยสวัสดิภาพ หากมีข้อผิดพลาดประการใด'
                        .'ทางเราขออภัยด้วยครับ เราสัญญาว่าจะพัฒนาให้ดียิ่งๆขึ้นไป '
                        .'แล้วพบกันใหม่ทริปหน้า เมื่อมีโอกาสขอให้พวกเราได้ดูแลอีกครั้งครับ',
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

        Log::info('SendSafeTravelsJob completed', ['sent' => $sent]);
    }

    /**
     * Guard against double-sending if the job runs more than once.
     */
    private function alreadySent(Booking $booking, int $userId): bool
    {
        return SmartNotification::where('user_id', $userId)
            ->where('type', 'safe_travels')
            ->where('data->booking_ref', $booking->booking_ref)
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendSafeTravelsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
