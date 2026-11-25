<?php

namespace App\Jobs;

use App\Services\HolidayCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * ดึงวันหยุดราชการไทยลง public_holidays — ทุกสัปดาห์ (routes/console.php) และเมื่อหน้า
 * วันหยุดยาวพบว่ายังไม่มีวันหยุดข้างหน้าเลย (LongWeekendService::ensureSynced)
 */
class SyncPublicHolidaysJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 600];
    }

    public function handle(HolidayCalendarService $calendar): void
    {
        $result = $calendar->sync();
        Log::info('SyncPublicHolidaysJob: อัปเดตวันหยุดแล้ว', $result);
    }
}
