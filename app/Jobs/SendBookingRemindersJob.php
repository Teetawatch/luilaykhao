<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\FcmService;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendBookingRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(SmsService $smsService, FcmService $fcmService): void
    {
        $sent = 0;

        foreach ([3, 1] as $daysBefore) {
            $bookings = Booking::where('status', 'confirmed')
                ->whereHas('schedule', function ($query) use ($daysBefore) {
                    // นับวันจากเวลาออกเดินทางจริง (departs_at) ไม่ใช่วันทริป
                    $query->departingOn(now()->addDays($daysBefore))
                        ->where('status', '!=', 'cancelled');
                })
                ->with(['user', 'passengers', 'schedule.trip', 'pickupPoint'])
                ->get();

            foreach ($bookings as $booking) {
                $log = $smsService->sendDepartureReminder($booking, $daysBefore);
                if ($log?->wasRecentlyCreated) {
                    $sent++;
                }

                // The day before departure, nudge the traveller to finish their
                // packing checklist. The job runs once daily, so no extra
                // dedupe guard is needed.
                if ($daysBefore === 1) {
                    $this->sendChecklistReminder($fcmService, $booking);
                }
            }
        }

        Log::info('SendBookingRemindersJob completed', ['sent' => $sent]);
    }

    private function sendChecklistReminder(FcmService $fcmService, Booking $booking): void
    {
        // Guest bookings have no account/device token to push to.
        if (! $booking->user_id) {
            return;
        }

        $tripTitle = $booking->schedule?->trip?->title ?: 'ทริปของคุณ';

        try {
            $fcmService->sendToUser(
                $booking->user_id,
                '🎒 เตรียมของก่อนเดินทาง',
                "พรุ่งนี้เดินทางทริป {$tripTitle}! แตะเพื่อเช็กของที่ต้องเตรียม",
                [
                    'type' => 'trip_checklist',
                    'booking_ref' => (string) $booking->booking_ref,
                ],
            );
        } catch (\Throwable $e) {
            // A failed push must not abort the SMS reminder run.
            Log::warning('Checklist reminder push failed', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendBookingRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
