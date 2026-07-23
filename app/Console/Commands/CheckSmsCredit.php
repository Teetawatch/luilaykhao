<?php

namespace App\Console\Commands;

use App\Services\ThaiBulkSmsClient;
use Illuminate\Console\Command;

class CheckSmsCredit extends Command
{
    protected $signature = 'sms:credit';

    protected $description = 'Check ThaiBulkSMS remaining credit with the configured API credentials.';

    public function handle(ThaiBulkSmsClient $client): int
    {
        $config = config('services.thaibulksms');

        if (! filled($config['api_key']) || ! filled($config['api_secret'])) {
            $this->error('ThaiBulkSMS API key/secret are not configured.');

            return self::FAILURE;
        }

        $result = $client->credit();

        if (! $result['ok']) {
            $this->error('ThaiBulkSMS credit check failed with HTTP '.$result['status']);
            $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->info('ThaiBulkSMS credit check succeeded.');
        $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
