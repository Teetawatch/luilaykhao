<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Observers\BookingObserver;
use App\Observers\TripObserver;
use App\Observers\TripScheduleObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Line\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('line', Provider::class);
        });

        TripSchedule::observe(TripScheduleObserver::class);
        Trip::observe(TripObserver::class);
        Booking::observe(BookingObserver::class);
    }
}
