<?php

namespace App\Console\Commands;

use App\Support\MediaDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off: relocate existing payment slips from the public media disk into the
 * private slip disk, then remove the public copies so financial documents are
 * no longer reachable by URL. Safe to re-run.
 */
class MoveSlipsToPrivate extends Command
{
    protected $signature = 'slips:make-private {--dry-run : Report what would change without writing anything} {--keep-source : Copy to the private disk but do not delete the public copies}';

    protected $description = 'Move existing payment slips from the public disk to the private slip disk';

    public function handle(): int
    {
        $source = MediaDisk::name();
        $target = MediaDisk::slipDisk();

        if ($source === $target) {
            $this->warn("Slip disk ({$target}) is the same as the public disk — nothing to separate. Configure R2_PRIVATE_BUCKET first.");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $keepSource = (bool) $this->option('keep-source');

        if ($dryRun) {
            $this->warn('DRY RUN — no files will be changed.');
        }

        $from = Storage::disk($source);
        $to = Storage::disk($target);

        $files = $from->allFiles('slips');
        if ($files === []) {
            $this->info("No slips found on the public disk ({$source}).");

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;
        $deleted = 0;

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $path) {
            $alreadyThere = $to->exists($path);

            if (! $alreadyThere) {
                if (! $dryRun) {
                    $stream = $from->readStream($path);
                    $to->writeStream($path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $moved++;
            } else {
                $skipped++;
            }

            // Only delete the public copy once we know the private one exists.
            if (! $keepSource && ($alreadyThere || ! $dryRun)) {
                if (! $dryRun) {
                    $from->delete($path);
                }
                $deleted++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Slips: {$moved} copied to '{$target}', {$skipped} already present, {$deleted} removed from '{$source}'.");

        if ($keepSource) {
            $this->warn('Public copies were KEPT — they remain publicly accessible until removed.');
        }

        return self::SUCCESS;
    }
}
