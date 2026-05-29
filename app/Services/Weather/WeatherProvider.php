<?php

namespace App\Services\Weather;

interface WeatherProvider
{
    /**
     * Fetch a daily forecast for a coordinate.
     *
     * @return array<string, array{
     *     date: string,
     *     condition_code: ?string,
     *     description_th: ?string,
     *     icon: ?string,
     *     temp_min: ?float,
     *     temp_max: ?float,
     *     pop: float,
     *     rain_mm: ?float,
     *     wind_speed: ?float,
     * }> Map keyed by local date string (Y-m-d). Empty array when the upstream
     *    call fails or is not configured.
     */
    public function fetchDaily(float $lat, float $lng): array;

    /**
     * Short identifier persisted on cached rows (e.g. 'openweather').
     */
    public function name(): string;
}
