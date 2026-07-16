<?php

namespace App\Jobs;

use App\Models\SchedulePhoto;
use App\Models\TripPhoto;
use App\Support\Thumbnailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a photo's thumbnail in the background. Decoding a 12MP JPEG in GD and
 * PUTting a second object to R2 used to happen inline, which made a multi-photo
 * upload take minutes; the upload now returns as soon as the original is stored
 * and the grid falls back to the full image until this job fills thumb_path in.
 */
class GeneratePhotoThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param  class-string<SchedulePhoto|TripPhoto>  $photoClass */
    public function __construct(public string $photoClass, public int $photoId) {}

    public function handle(): void
    {
        if (! in_array($this->photoClass, [SchedulePhoto::class, TripPhoto::class], true)) {
            return;
        }

        /** @var SchedulePhoto|TripPhoto|null $photo */
        $photo = $this->photoClass::find($this->photoId);

        if (! $photo || ! $photo->path || $photo->thumb_path) {
            return; // gone, or already thumbnailed by an earlier attempt
        }

        $disk = Storage::disk($photo->disk ?: config('filesystems.default'));

        if (! $disk->exists($photo->path)) {
            return;
        }

        // GD only reads from a real file, so stage the original locally.
        $tmp = tempnam(sys_get_temp_dir(), 'thumb_');
        if ($tmp === false) {
            return;
        }

        try {
            $source = $disk->readStream($photo->path);
            if (! $source) {
                return;
            }
            $local = fopen($tmp, 'w');
            stream_copy_to_stream($source, $local);
            fclose($local);
            fclose($source);

            $data = Thumbnailer::fromPath($tmp);
            if (! $data) {
                return; // format GD can't read — the UI keeps using the full image
            }

            $thumbPath = $this->thumbPathFor($photo->path);
            $disk->put($thumbPath, $data, [
                'visibility' => 'public',
                'ContentType' => 'image/jpeg',
            ]);

            $photo->thumb_path = $thumbPath;
            $photo->thumb_url = $disk->url($thumbPath);

            // Backfill dimensions if the upload couldn't read them.
            if (! $photo->width || ! $photo->height) {
                [$w, $h] = Thumbnailer::dimensions($tmp);
                $photo->width = $w;
                $photo->height = $h;
            }

            $photo->saveQuietly();
        } finally {
            @unlink($tmp);
        }
    }

    /** "schedules/12/abc.jpg" → "schedules/12/thumbs/abc.jpg" */
    private function thumbPathFor(string $path): string
    {
        $dir = trim(pathinfo($path, PATHINFO_DIRNAME), '/.');
        $base = pathinfo($path, PATHINFO_FILENAME);

        return ($dir !== '' ? $dir.'/' : '').'thumbs/'.$base.'.jpg';
    }
}
