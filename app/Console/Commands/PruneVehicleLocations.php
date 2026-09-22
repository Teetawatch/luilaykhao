<?php

namespace App\Console\Commands;

use App\Jobs\PruneVehicleLocationsJob;
use App\Models\VehicleLocation;
use Illuminate\Console\Command;

/**
 * ลบพิกัดรถเก่าด้วยมือ — สำหรับล้างของที่ค้างสะสมมาตั้งแต่ก่อนมีงานลบอัตโนมัติ
 *
 * ครั้งแรกบนเครื่องจริงควรดูจำนวนก่อนด้วย --dry-run เสมอ
 */
class PruneVehicleLocations extends Command
{
    protected $signature = 'tracking:prune
        {--days= : เก็บย้อนหลังกี่วัน (ค่าเริ่มต้นตาม PruneVehicleLocationsJob)}
        {--dry-run : บอกจำนวนที่จะลบโดยไม่ลบจริง}';

    protected $description = 'ลบพิกัดรถที่เก่ากว่าจำนวนวันที่กำหนด';

    public function handle(): int
    {
        // ?: ไม่ได้ เพราะ "0" เป็นค่าเท็จ — --days=0 จะเงียบ ๆ กลายเป็นค่าเริ่มต้น
        // แทนที่จะถูกปฏิเสธ ซึ่งแปลว่า "ลบทุกแถว" โดยที่คนสั่งไม่ได้ตั้งใจ
        $option = $this->option('days');
        $days = $option === null || $option === ''
            ? PruneVehicleLocationsJob::RETENTION_DAYS
            : (int) $option;

        if ($days < 1) {
            $this->error('--days ต้องเป็นจำนวนวันที่มากกว่า 0');

            return self::FAILURE;
        }

        $total = VehicleLocation::count();
        $pending = PruneVehicleLocationsJob::pending($days);

        $this->line("พิกัดทั้งหมดตอนนี้: {$total} แถว");
        $this->line("เก่ากว่า {$days} วัน: {$pending} แถว");

        if ($pending === 0) {
            $this->info('ไม่มีอะไรต้องลบ');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('โหมดลองดูเฉย ๆ — ยังไม่ได้ลบอะไร');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($pending);
        $bar->start();

        $deleted = PruneVehicleLocationsJob::prune(
            $days,
            fn (int $done) => $bar->setProgress(min($done, $pending)),
        );

        $bar->finish();
        $this->newLine(2);
        $this->info("ลบแล้ว {$deleted} แถว · เหลือ ".VehicleLocation::count().' แถว');

        return self::SUCCESS;
    }
}
