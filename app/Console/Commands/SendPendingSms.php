<?php

namespace App\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;

class SendPendingSms extends Command
{
    protected $signature = 'sms:send-pending {--limit=100}';
    protected $description = 'Send pending SMS logs through the configured SMS provider.';

    public function handle(SmsService $smsService): int
    {
        $sent = $smsService->sendPending((int) $this->option('limit'));

        $this->info("Sent {$sent} pending SMS message(s).");

        return self::SUCCESS;
    }
}
