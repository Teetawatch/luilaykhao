<?php

namespace App\Jobs;

use App\Services\ChatBirthdayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * บอกทีมงานว่าทริปที่กำลังจะออกมีลูกทริปวันเกิดตรงวันเดินทาง (ครั้งเดียวต่อคน)
 * — ดู ChatBirthdayService
 */
class NotifyTripBirthdaysJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(ChatBirthdayService $birthdays): void
    {
        $sent = $birthdays->notifyStaffDue();

        if ($sent > 0) {
            Log::info('NotifyTripBirthdaysJob: แจ้งทีมงานเรื่องวันเกิดแล้ว', ['passengers' => $sent]);
        }
    }
}
