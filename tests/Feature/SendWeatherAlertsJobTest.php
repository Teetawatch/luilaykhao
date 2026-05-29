<?php

namespace Tests\Feature;

use App\Jobs\SendWeatherAlertsJob;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendWeatherAlertsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.weather.provider' => 'openweather',
            'services.weather.api_key' => 'test-key',
            'services.weather.base_url' => 'https://api.openweathermap.org',
        ]);

        Mail::fake();
    }

    private function makeBooking(?float $lat, ?float $lng): Booking
    {
        $trip = Trip::create([
            'title' => 'Doi Trip',
            'slug' => 'doi-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $user = User::factory()->create();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Mr.',
                'name' => 'Passenger 1',
                'phone' => '0812345678',
                'email' => 'p1@example.test',
            ]],
        );

        // BookingService may create the booking as pending; the alert targets
        // confirmed bookings.
        $booking->update(['status' => 'confirmed']);

        return $booking->fresh();
    }

    private function fakeForecast(int $code, float $pop, float $rain): void
    {
        $date = now()->addDay()->toDateString();
        Http::fake([
            '*/data/2.5/forecast*' => Http::response([
                'city' => ['timezone' => 25200],
                'list' => [[
                    'dt' => Carbon::parse("{$date} 12:00", '+07:00')->timestamp,
                    'main' => ['temp_min' => 24, 'temp_max' => 30],
                    'weather' => [['id' => $code, 'description' => 'desc', 'icon' => '10d']],
                    'wind' => ['speed' => 5],
                    'pop' => $pop,
                    'rain' => ['3h' => $rain],
                ]],
            ], 200),
        ]);
    }

    public function test_sends_alert_to_booked_users_when_weather_is_bad(): void
    {
        $this->fakeForecast(code: 202, pop: 0.9, rain: 12); // thunderstorm → warning
        $booking = $this->makeBooking(18.788, 98.985);

        (new SendWeatherAlertsJob)->handle(app(WeatherService::class));

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $booking->user_id,
            'type' => 'weather_alert',
        ]);
    }

    public function test_no_alert_when_weather_is_clear(): void
    {
        $this->fakeForecast(code: 800, pop: 0.0, rain: 0); // clear → none
        $this->makeBooking(18.788, 98.985);

        (new SendWeatherAlertsJob)->handle(app(WeatherService::class));

        $this->assertSame(0, SmartNotification::where('type', 'weather_alert')->count());
    }

    public function test_does_not_send_duplicate_on_second_run(): void
    {
        $this->fakeForecast(code: 202, pop: 0.9, rain: 12);
        $this->makeBooking(18.788, 98.985);

        $service = app(WeatherService::class);
        (new SendWeatherAlertsJob)->handle($service);
        (new SendWeatherAlertsJob)->handle($service);

        $this->assertSame(1, SmartNotification::where('type', 'weather_alert')->count());
    }

    public function test_skips_trips_without_coordinates(): void
    {
        Http::fake(); // would fail if called
        $this->makeBooking(null, null);

        (new SendWeatherAlertsJob)->handle(app(WeatherService::class));

        $this->assertSame(0, SmartNotification::where('type', 'weather_alert')->count());
        Http::assertNothingSent();
    }
}
