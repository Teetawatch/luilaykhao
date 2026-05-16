<?php

namespace App\Console\Commands;

use App\Jobs\SendInstallmentRemindersJob;
use Illuminate\Console\Command;

class SendInstallmentReminders extends Command
{
    protected $signature   = 'installment:remind';
    protected $description = 'แจ้งเตือนผ่อนชำระที่ใกล้ครบกำหนด และทำเครื่องหมาย overdue';

    public function handle(): void
    {
        SendInstallmentRemindersJob::dispatch()->onQueue('reminders');
        $this->info('Dispatched SendInstallmentRemindersJob to queue.');
    }
}
