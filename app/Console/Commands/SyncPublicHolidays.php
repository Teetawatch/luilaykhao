<?php

namespace App\Console\Commands;

use App\Services\HolidayCalendarService;
use Illuminate\Console\Command;

/**
 * ดึงวันหยุดราชการไทยจากปฏิทินของ Google เดี๋ยวนี้ (ไม่ผ่านคิว) — ใช้ตอน deploy ครั้งแรก
 */
class SyncPublicHolidays extends Command
{
    protected $signature = 'holidays:sync';

    protected $description = 'ดึงวันหยุดราชการไทย (รวมวันหยุดชดเชย) ลงตาราง public_holidays';

    public function handle(HolidayCalendarService $calendar): int
    {
        try {
            $result = $calendar->sync();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("ได้วันหยุด {$result['fetched']} วัน — เพิ่ม {$result['created']} แก้ {$result['updated']} ลบ {$result['removed']}");

        return self::SUCCESS;
    }
}
