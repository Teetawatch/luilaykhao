<?php

namespace App\Services\Weather;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenWeather "5 day / 3 hour" forecast provider.
 *
 * The endpoint returns 3-hourly slots for ~5 days; we aggregate them into one
 * row per local date (using the city's timezone offset), keeping the most
 * severe weather condition of the day plus min/max temp, peak rain chance,
 * total rainfall and peak wind.
 *
 * @see https://openweathermap.org/forecast5
 */
class OpenWeatherProvider implements WeatherProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly string $units = 'metric',
        private readonly string $lang = 'th',
    ) {}

    public function name(): string
    {
        return 'openweather';
    }

    public function fetchDaily(float $lat, float $lng): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get("{$this->baseUrl}/data/2.5/forecast", [
                    'lat' => $lat,
                    'lon' => $lng,
                    'units' => $this->units,
                    'lang' => $this->lang,
                    'appid' => $this->apiKey,
                ]);

            if (! $response->successful()) {
                Log::warning('OpenWeather forecast failed', [
                    'status' => $response->status(),
                    'body' => $response->json() ?: $response->body(),
                ]);

                return [];
            }

            return $this->aggregate($response->json());
        } catch (\Throwable $e) {
            Log::warning('OpenWeather forecast exception', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Collapse 3-hourly slots into one entry per local date.
     */
    private function aggregate(array $payload): array
    {
        $slots = $payload['list'] ?? [];
        if (empty($slots)) {
            return [];
        }

        $tzOffset = (int) ($payload['city']['timezone'] ?? 0);
        $days = [];

        foreach ($slots as $slot) {
            $localDate = Carbon::createFromTimestampUTC($slot['dt'] ?? 0)
                ->addSeconds($tzOffset)
                ->toDateString();

            $weather = $slot['weather'][0] ?? [];
            $code = isset($weather['id']) ? (string) $weather['id'] : null;
            $tempMin = $slot['main']['temp_min'] ?? ($slot['main']['temp'] ?? null);
            $tempMax = $slot['main']['temp_max'] ?? ($slot['main']['temp'] ?? null);
            $pop = (float) ($slot['pop'] ?? 0);
            $rain = (float) ($slot['rain']['3h'] ?? 0);
            $wind = isset($slot['wind']['speed']) ? (float) $slot['wind']['speed'] : null;

            if (! isset($days[$localDate])) {
                $days[$localDate] = [
                    'date' => $localDate,
                    'condition_code' => $code,
                    'description_th' => $weather['description'] ?? null,
                    'icon' => $weather['icon'] ?? null,
                    'temp_min' => $tempMin,
                    'temp_max' => $tempMax,
                    'pop' => $pop,
                    'rain_mm' => $rain,
                    'wind_speed' => $wind,
                ];

                continue;
            }

            $day = &$days[$localDate];
            if ($tempMin !== null) {
                $day['temp_min'] = $day['temp_min'] === null ? $tempMin : min($day['temp_min'], $tempMin);
            }
            if ($tempMax !== null) {
                $day['temp_max'] = $day['temp_max'] === null ? $tempMax : max($day['temp_max'], $tempMax);
            }
            $day['pop'] = max($day['pop'], $pop);
            $day['rain_mm'] = ($day['rain_mm'] ?? 0) + $rain;
            if ($wind !== null) {
                $day['wind_speed'] = $day['wind_speed'] === null ? $wind : max($day['wind_speed'], $wind);
            }

            // Keep the most severe condition seen that day so the headline
            // reflects the worst weather, not whatever happened first.
            if ($this->severityRank($code) > $this->severityRank($day['condition_code'])) {
                $day['condition_code'] = $code;
                $day['description_th'] = $weather['description'] ?? $day['description_th'];
                $day['icon'] = $weather['icon'] ?? $day['icon'];
            }
            unset($day);
        }

        return $days;
    }

    /**
     * Rough "how bad" ordering of OpenWeather condition groups, used only to
     * pick the day's representative condition.
     */
    private function severityRank(?string $code): int
    {
        if ($code === null) {
            return -1;
        }

        $group = (int) substr($code, 0, 1);

        return match ($group) {
            2 => 5, // Thunderstorm
            5 => 4, // Rain
            6 => 3, // Snow
            3 => 2, // Drizzle
            7 => 1, // Atmosphere (mist/haze)
            default => 0, // Clouds / Clear
        };
    }
}
