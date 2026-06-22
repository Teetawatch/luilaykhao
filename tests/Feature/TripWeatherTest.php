<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripWeatherTest extends TestCase
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

    private function makeTrip(?float $lat, ?float $lng): Trip
    {
        return Trip::create([
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
    }

    private function makeSchedule(Trip $trip, string $departure): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
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

    public function test_trip_schedules_endpoint_includes_weather_for_near_dates(): void
    {
        $departure = now()->addDays(2)->toDateString();
        $this->fakeRainyForecast($departure);

        $trip = $this->makeTrip(18.788, 98.985);
        $this->makeSchedule($trip, $departure);

        $this->getJson("/api/v1/trips/{$trip->slug}/schedules")
            ->assertOk()
            ->assertJsonPath('data.0.weather.severity', 'warning')
            ->assertJsonPath('data.0.weather.forecast_date', $departure)
            ->assertJsonPath('data.0.weather.description_th', 'ฝนตกหนัก');
    }

    public function test_schedule_show_endpoint_includes_weather(): void
    {
        $departure = now()->addDays(2)->toDateString();
        $this->fakeRainyForecast($departure);

        $trip = $this->makeTrip(18.788, 98.985);
        $schedule = $this->makeSchedule($trip, $departure);

        $this->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.weather.severity', 'warning');
    }

    public function test_weather_omitted_when_trip_has_no_coordinates(): void
    {
        $departure = now()->addDays(2)->toDateString();
        Http::fake(); // any upstream call would fail the assertion below

        $trip = $this->makeTrip(null, null);
        $this->makeSchedule($trip, $departure);

        $this->getJson("/api/v1/trips/{$trip->slug}/schedules")
            ->assertOk()
            ->assertJsonMissingPath('data.0.weather');
        Http::assertNothingSent();
    }

    public function test_weather_omitted_for_dates_beyond_forecast_window(): void
    {
        // Beyond the ~6 day window — we must not even call the provider.
        $departure = now()->addDays(20)->toDateString();
        Http::fake();

        $trip = $this->makeTrip(18.788, 98.985);
        $this->makeSchedule($trip, $departure);

        $this->getJson("/api/v1/trips/{$trip->slug}/schedules")
            ->assertOk()
            ->assertJsonMissingPath('data.0.weather');
        Http::assertNothingSent();
    }
}
