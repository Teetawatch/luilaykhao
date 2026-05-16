<?php

namespace App\Console\Commands;

use App\Jobs\SendBookingRemindersJob;
use Illuminate\Console\Command;

class SendBookingSmsReminders extends Command
{
    protected $signature = 'sms:booking-reminders';
    protected $description = 'Send SMS reminders for upcoming confirmed trip bookings.';

    public function handle(): int
    {
        SendBookingRemindersJob::dispatch()->onQueue('reminders');
        $this->info('Dispatched SendBookingRemindersJob to queue.');

        return self::SUCCESS;
    }
}
