<?php

namespace App\Jobs;

use App\Services\WeatherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ดึงพยากรณ์อากาศของพิกัดหนึ่งจากผู้ให้บริการมาเก็บในตาราง weather_forecasts
 *
 * หน้าจอที่ลูกค้ารอ (รายละเอียดทริป รายการจอง แชท) อ่านพยากรณ์จากตารางอย่างเดียว
 * (WeatherService::cachedForecastFor) ถ้ายังไม่มีหรือเก่าแล้ว ก็ส่งงานนี้เข้าคิวแทนการ
 * ยิง OpenWeather ระหว่าง request (timeout 10 วินาที) — ลูกค้าเห็นพยากรณ์ในการเปิดครั้งถัดไป
 */
class RefreshWeatherForecastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $date,
    ) {}

    public function handle(WeatherService $weather): void
    {
        $weather->forecastFor($this->latitude, $this->longitude, $this->date);
    }
}
