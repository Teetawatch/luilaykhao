<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('installment:remind')->dailyAt('08:00')->timezone('Asia/Bangkok');
Schedule::command('deposit:remind-balance')->dailyAt('08:10')->timezone('Asia/Bangkok');
Schedule::command('sms:booking-reminders')->dailyAt('08:15')->timezone('Asia/Bangkok');
Schedule::command('sms:send-pending')->everyFiveMinutes();
Schedule::command('eta:notify-pickups')->everyMinute()->withoutOverlapping();
