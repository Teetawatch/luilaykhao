<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchedulePhotoResource;
use App\Http\Resources\TripPhotoResource;
use App\Models\SchedulePhoto;
use App\Models\Trip;
use App\Models\TripPhoto;
use App\Models\TripSchedule;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PhotoController extends Controller
{
    use ApiResponse;

    private const MAX_FILES_PER_REQUEST = 20;

    private const MAX_FILE_SIZE_KB = 15360; // 15 MB per photo

    private const ALLOWED_MIMES = 'jpeg,jpg,png,webp,heic,heif';

    private const THUMB_MAX_EDGE = 800; // longest side of the generated thumbnail

    private const THUMB_QUALITY = 82;

    private function disk(): string
    {
        return config('filesystems.default') === 'r2' ? 'r2' : (
            // Prefer r2 when configured, otherwise fall back to public.
            config('filesystems.disks.r2.bucket') ? 'r2' : 'public'
        );
    }

    /* ───── Trip photos ─────────────────────────────────────── */

    public function tripIndex(int $tripId): JsonResponse
    {
        $trip = Trip::findOrFail($tripId);
        $photos = $trip->photos()->get();

        return $this->success(TripPhotoResource::collection($photos));
    }

    public function tripUpload(Request $request, int $tripId): JsonResponse
    {
        $trip = Trip::findOrFail($tripId);

        $request->validate([
            'files' => ['required', 'array', 'max:'.self::MAX_FILES_PER_REQUEST],
            'files.*' => ['required', 'file', 'mimes:'.self::ALLOWED_MIMES, 'max:'.self::MAX_FILE_SIZE_KB],
        ]);

        $disk = $this->disk();
        $baseSort = (int) $trip->photos()->max('sort_order');
        $stored = [];

        DB::transaction(function () use ($trip, $request, $disk, $baseSort, &$stored) {
            foreach ($request->file('files') as $idx => $file) {
                /** @var UploadedFile $file */
                $info = $this->storeFile($file, "trips/{$trip->id}", $disk);
                $photo = $trip->photos()->create($info + [
                    'disk' => $disk,
                    'sort_order' => $baseSort + $idx + 1,
                ]);
                $stored[] = $photo;
            }
        });

        return $this->success(
            TripPhotoResource::collection(collect($stored)),
            'อัปโหลดรูปทริปสำเร็จ',
            201
        );
    }

    public function tripDestroy(int $tripId, int $photoId): JsonResponse
    {
        $photo = TripPhoto::where('trip_id', $tripId)->findOrFail($photoId);
        $photo->delete(); // model boot removes the file from disk

        return $this->success(null, 'ลบรูปสำเร็จ');
    }

    public function tripReorder(Request $request, int $tripId): JsonResponse
    {
        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ids = $request->input('order');
        $photos = TripPhoto::where('trip_id', $tripId)->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $position => $id) {
            if (isset($photos[$id])) {
                $photos[$id]->update(['sort_order' => $position + 1]);
            }
        }

        return $this->success(null, 'จัดเรียงรูปสำเร็จ');
    }

    /* ───── Schedule photos ─────────────────────────────────── */

    public function scheduleIndex(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $photos = $schedule->photos()->get();

        return $this->success(SchedulePhotoResource::collection($photos));
    }

    public function scheduleUpload(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        $request->validate([
            'files' => ['required', 'array', 'max:'.self::MAX_FILES_PER_REQUEST],
            'files.*' => ['required', 'file', 'mimes:'.self::ALLOWED_MIMES, 'max:'.self::MAX_FILE_SIZE_KB],
        ]);

        $disk = $this->disk();
        $baseSort = (int) $schedule->photos()->max('schedule_photo.sort_order');
        $ids = [];

        DB::transaction(function () use ($schedule, $request, $disk, $baseSort, &$ids) {
            foreach ($request->file('files') as $idx => $file) {
                /** @var UploadedFile $file */
                $info = $this->storeFile($file, "schedules/{$schedule->id}", $disk);
                $photo = SchedulePhoto::create($info + ['disk' => $disk]);
                $schedule->photos()->attach($photo->id, ['sort_order' => $baseSort + $idx + 1]);
                $ids[] = $photo->id;
            }
        });

        // Reload through the relation so each resource carries its pivot (sort_order).
        $photos = $schedule->photos()->whereIn('schedule_photos.id', $ids)->get();

        return $this->success(
            SchedulePhotoResource::collection($photos),
            'อัปโหลดรูปรอบเดินทางสำเร็จ',
            201
        );
    }

    /**
     * Attach photos already uploaded to one round onto other rounds of the same trip,
     * so the same R2 objects are reused without re-uploading. When photo_ids is omitted
     * every photo of the source round is applied.
     */
    public function scheduleApply(Request $request, int $scheduleId): JsonResponse
    {
        $source = TripSchedule::findOrFail($scheduleId);

        $request->validate([
            'schedule_ids' => ['required', 'array', 'min:1'],
            'schedule_ids.*' => ['integer', 'distinct'],
            'photo_ids' => ['sometimes', 'array'],
            'photo_ids.*' => ['integer'],
        ]);

        $photoQuery = $source->photos();
        if ($request->filled('photo_ids')) {
            $photoQuery->whereIn('schedule_photos.id', $request->input('photo_ids'));
        }
        $photos = $photoQuery->get();

        if ($photos->isEmpty()) {
            return $this->error('ไม่พบรูปที่จะนำไปใช้กับรอบอื่น', 422);
        }

        // Only allow targets within the same trip, never the source itself.
        $targets = TripSchedule::whereIn('id', $request->input('schedule_ids'))
            ->where('trip_id', $source->trip_id)
            ->where('id', '!=', $source->id)
            ->get();

        if ($targets->isEmpty()) {
            return $this->error('ไม่พบรอบเดินทางปลายทางที่ถูกต้อง (ต้องเป็นทริปเดียวกัน)', 422);
        }

        $attached = 0;

        DB::transaction(function () use ($targets, $photos, &$attached) {
            foreach ($targets as $target) {
                $base = (int) $target->photos()->max('schedule_photo.sort_order');
                $existing = $target->photos()->pluck('schedule_photos.id')->all();
                $position = 0;

                foreach ($photos as $photo) {
                    if (in_array($photo->id, $existing, true)) {
                        continue; // already on this round
                    }
                    $position++;
                    $target->photos()->attach($photo->id, ['sort_order' => $base + $position]);
                    $attached++;
                }
            }
        });

        return $this->success(
            ['attached' => $attached, 'schedules' => $targets->count()],
            "นำรูปไปใช้กับ {$targets->count()} รอบเดินทางแล้ว"
        );
    }

    public function scheduleDestroy(int $scheduleId, int $photoId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $photo = $schedule->photos()->where('schedule_photos.id', $photoId)->firstOrFail();

        DB::transaction(function () use ($schedule, $photo) {
            // Detach from this round only; remove the file from R2 once no round uses it.
            $schedule->photos()->detach($photo->id);
            if ($photo->schedules()->count() === 0) {
                $photo->delete(); // model boot hook removes the file from disk
            }
        });

        return $this->success(null, 'ลบรูปสำเร็จ');
    }

    public function scheduleReorder(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $attached = $schedule->photos()->pluck('schedule_photos.id')->all();

        foreach ($request->input('order') as $position => $id) {
            if (in_array((int) $id, $attached, true)) {
                $schedule->photos()->updateExistingPivot($id, ['sort_order' => $position + 1]);
            }
        }

        return $this->success(null, 'จัดเรียงรูปสำเร็จ');
    }

    /* ───── Public album share link ─────────────────────────── */

    public function scheduleShareShow(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        return $this->success($this->sharePayload($schedule));
    }

    public function scheduleShareStore(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $schedule->ensurePhotoToken($request->boolean('rotate'));

        return $this->success(
            $this->sharePayload($schedule),
            $request->boolean('rotate') ? 'สร้างลิงก์ใหม่แล้ว' : 'เปิดลิงก์อัลบั้มแล้ว'
        );
    }

    public function scheduleShareDestroy(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $schedule->revokePhotoToken();

        return $this->success($this->sharePayload($schedule->fresh()), 'ปิดลิงก์อัลบั้มแล้ว');
    }

    private function sharePayload(TripSchedule $schedule): array
    {
        return [
            'token' => $schedule->photo_token,
            'url' => $schedule->photoAlbumUrl(),
        ];
    }

    /* ───── Shared upload helper ────────────────────────────── */

    private function storeFile(UploadedFile $file, string $folder, string $disk): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = date('YmdHis').'_'.Str::random(10).'.'.$ext;
        $path = trim($folder, '/').'/'.$filename;

        $stream = fopen($file->getRealPath(), 'r');
        Storage::disk($disk)->put($path, $stream, [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType(),
        ]);
        if (is_resource($stream)) {
            fclose($stream);
        }

        [$width, $height] = $this->imageDimensions($file->getRealPath());

        // Downscaled thumbnail for fast grids. Best-effort: if GD can't read the
        // format (e.g. a HEIC slips through without browser conversion) we simply
        // store no thumbnail and the UI falls back to the full image.
        $thumbPath = null;
        $thumbUrl = null;
        if ($thumbData = $this->makeThumbnail($file->getRealPath())) {
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $thumbPath = trim($folder, '/').'/thumbs/'.$base.'.jpg';
            Storage::disk($disk)->put($thumbPath, $thumbData, [
                'visibility' => 'public',
                'ContentType' => 'image/jpeg',
            ]);
            $thumbUrl = Storage::disk($disk)->url($thumbPath);
        }

        return [
            'path' => $path,
            'thumb_path' => $thumbPath,
            'url' => Storage::disk($disk)->url($path),
            'thumb_url' => $thumbUrl,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ];
    }

    private function imageDimensions(string $path): array
    {
        $info = @getimagesize($path);
        if (! $info) {
            return [null, null];
        }

        return [$info[0] ?? null, $info[1] ?? null];
    }

    /**
     * Build a JPEG thumbnail (longest edge {@see self::THUMB_MAX_EDGE}px) with GD,
     * honouring EXIF orientation. Returns the encoded bytes, or null on any failure.
     */
    private function makeThumbnail(string $sourcePath): ?string
    {
        $info = @getimagesize($sourcePath);
        if (! $info) {
            return null;
        }

        $src = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => false,
        };
        if (! $src) {
            return null;
        }

        $src = $this->applyExifOrientation($src, $sourcePath, $info[2] ?? null);

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::THUMB_MAX_EDGE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($tw, $th);
        // Flatten transparency onto white so PNGs/WebP get a sane JPEG background.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        ob_start();
        imagejpeg($dst, null, self::THUMB_QUALITY);
        $data = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $data ?: null;
    }

    private function applyExifOrientation(\GdImage $img, string $path, ?int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? 0;

        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };

        if ($rotated) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }
}
