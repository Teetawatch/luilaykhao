<?php

namespace App\Console\Commands;

use App\Jobs\SendBalanceDueRemindersJob;
use Illuminate\Console\Command;

class SendBalanceDueReminders extends Command
{
    protected $signature = 'deposit:remind-balance';

    protected $description = 'แจ้งเตือนชำระเงินส่วนที่เหลือสำหรับการจองที่จ่ายมัดจำไว้ (ก่อนเดินทาง 15 วัน)';

    public function handle(): void
    {
        SendBalanceDueRemindersJob::dispatch()->onQueue('reminders');
        $this->info('Dispatched SendBalanceDueRemindersJob to queue.');
    }
}
