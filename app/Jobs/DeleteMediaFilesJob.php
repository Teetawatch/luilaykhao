<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Removes files from a disk in the background. Deleting a round's photos used to
 * fire two R2 round-trips per photo inline (and inside the DB transaction), so a
 * few hundred photos reliably timed out and rolled the whole delete back. The DB
 * rows now go first and the objects are swept up here.
 */
class DeleteMediaFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @param  string[]  $paths */
    public function __construct(public string $disk, public array $paths) {}

    public function handle(): void
    {
        $paths = array_values(array_filter($this->paths));
        if (! $paths) {
            return;
        }

        $disk = Storage::disk($this->disk);

        foreach (array_chunk($paths, 100) as $chunk) {
            try {
                $disk->delete($chunk);
            } catch (\Throwable $e) {
                // A file we can't remove is orphaned storage, not a broken record —
                // log it and keep going rather than failing the whole sweep.
                Log::warning('Failed to delete media files', [
                    'disk' => $this->disk,
                    'paths' => $chunk,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
