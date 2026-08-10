<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileBeamChargesJob;
use Illuminate\Console\Command;

/**
 * เรียก ReconcileBeamChargesJob ด้วยมือ — ใช้ตอนเพิ่งกู้เซิร์ฟเวอร์กลับมา
 * แล้วอยากเก็บ webhook ที่หายไประหว่างล่มทันที ไม่ต้องรอ scheduler รอบถัดไป
 */
class ReconcileBeamCharges extends Command
{
    protected $signature = 'beam:reconcile';

    protected $description = 'Ask Beam how every pending charge ended, and settle the ones that were paid.';

    public function handle(): int
    {
        ReconcileBeamChargesJob::dispatchSync();

        $this->info('เรียบร้อย — ดูผลได้ใน log (ReconcileBeamChargesJob completed)');

        return self::SUCCESS;
    }
}
