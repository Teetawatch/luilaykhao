<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\AwsS3V3Adapter;
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
 *
 * On S3/R2 the objects go in one DeleteObjects request per chunk. Laravel's
 * `$disk->delete([...])` loops one DeleteObject request per path, so a 200-photo
 * purge chunk (400 objects) ran past the queue's retry_after, was re-delivered
 * to a second worker while still running, and died as "attempted too many times".
 */
class DeleteMediaFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** S3 DeleteObjects takes up to 1000 keys per request. */
    private const BATCH = 1000;

    public int $tries = 3;

    public int $timeout = 300;

    /** @param  string[]  $paths */
    public function __construct(public string $disk, public array $paths) {}

    public function handle(): void
    {
        $paths = array_values(array_unique(array_filter($this->paths)));
        if (! $paths) {
            return;
        }

        $disk = Storage::disk($this->disk);

        foreach (array_chunk($paths, self::BATCH) as $chunk) {
            try {
                $this->deleteChunk($disk, $chunk);
            } catch (\Throwable $e) {
                // A file we can't remove is orphaned storage, not a broken record —
                // log it and keep going rather than failing the whole sweep.
                Log::warning('Failed to delete media files', [
                    'disk' => $this->disk,
                    'count' => count($chunk),
                    'paths' => array_slice($chunk, 0, 20),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @param  string[]  $chunk */
    private function deleteChunk(Filesystem $disk, array $chunk): void
    {
        if ($disk instanceof AwsS3V3Adapter) {
            try {
                $this->deleteObjects($disk, $chunk);

                return;
            } catch (\Throwable $e) {
                // ไม่เคยทดสอบ DeleteObjects กับ R2 จริง — ถ้าถูกปฏิเสธ ถอยไปลบทีละไฟล์แบบเดิม
                // (ช้ากว่า แต่ยังจบใน timeout เพราะ retry_after ยาวพอแล้ว)
                Log::info('Batch delete rejected, deleting one by one', [
                    'disk' => $this->disk,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $disk->delete($chunk);
    }

    /** @param  string[]  $chunk */
    private function deleteObjects(AwsS3V3Adapter $disk, array $chunk): void
    {
        $result = $disk->getClient()->deleteObjects([
            'Bucket' => $disk->getConfig()['bucket'],
            'Delete' => [
                'Objects' => array_map(fn (string $path) => ['Key' => $disk->path($path)], $chunk),
                // Quiet = the response lists only the keys that failed
                'Quiet' => true,
            ],
        ]);

        $errors = $result['Errors'] ?? [];
        if ($errors !== []) {
            // ลบไม่ได้บางไฟล์ไม่ใช่เหตุให้ทั้งงานล้ม (ไฟล์ที่ไม่มีอยู่แล้ว S3 นับว่าลบสำเร็จ)
            Log::warning('Some media files could not be deleted', [
                'disk' => $this->disk,
                'count' => count($errors),
                'errors' => array_slice($errors, 0, 20),
            ]);
        }
    }
}
