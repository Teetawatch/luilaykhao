<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\SmsService;
use Illuminate\Console\Command;

class SendBookingSmsReminders extends Command
{
    protected $signature = 'sms:booking-reminders';
    protected $description = 'Send SMS reminders for upcoming confirmed trip bookings.';

    public function handle(SmsService $smsService): int
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

        $this->info("Queued {$sent} booking reminder SMS message(s).");

        return self::SUCCESS;
    }
}
