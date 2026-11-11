<?php

namespace App\Console\Commands;

use App\Services\SeatLockService;
use Illuminate\Console\Command;

/**
 * "ที่นั่งที่ล็อกไว้" ของแต่ละคนอ่านจากดัชนีต่อผู้ใช้ใน Redis (seat_lock_user:{id})
 * ล็อกที่ทำไว้ก่อนมีดัชนีจะไม่อยู่ในนั้น — deploy.sh รันคำสั่งนี้ทุกครั้ง (ปลอดภัย
 * ถ้ารันซ้ำ) ให้ล็อกเหล่านั้นกลับมาอยู่ในดัชนีจนกว่าจะหมดอายุไปเอง
 */
class ReindexSeatLocksCommand extends Command
{
    protected $signature = 'seat-locks:reindex';

    protected $description = 'Rebuild the per-user seat-lock index from the locks currently in Redis.';

    public function handle(SeatLockService $locks): int
    {
        $count = $locks->reindex();
        $this->info("ใส่ล็อกที่นั่ง {$count} รายการลงดัชนีแล้ว");

        return self::SUCCESS;
    }
}
