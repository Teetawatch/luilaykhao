<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\ScheduleItineraryItem;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Observers\BookingObserver;
use App\Observers\ScheduleItineraryItemObserver;
use App\Observers\TripObserver;
use App\Observers\TripScheduleObserver;
use App\Services\SaleCampaignService;
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
        // แคมเปญวันพิเศษถูกถามซ้ำทุกครั้งที่คิดราคา — singleton ทำให้ยิง DB
        // ครั้งเดียวต่อ request แล้วจำคำตอบไว้
        $this->app->singleton(SaleCampaignService::class);
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
        ScheduleItineraryItem::observe(ScheduleItineraryItemObserver::class);
    }
}
