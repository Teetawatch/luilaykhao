<?php

namespace App\Services;

use App\Jobs\RefreshWeatherForecastJob;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\WeatherForecast;
use App\Services\Weather\OpenWeatherProvider;
use App\Services\Weather\WeatherProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    /**
     * Coordinate rounding (decimals) used for the cache key so nearby
     * schedules at effectively the same destination share one forecast row.
     * 3 decimals ≈ 110 m.
     */
    private const COORD_PRECISION = 3;

    /**
     * How many days ahead a forecast is meaningful. OpenWeather's free tier
     * returns ~5 days; we keep a little slack. Schedules departing beyond this
     * are skipped so we never make a pointless upstream call for them.
     */
    public const FORECAST_WINDOW_DAYS = 6;

    /**
     * Resolve the departure-day forecast for [$schedule] (using its trip's
     * coordinates) and stash it on the model as `weather_forecast` so the API
     * resource can expose it. No-op when coords/date are missing or the date is
     * outside the forecast window. Never throws — weather is best-effort.
     *
     * Never calls the provider during the request: it reads the stored row and
     * queues a refresh when that row is missing or stale (cachedForecastFor).
     */
    public function attach(TripSchedule $schedule, ?Trip $trip = null): void
    {
        $trip ??= $schedule->trip;
        if (! $trip || $trip->latitude === null || $trip->longitude === null) {
            return;
        }

        $departure = $schedule->departure_date;
        if (! $departure) {
            return;
        }

        // Only within the forecast window (today .. +N days).
        if ($departure->isBefore(now()->startOfDay())
            || $departure->isAfter(now()->startOfDay()->addDays(self::FORECAST_WINDOW_DAYS))) {
            return;
        }

        try {
            $forecast = $this->cachedForecastFor(
                (float) $trip->latitude,
                (float) $trip->longitude,
                $departure->toDateString(),
            );

            if ($forecast) {
                $schedule->weather_forecast = $forecast->toPayload();
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to attach weather to schedule', [
                'schedule_id' => $schedule->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private WeatherProvider $provider;

    private int $cacheTtlMinutes;

    public function __construct(?WeatherProvider $provider = null)
    {
        $this->cacheTtlMinutes = (int) config('services.weather.cache_ttl_minutes', 180);
        $this->provider = $provider ?? $this->makeProvider();
    }

    private function makeProvider(): WeatherProvider
    {
        return match (config('services.weather.provider', 'openweather')) {
            // Future: 'tmd' => new TmdProvider(...),
            default => new OpenWeatherProvider(
                apiKey: config('services.weather.api_key'),
                baseUrl: rtrim((string) config('services.weather.base_url', 'https://api.openweathermap.org'), '/'),
                units: config('services.weather.units', 'metric'),
                lang: config('services.weather.lang', 'th'),
            ),
        };
    }

    /** How long a failed upstream call keeps us from retrying the same spot. */
    private const FAILURE_BACKOFF_MINUTES = 10;

    /** One queued refresh per spot at a time, however many screens ask. */
    private const REFRESH_LOCK_MINUTES = 10;

    /**
     * Daily forecast for a coordinate on a specific date, or null when it is
     * outside the provider's window or the upstream call is unavailable.
     *
     * Calls the provider synchronously — meant for jobs. Screens a customer is
     * waiting on use [cachedForecastFor] instead.
     */
    public function forecastFor(float $lat, float $lng, string $date): ?WeatherForecast
    {
        $lat = round($lat, self::COORD_PRECISION);
        $lng = round($lng, self::COORD_PRECISION);

        $fresh = $this->freshCached($lat, $lng, $date);
        if ($fresh) {
            return $fresh;
        }

        // ดึงพิกัดนี้ไปไม่นานนี้แล้ว (หรือเพิ่งดึงไม่สำเร็จ) — ไม่มีแถวของวันนั้นก็แปลว่า
        // ผู้ให้บริการไม่มีให้ (เกินช่วงพยากรณ์) ยิงซ้ำก็ได้คำตอบเดิม
        if ($this->recentlyFetched($lat, $lng)) {
            return $this->cachedRow($lat, $lng, $date);
        }

        $days = $this->provider->fetchDaily($lat, $lng);
        if (empty($days)) {
            $this->markFetched($lat, $lng, self::FAILURE_BACKOFF_MINUTES);

            // Upstream unavailable — fall back to a stale row if we have one
            // rather than showing nothing.
            return $this->cachedRow($lat, $lng, $date);
        }

        $this->store($lat, $lng, $days);
        $this->markFetched($lat, $lng, $this->cacheTtlMinutes);

        return $this->cachedRow($lat, $lng, $date);
    }

    /**
     * Forecast from the table only — never waits on the provider. When the row
     * is missing or stale and the date is inside the forecast window, a
     * [RefreshWeatherForecastJob] is queued (once per spot per few minutes) and
     * whatever is stored is returned, stale or not; the next view gets the new
     * one. With the sync queue (tests, local) the job runs first, so the answer
     * is the same as [forecastFor].
     */
    public function cachedForecastFor(float $lat, float $lng, string $date): ?WeatherForecast
    {
        $lat = round($lat, self::COORD_PRECISION);
        $lng = round($lng, self::COORD_PRECISION);

        $fresh = $this->freshCached($lat, $lng, $date);
        if ($fresh) {
            return $fresh;
        }

        if ($this->insideWindow($date) && ! $this->recentlyFetched($lat, $lng)) {
            $this->queueRefresh($lat, $lng, $date);
        }

        return $this->cachedRow($lat, $lng, $date);
    }

    private function insideWindow(string $date): bool
    {
        try {
            $day = Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        $today = now()->startOfDay();

        return ! $day->isBefore($today)
            && ! $day->isAfter($today->copy()->addDays(self::FORECAST_WINDOW_DAYS));
    }

    private function queueRefresh(float $lat, float $lng, string $date): void
    {
        try {
            if (! Cache::add($this->spotKey('refresh', $lat, $lng), true, now()->addMinutes(self::REFRESH_LOCK_MINUTES))) {
                return;
            }

            RefreshWeatherForecastJob::dispatch($lat, $lng, $date);
        } catch (\Throwable $e) {
            Log::warning('Unable to queue weather refresh', ['message' => $e->getMessage()]);
        }
    }

    private function recentlyFetched(float $lat, float $lng): bool
    {
        try {
            return Cache::has($this->spotKey('fetched', $lat, $lng));
        } catch (\Throwable) {
            return false;
        }
    }

    private function markFetched(float $lat, float $lng, int $minutes): void
    {
        try {
            Cache::put($this->spotKey('fetched', $lat, $lng), true, now()->addMinutes(max(1, $minutes)));
        } catch (\Throwable) {
            // best-effort — worst case the next caller fetches again
        }
    }

    private function spotKey(string $kind, float $lat, float $lng): string
    {
        return "weather:{$kind}:{$this->provider->name()}:{$lat}:{$lng}";
    }

    private function freshCached(float $lat, float $lng, string $date): ?WeatherForecast
    {
        return $this->baseQuery($lat, $lng, $date)
            ->where('fetched_at', '>=', now()->subMinutes($this->cacheTtlMinutes))
            ->first();
    }

    private function cachedRow(float $lat, float $lng, string $date): ?WeatherForecast
    {
        return $this->baseQuery($lat, $lng, $date)->first();
    }

    private function baseQuery(float $lat, float $lng, string $date)
    {
        return WeatherForecast::query()
            ->where('provider', $this->provider->name())
            ->where('latitude', $lat)
            ->where('longitude', $lng)
            ->whereDate('forecast_date', $date);
    }

    private function store(float $lat, float $lng, array $days): void
    {
        $now = now();

        foreach ($days as $day) {
            WeatherForecast::updateOrCreate(
                [
                    'provider' => $this->provider->name(),
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'forecast_date' => $day['date'],
                ],
                [
                    'condition_code' => $day['condition_code'] ?? null,
                    'description_th' => $day['description_th'] ?? null,
                    'temp_min' => $day['temp_min'] ?? null,
                    'temp_max' => $day['temp_max'] ?? null,
                    'pop' => $day['pop'] ?? 0,
                    'rain_mm' => $day['rain_mm'] ?? null,
                    'wind_speed' => $day['wind_speed'] ?? null,
                    'icon' => $day['icon'] ?? null,
                    'severity' => $this->classifySeverity($day),
                    'raw' => $day,
                    'fetched_at' => $now,
                ],
            );
        }
    }

    /**
     * Map a daily forecast to none | advisory | warning.
     *
     * @param  array{condition_code?: ?string, pop?: float, rain_mm?: ?float, wind_speed?: ?float}  $day
     */
    public function classifySeverity(array $day): string
    {
        $group = isset($day['condition_code']) ? (int) substr((string) $day['condition_code'], 0, 1) : 0;
        $pop = (float) ($day['pop'] ?? 0);
        $rain = (float) ($day['rain_mm'] ?? 0);
        $wind = (float) ($day['wind_speed'] ?? 0);

        $isThunderstorm = $group === 2;

        if ($isThunderstorm || $rain >= 35 || $pop >= 0.8 || $wind >= 10) {
            return 'warning';
        }

        $isRainOrDrizzle = $group === 5 || $group === 3;
        if ($isRainOrDrizzle || $pop >= 0.5 || $rain >= 5 || $wind >= 8) {
            return 'advisory';
        }

        return 'none';
    }

    /**
     * Thai-language heads-up for a forecast, used by the pre-departure alert.
     *
     * @return array{title: string, body: string}
     */
    public function thaiAdvice(WeatherForecast $f, string $tripTitle): array
    {
        $popPercent = (int) round(($f->pop ?? 0) * 100);
        $desc = $f->description_th ? "({$f->description_th}) " : '';

        if ($f->severity === 'warning') {
            return [
                'title' => 'พรุ่งนี้สภาพอากาศไม่ค่อยดี ⛈️',
                'body' => "{$tripTitle} พรุ่งนี้คาดว่ามีฝน/พายุ {$desc}โอกาสฝนตก {$popPercent}% "
                    .'เตรียมเสื้อกันฝน รองเท้ากันลื่น และกันน้ำให้อุปกรณ์ไปด้วยนะ — ทริปยังเดินทางตามกำหนดเดิม',
            ];
        }

        return [
            'title' => 'พรุ่งนี้อาจเจอฝน ☔️',
            'body' => "{$tripTitle} พรุ่งนี้มีโอกาสฝนตก {$popPercent}% {$desc}"
                .'เตรียมเสื้อกันฝนติดไปเผื่อไว้นะ — ทริปเดินทางตามกำหนดเดิม',
        ];
    }
}
