<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripPhotoResource;
use App\Http\Resources\SchedulePhotoResource;
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
            'files' => ['required', 'array', 'max:' . self::MAX_FILES_PER_REQUEST],
            'files.*' => ['required', 'file', 'mimes:' . self::ALLOWED_MIMES, 'max:' . self::MAX_FILE_SIZE_KB],
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
            'files' => ['required', 'array', 'max:' . self::MAX_FILES_PER_REQUEST],
            'files.*' => ['required', 'file', 'mimes:' . self::ALLOWED_MIMES, 'max:' . self::MAX_FILE_SIZE_KB],
        ]);

        $disk = $this->disk();
        $baseSort = (int) $schedule->photos()->max('sort_order');
        $stored = [];

        DB::transaction(function () use ($schedule, $request, $disk, $baseSort, &$stored) {
            foreach ($request->file('files') as $idx => $file) {
                /** @var UploadedFile $file */
                $info = $this->storeFile($file, "schedules/{$schedule->id}", $disk);
                $photo = $schedule->photos()->create($info + [
                    'disk' => $disk,
                    'sort_order' => $baseSort + $idx + 1,
                ]);
                $stored[] = $photo;
            }
        });

        return $this->success(
            SchedulePhotoResource::collection(collect($stored)),
            'อัปโหลดรูปรอบเดินทางสำเร็จ',
            201
        );
    }

    public function scheduleDestroy(int $scheduleId, int $photoId): JsonResponse
    {
        $photo = SchedulePhoto::where('schedule_id', $scheduleId)->findOrFail($photoId);
        $photo->delete();

        return $this->success(null, 'ลบรูปสำเร็จ');
    }

    public function scheduleReorder(Request $request, int $scheduleId): JsonResponse
    {
        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ids = $request->input('order');
        $photos = SchedulePhoto::where('schedule_id', $scheduleId)->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $position => $id) {
            if (isset($photos[$id])) {
                $photos[$id]->update(['sort_order' => $position + 1]);
            }
        }

        return $this->success(null, 'จัดเรียงรูปสำเร็จ');
    }

    /* ───── Shared upload helper ────────────────────────────── */

    private function storeFile(UploadedFile $file, string $folder, string $disk): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = date('YmdHis') . '_' . Str::random(10) . '.' . $ext;
        $path = trim($folder, '/') . '/' . $filename;

        $stream = fopen($file->getRealPath(), 'r');
        Storage::disk($disk)->put($path, $stream, [
            'visibility' => 'public',
            'ContentType' => $file->getMimeType(),
        ]);
        if (is_resource($stream)) {
            fclose($stream);
        }

        [$width, $height] = $this->imageDimensions($file->getRealPath());

        return [
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
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
        if (!$info) {
            return [null, null];
        }
        return [$info[0] ?? null, $info[1] ?? null];
    }
}
