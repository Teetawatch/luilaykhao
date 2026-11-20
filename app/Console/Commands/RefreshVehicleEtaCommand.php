<?php

namespace App\Console\Commands;

use App\Services\Maps\VehicleEtaService;
use Illuminate\Console\Command;

class RefreshVehicleEtaCommand extends Command
{
    protected $signature = 'tracking:refresh-eta';

    protected $description = 'Refresh the shared vehicle → pickup ETA (one Google Routes call per vehicle every 3–5 minutes, only while someone is still waiting).';

    public function handle(VehicleEtaService $eta): int
    {
        $counts = $eta->refreshAll();

        if (array_sum($counts) > $counts['unchanged']) {
            $this->info(sprintf(
                'ETA: google %d · reanchored %d · estimated %d · stopped %d · unchanged %d',
                $counts['google'], $counts['reanchored'], $counts['estimated'], $counts['stopped'], $counts['unchanged'],
            ));
        }

        return self::SUCCESS;
    }
}
