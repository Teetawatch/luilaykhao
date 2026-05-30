<?php

use App\Jobs\ExpireGroupPlansJob;
use App\Jobs\ExpirePendingBookingsJob;
use App\Jobs\ExpireWaitlistOffersJob;
use App\Jobs\ProcessTripAlertsJob;
use App\Jobs\SendTripReminderNotificationsJob;
use App\Jobs\SendWeatherAlertsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('installment:remind')->dailyAt('08:00')->timezone('Asia/Bangkok');
Schedule::command('deposit:remind-balance')->dailyAt('08:10')->timezone('Asia/Bangkok');
Schedule::command('sms:booking-reminders')->dailyAt('08:15')->timezone('Asia/Bangkok');
Schedule::job(new SendTripReminderNotificationsJob)->dailyAt('08:20')->timezone('Asia/Bangkok');
Schedule::job(new SendWeatherAlertsJob)->dailyAt('18:00')->timezone('Asia/Bangkok')->withoutOverlapping();
Schedule::command('sms:send-pending')->everyFiveMinutes();
Schedule::command('eta:notify-pickups')->everyMinute()->withoutOverlapping();
Schedule::job(new ExpireWaitlistOffersJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new ExpirePendingBookingsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new ProcessTripAlertsJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new ExpireGroupPlansJob)->everyFiveMinutes()->withoutOverlapping();
