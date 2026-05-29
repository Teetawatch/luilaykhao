<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingWeatherTest extends TestCase
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
    }

    private function makeBooking(?float $lat, ?float $lng, string $departure): Booking
    {
        Mail::fake();

        $trip = Trip::create([
            'title' => 'Doi Trip',
            'slug' => 'doi-trip',
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
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $user = User::factory()->create();

        return app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Mr.',
                'name' => 'Passenger 1',
                'phone' => '0812345678',
                'email' => 'p1@example.test',
            ]],
        )->fresh();
    }

    private function fakeRainyForecast(string $date): void
    {
        Http::fake([
            '*/data/2.5/forecast*' => Http::response([
                'city' => ['timezone' => 25200],
                'list' => [[
                    'dt' => Carbon::parse("{$date} 12:00", '+07:00')->timestamp,
                    'main' => ['temp_min' => 24, 'temp_max' => 30],
                    'weather' => [['id' => 502, 'description' => 'ฝนตกหนัก', 'icon' => '10d']],
                    'wind' => ['speed' => 6],
                    'pop' => 0.9,
                    'rain' => ['3h' => 12],
                ]],
            ], 200),
        ]);
    }

    public function test_booking_detail_includes_weather_when_trip_has_coordinates(): void
    {
        $departure = now()->addDays(2)->toDateString();
        $this->fakeRainyForecast($departure);

        $booking = $this->makeBooking(18.788, 98.985, $departure);

        $response = $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}");

        $response->assertOk()
            ->assertJsonPath('data.schedule.weather.severity', 'warning')
            ->assertJsonPath('data.schedule.weather.forecast_date', $departure)
            ->assertJsonPath('data.schedule.weather.description_th', 'ฝนตกหนัก');
    }

    public function test_booking_detail_omits_weather_when_trip_has_no_coordinates(): void
    {
        $departure = now()->addDays(2)->toDateString();
        Http::fake(); // would fail the test if called

        $booking = $this->makeBooking(null, null, $departure);

        $response = $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}");

        $response->assertOk()
            ->assertJsonMissingPath('data.schedule.weather');
        Http::assertNothingSent();
    }
}
