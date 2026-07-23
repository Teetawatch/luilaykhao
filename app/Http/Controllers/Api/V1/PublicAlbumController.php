<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchedulePhotoResource;
use App\Models\SchedulePhoto;
use App\Models\TripSchedule;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, token-gated access to a round's photo album. No login required — anyone with
 * the link can view and download, so companions who did not book themselves can still
 * grab their trip photos. Mirrors the /track/{token} and /pay/{token} share model.
 */
class PublicAlbumController extends Controller
{
    use ApiResponse;

    private function resolveSchedule(string $token): TripSchedule
    {
        return TripSchedule::with(['trip', 'photos'])
            ->where('photo_token', $token)
            ->firstOrFail();
    }

    /** JSON consumed by the standalone album page. */
    public function photos(string $token): JsonResponse
    {
        $schedule = $this->resolveSchedule($token);

        // รูปชุดแรกที่จะหมดอายุ คือเส้นตายที่ต้องเตือนให้ดาวน์โหลด
        $expiresAt = $schedule->photos
            ->map(fn (SchedulePhoto $photo) => $photo->expiresAt())
            ->filter()
            ->min();

        return $this->success([
            'trip_title' => $schedule->trip?->title,
            'departure_date' => optional($schedule->departure_date)->toDateString(),
            'return_date' => optional($schedule->return_date)->toDateString(),
            'count' => $schedule->photos->count(),
            'retention_days' => SchedulePhoto::RETENTION_DAYS,
            'expires_at' => $expiresAt?->toISOString(),
            'photos' => SchedulePhotoResource::collection($schedule->photos),
        ]);
    }

    /** Force-download a single photo with a friendly filename. */
    public function downloadOne(string $token, int $photoId): StreamedResponse
    {
        $schedule = $this->resolveSchedule($token);
        $photo = $schedule->photos()->where('schedule_photos.id', $photoId)->firstOrFail();

        $disk = Storage::disk($photo->disk ?: config('filesystems.default'));
        abort_unless($disk->exists($photo->path), 404);

        return $disk->download($photo->path, $this->downloadName($schedule, $photo));
    }

    /** Stream a ZIP of every photo in the album. */
    public function downloadAll(string $token): StreamedResponse
    {
        $schedule = $this->resolveSchedule($token);
        $photos = $schedule->photos;
        abort_if($photos->isEmpty(), 404, 'ยังไม่มีรูปในอัลบั้มนี้');

        $zipName = $this->albumSlug($schedule).'.zip';

        return response()->streamDownload(function () use ($photos, $schedule) {
            $tmp = tempnam(sys_get_temp_dir(), 'album');
            $zip = new \ZipArchive;
            $zip->open($tmp, \ZipArchive::OVERWRITE);

            $seq = 0;
            foreach ($photos as $photo) {
                $disk = Storage::disk($photo->disk ?: config('filesystems.default'));
                if (! $disk->exists($photo->path)) {
                    continue;
                }
                $seq++;
                $zip->addFromString($this->downloadName($schedule, $photo, $seq), $disk->get($photo->path));
            }

            $zip->close();
            readfile($tmp);
            @unlink($tmp);
        }, $zipName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    private function downloadName(TripSchedule $schedule, SchedulePhoto $photo, ?int $seq = null): string
    {
        $ext = pathinfo($photo->path, PATHINFO_EXTENSION) ?: 'jpg';
        $base = $this->albumSlug($schedule);
        $suffix = $seq !== null ? str_pad((string) $seq, 3, '0', STR_PAD_LEFT) : $photo->id;

        return "{$base}-{$suffix}.{$ext}";
    }

    private function albumSlug(TripSchedule $schedule): string
    {
        $title = Str::slug((string) $schedule->trip?->title) ?: 'album';
        $date = optional($schedule->departure_date)->format('Ymd') ?: 'photos';

        return "{$title}-{$date}";
    }
}
