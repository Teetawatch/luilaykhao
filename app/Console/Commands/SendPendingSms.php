<?php

namespace App\Console\Commands;

use App\Jobs\SendPendingSmsJob;
use Illuminate\Console\Command;

class SendPendingSms extends Command
{
    protected $signature = 'sms:send-pending {--limit=100}';
    protected $description = 'Send pending SMS logs through the configured SMS provider.';

    public function handle(): int
    {
        SendPendingSmsJob::dispatch((int) $this->option('limit'))->onQueue('sms');
        $this->info('Dispatched SendPendingSmsJob to queue.');

        return self::SUCCESS;
    }
}
