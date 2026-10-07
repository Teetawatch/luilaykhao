<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchedulePhotoResource;
use App\Models\SchedulePhoto;
use App\Models\TripSchedule;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, token-gated access to a round's photo album. No login required — anyone with
 * the link can view and download, so companions who did not book themselves can still
 * grab their trip photos. Mirrors the /track/{token} and /pay/{token} share model.
 *
 * Downloads are one photo at a time on purpose: the old "download all" zip was built
 * in /tmp on the server and filled the VPS disk.
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
    public function photos(Request $request, string $token): JsonResponse
    {
        $schedule = $this->resolveSchedule($token);
        $this->registerView($schedule, $request);

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
            'views_count' => (int) $schedule->photo_views_count,
            'retention_days' => SchedulePhoto::RETENTION_DAYS,
            'expires_at' => $expiresAt?->toISOString(),
            'photos' => SchedulePhotoResource::collection($schedule->photos),
        ]);
    }

    /**
     * นับ "คนเข้าดูอัลบั้ม" แบบกันรีเฟรชซ้ำ
     *
     * ผูกกับ ip + user agent เป็นเวลา 6 ชั่วโมง เพื่อให้เลขที่โชว์เป็นจำนวน "คน"
     * มากกว่าจำนวนครั้ง — ลิงก์อัลบั้มถูกส่งต่อในกลุ่มไลน์ คนเดิมเปิดซ้ำทั้งวัน
     */
    private function registerView(TripSchedule $schedule, Request $request): void
    {
        $key = 'album_view:'.$schedule->id.':'.sha1($request->ip().'|'.$request->userAgent());

        if (Cache::add($key, true, now()->addHours(6))) {
            $schedule->increment('photo_views_count');
        }
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

    private function downloadName(TripSchedule $schedule, SchedulePhoto $photo): string
    {
        $ext = pathinfo($photo->path, PATHINFO_EXTENSION) ?: 'jpg';
        $title = Str::slug((string) $schedule->trip?->title) ?: 'album';
        $date = optional($schedule->departure_date)->format('Ymd') ?: 'photos';

        return "{$title}-{$date}-{$photo->id}.{$ext}";
    }
}
