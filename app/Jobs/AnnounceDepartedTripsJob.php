<?php

namespace App\Jobs;

use App\Services\TripDepartureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ยิง "รถออกเดินทางแล้ว" ให้ผู้โดยสาร ทันทีที่พิกัดบอกว่ารถเริ่มวิ่งจริง
 *
 * ไม่มีปุ่มให้ใครกด — สตาฟที่นั่งไปกับรถมีงานหน้างานของตัวเองอยู่แล้ว
 * (ดู TripDepartureService ว่าอะไรคือ "ออกเดินทางแล้ว")
 */
class AnnounceDepartedTripsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(TripDepartureService $departures): void
    {
        $announced = $departures->sweep();

        if ($announced > 0) {
            Log::info('AnnounceDepartedTripsJob completed', ['announced' => $announced]);
        }
    }
}
