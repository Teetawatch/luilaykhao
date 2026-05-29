<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeatherForecast extends Model
{
    protected $fillable = [
        'provider', 'latitude', 'longitude', 'forecast_date',
        'condition_code', 'description_th', 'temp_min', 'temp_max',
        'pop', 'rain_mm', 'wind_speed', 'icon', 'severity', 'raw', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'forecast_date' => 'date',
            'temp_min' => 'float',
            'temp_max' => 'float',
            'pop' => 'float',
            'rain_mm' => 'float',
            'wind_speed' => 'float',
            'raw' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Shape returned to the API / notification layer.
     */
    public function toPayload(): array
    {
        return [
            'forecast_date' => $this->forecast_date?->toDateString(),
            'condition_code' => $this->condition_code,
            'description_th' => $this->description_th,
            'temp_min' => $this->temp_min,
            'temp_max' => $this->temp_max,
            'pop' => $this->pop,
            'rain_mm' => $this->rain_mm,
            'wind_speed' => $this->wind_speed,
            'icon' => $this->icon,
            'severity' => $this->severity,
        ];
    }
}
