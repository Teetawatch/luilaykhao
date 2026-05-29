<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caches a daily weather forecast per (provider, coordinate, date) so multiple
 * schedules at the same destination share one row and we don't re-hit the
 * upstream API on every booking view or alert run.
 *
 * Conventions:
 *   pop       – probability of precipitation, 0.0–1.0
 *   severity  – 'none' | 'advisory' | 'warning' (see WeatherService::classifySeverity)
 *   raw       – the aggregated daily payload, kept for debugging / future fields
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_forecasts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('openweather');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->date('forecast_date');
            $table->string('condition_code', 16)->nullable();
            $table->string('description_th')->nullable();
            $table->decimal('temp_min', 5, 2)->nullable();
            $table->decimal('temp_max', 5, 2)->nullable();
            $table->decimal('pop', 4, 3)->default(0);
            $table->decimal('rain_mm', 6, 2)->nullable();
            $table->decimal('wind_speed', 5, 2)->nullable();
            $table->string('icon', 16)->nullable();
            $table->string('severity', 16)->default('none');
            $table->json('raw')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'latitude', 'longitude', 'forecast_date'], 'weather_forecasts_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_forecasts');
    }
};
