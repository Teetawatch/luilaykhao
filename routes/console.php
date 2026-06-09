<?php

use App\Jobs\BroadcastLowSeatsJob;
use App\Jobs\ExpireGroupPlansJob;
use App\Jobs\ExpirePendingBookingsJob;
use App\Jobs\ExpireWaitlistOffersJob;
use App\Jobs\ProcessTripAlertsJob;
use App\Jobs\SendReviewInvitesJob;
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
// Review window opens at 20:00 (Asia/Bangkok) on the trip's last day — invite right after.
Schedule::job(new SendReviewInvitesJob)->dailyAt('20:05')->timezone('Asia/Bangkok')->withoutOverlapping();
Schedule::command('sms:send-pending')->everyFiveMinutes();
Schedule::command('eta:notify-pickups')->everyMinute()->withoutOverlapping();
Schedule::job(new ExpireWaitlistOffersJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new ExpirePendingBookingsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new ProcessTripAlertsJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new ExpireGroupPlansJob)->everyFiveMinutes()->withoutOverlapping();
// "Almost sold out" marketing blasts — only sweep during the day; the service
// also defers any individual send that lands inside quiet hours.
Schedule::job(new BroadcastLowSeatsJob)
    ->everyFifteenMinutes()
    ->between('8:00', '21:00')
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping();
