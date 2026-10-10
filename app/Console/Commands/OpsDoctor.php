<?php

namespace App\Console\Commands;

use App\Services\OpsHealthService;
use Illuminate\Console\Command;

/**
 * เช็คสุขภาพระบบเบื้องหลังในคำสั่งเดียว — รันหลัง deploy ทุกครั้ง (deploy/deploy.sh)
 * หรือเมื่อสงสัยว่าอะไรเงียบไป จบด้วย exit code 1 ถ้ามีข้อที่ fail
 */
class OpsDoctor extends Command
{
    protected $signature = 'ops:doctor';

    protected $description = 'Check queues, Horizon, the scheduler and key jobs are actually running.';

    public function handle(OpsHealthService $health): int
    {
        $checks = $health->checks();

        $this->table(['', 'รายการ', 'รายละเอียด'], array_map(fn ($check) => [
            match ($check['status']) {
                OpsHealthService::OK => '<fg=green>✓</>',
                OpsHealthService::WARN => '<fg=yellow>!</>',
                default => '<fg=red>✗</>',
            },
            $check['label'],
            $check['detail'],
        ], $checks));

        $failed = array_filter($checks, fn ($check) => $check['status'] === OpsHealthService::FAIL);

        if ($failed) {
            $this->components->error(count($failed).' รายการมีปัญหา');

            return self::FAILURE;
        }

        $this->components->info('ระบบเบื้องหลังทำงานปกติ');

        return self::SUCCESS;
    }
}
