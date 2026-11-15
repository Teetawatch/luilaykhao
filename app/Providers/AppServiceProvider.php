<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ForceMajeureSeatHold;
use App\Models\Review;
use App\Models\SaleCampaign;
use App\Models\ScheduleItineraryItem;
use App\Models\SchedulePickupPoint;
use App\Models\ScheduleVehicleOption;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\WaitlistEntry;
use App\Observers\BookingObserver;
use App\Observers\ScheduleItineraryItemObserver;
use App\Observers\TripObserver;
use App\Observers\TripScheduleObserver;
use App\Services\SaleCampaignService;
use App\Support\TripListCache;
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

        // รายการทริปหน้าแรกถูกแคชไว้ (TripListCache) — ของที่แสดงบนการ์ดเปลี่ยน
        // เมื่อไหร่ ทิ้งแคชทันที ไม่ต้องรอหมดอายุ (การจองใหม่เขียนลง TripSchedule
        // ผ่าน syncBookedSeats จึงติดมาด้วย)
        // ที่นั่งที่ถูกกันไว้ (คิวรอได้สิทธิ์ / เหตุสุดวิสัย) และตัวเลือกรถก็อยู่บนการ์ดด้วย
        foreach ([Trip::class, TripSchedule::class, SchedulePickupPoint::class, SaleCampaign::class, Review::class,
            ScheduleVehicleOption::class, WaitlistEntry::class, ForceMajeureSeatHold::class] as $model) {
            $model::saved(fn () => TripListCache::flush());
            $model::deleted(fn () => TripListCache::flush());
        }

        // ผังที่นั่งพักการนับใหม่ไว้ช่วงสั้น ๆ (TripSchedule::syncBookedSeatsThrottled) —
        // ใบจองหรือผู้โดยสารของรอบเปลี่ยนเมื่อไหร่ ให้นับใหม่ทันทีในคำขอถัดไป
        $forgetBookingRounds = function (Booking $booking) {
            TripSchedule::forgetSeatSyncThrottle($booking->schedule_id);
            TripSchedule::forgetSeatSyncThrottle($booking->getOriginal('schedule_id'));
        };
        Booking::saved($forgetBookingRounds);
        Booking::deleted($forgetBookingRounds);
        $forgetPassengerRound = fn (BookingPassenger $passenger) => TripSchedule::forgetSeatSyncThrottle(
            Booking::whereKey($passenger->booking_id)->value('schedule_id'),
        );
        BookingPassenger::created($forgetPassengerRound);
        BookingPassenger::deleted($forgetPassengerRound);

        // BookingService นับที่นั่งด้วย increment() ซึ่งยิงแค่ updated ไม่ยิง saved —
        // เดิมการจองใหม่จึงไม่ล้างแคช "เหลือกี่ที่" จนกว่าแคชจะหมดอายุเอง ดูเฉพาะคอลัมน์
        // ที่นั่ง เพราะ increment อื่น (เช่นยอดเปิดดูอัลบั้ม) ไม่ได้อยู่บนการ์ด
        foreach ([TripSchedule::class, ScheduleVehicleOption::class] as $model) {
            $model::updated(function ($row) {
                if ($row->wasChanged(['booked_seats', 'join_trip_booked_seats'])) {
                    TripListCache::flush();
                }
            });
        }
    }
}
