<?php

namespace Tests\Unit;

use App\Models\WeatherForecast;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherServiceTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 18.7883;

    private const LNG = 98.9853;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.weather.provider' => 'openweather',
            'services.weather.api_key' => 'test-key',
            'services.weather.base_url' => 'https://api.openweathermap.org',
            'services.weather.cache_ttl_minutes' => 180,
        ]);
    }

    private function fakeForecast(): void
    {
        Http::fake([
            '*/data/2.5/forecast*' => Http::response([
                'city' => ['timezone' => 25200], // +07:00
                'list' => [
                    // 2026-06-01 — rain in the morning, thunderstorm in the afternoon
                    $this->slot('2026-06-01 09:00', 500, 'ฝนเล็กน้อย', '10d', 27, 29, 0.4, 1.0, 5),
                    $this->slot('2026-06-01 15:00', 200, 'พายุฝนฟ้าคะนอง', '11d', 26, 31, 0.7, 3.0, 6),
                    // 2026-06-02 — clear
                    $this->slot('2026-06-02 12:00', 800, 'ท้องฟ้าแจ่มใส', '01d', 28, 33, 0.0, 0.0, 3),
                ],
            ], 200),
        ]);
    }

    /**
     * Build a 3-hour slot whose `dt` lands on the given +07:00 local time.
     */
    private function slot(string $localTime, int $code, string $desc, string $icon, float $tMin, float $tMax, float $pop, float $rain, float $wind): array
    {
        return [
            'dt' => Carbon::parse($localTime, '+07:00')->timestamp,
            'main' => ['temp_min' => $tMin, 'temp_max' => $tMax],
            'weather' => [['id' => $code, 'description' => $desc, 'icon' => $icon]],
            'wind' => ['speed' => $wind],
            'pop' => $pop,
            'rain' => ['3h' => $rain],
        ];
    }

    public function test_aggregates_3hourly_slots_into_one_daily_row(): void
    {
        $this->fakeForecast();

        $f = (new WeatherService)->forecastFor(self::LAT, self::LNG, '2026-06-01');

        $this->assertNotNull($f);
        $this->assertSame('2026-06-01', $f->forecast_date->toDateString());
        $this->assertEqualsWithDelta(26.0, $f->temp_min, 0.01);
        $this->assertEqualsWithDelta(31.0, $f->temp_max, 0.01);
        $this->assertEqualsWithDelta(0.7, $f->pop, 0.001);   // max across the day
        $this->assertEqualsWithDelta(4.0, $f->rain_mm, 0.01); // summed across the day
        $this->assertSame('200', $f->condition_code);         // most severe condition kept
        $this->assertSame('warning', $f->severity);           // thunderstorm
    }

    public function test_clear_day_classified_as_none(): void
    {
        $this->fakeForecast();

        $f = (new WeatherService)->forecastFor(self::LAT, self::LNG, '2026-06-02');

        $this->assertNotNull($f);
        $this->assertSame('none', $f->severity);
    }

    public function test_classify_severity_levels(): void
    {
        $service = new WeatherService;

        $this->assertSame('warning', $service->classifySeverity(['condition_code' => '202']));
        $this->assertSame('warning', $service->classifySeverity(['condition_code' => '804', 'pop' => 0.85]));
        $this->assertSame('warning', $service->classifySeverity(['condition_code' => '801', 'wind_speed' => 11]));
        $this->assertSame('advisory', $service->classifySeverity(['condition_code' => '500']));
        $this->assertSame('advisory', $service->classifySeverity(['condition_code' => '801', 'pop' => 0.55]));
        $this->assertSame('none', $service->classifySeverity(['condition_code' => '800', 'pop' => 0.1]));
    }

    public function test_cache_hit_avoids_second_http_call(): void
    {
        $this->fakeForecast();
        $service = new WeatherService;

        $service->forecastFor(self::LAT, self::LNG, '2026-06-01');
        $service->forecastFor(self::LAT, self::LNG, '2026-06-01');

        Http::assertSentCount(1);
    }

    public function test_returns_null_for_date_outside_window(): void
    {
        $this->fakeForecast();

        $f = (new WeatherService)->forecastFor(self::LAT, self::LNG, '2026-06-20');

        $this->assertNull($f);
    }

    public function test_returns_null_when_api_key_missing(): void
    {
        config(['services.weather.api_key' => null]);
        Http::fake(); // ensure no real call

        $f = (new WeatherService)->forecastFor(self::LAT, self::LNG, '2026-06-01');

        $this->assertNull($f);
        $this->assertSame(0, WeatherForecast::count());
    }
}
