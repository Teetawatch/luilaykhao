<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendBookingRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function handle(SmsService $smsService): void
    {
        $sent = 0;

        foreach ([3, 1] as $daysBefore) {
            $bookings = Booking::where('status', 'confirmed')
                ->whereHas('schedule', function ($query) use ($daysBefore) {
                    $query->whereDate('departure_date', now()->addDays($daysBefore)->toDateString())
                        ->where('status', '!=', 'cancelled');
                })
                ->with(['user', 'passengers', 'schedule.trip', 'pickupPoint'])
                ->get();

            foreach ($bookings as $booking) {
                $log = $smsService->sendDepartureReminder($booking, $daysBefore);
                if ($log?->wasRecentlyCreated) {
                    $sent++;
                }
            }
        }

        Log::info('SendBookingRemindersJob completed', ['sent' => $sent]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendBookingRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
