<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchedulePhotoResource;
use App\Http\Resources\TripPhotoResource;
use App\Jobs\DeleteMediaFilesJob;
use App\Jobs\GeneratePhotoThumbnailJob;
use App\Models\SchedulePhoto;
use App\Models\Trip;
use App\Models\TripPhoto;
use App\Models\TripSchedule;
use App\Services\SchedulePhotoService;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PhotoController extends Controller
{
    use ApiResponse;

    public function __construct(private SchedulePhotoService $photos) {}

    private function disk(): string
    {
        return MediaDisk::name();
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

        $request->validate(SchedulePhotoService::uploadRules());

        $disk = $this->disk();
        $baseSort = (int) $trip->photos()->max('sort_order');

        // Push the files to R2 *before* opening a transaction — these are network
        // round-trips, and holding a transaction open across them is what used to
        // pin DB connections for the length of an upload.
        $uploads = [];
        foreach ($request->file('files') as $file) {
            /** @var UploadedFile $file */
            $uploads[] = $this->photos->storeFile($file, "trips/{$trip->id}", $disk);
        }

        $stored = [];

        DB::transaction(function () use ($trip, $uploads, $disk, $baseSort, &$stored) {
            foreach ($uploads as $idx => $info) {
                $stored[] = $trip->photos()->create($info + [
                    'disk' => $disk,
                    'sort_order' => $baseSort + $idx + 1,
                ]);
            }
        });

        foreach ($stored as $photo) {
            GeneratePhotoThumbnailJob::dispatch(TripPhoto::class, $photo->id)->afterCommit();
        }

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
        $photos = $schedule->photos()->with('uploader:id,name')->get();

        return $this->success(SchedulePhotoResource::collection($photos));
    }

    public function scheduleUpload(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        $request->validate(SchedulePhotoService::uploadRules());

        $photos = $this->photos->upload($schedule, $request->file('files'), $request->user());

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

        $this->photos->detach($schedule, $photo);

        return $this->success(null, 'ลบรูปสำเร็จ');
    }

    /**
     * Remove every photo from this round at once. Each photo is detached from the
     * round; its R2 file is deleted only once no other round still uses it (matching
     * {@see scheduleDestroy}). Returns how many files were queued for removal.
     */
    public function scheduleDestroyAll(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $photos = $schedule->photos()->with('uploader:id,name')->get();

        if ($photos->isEmpty()) {
            return $this->success(
                ['detached' => 0, 'files_removed' => 0],
                'ลบรูปทั้งหมดของรอบนี้แล้ว'
            );
        }

        $photoIds = $photos->pluck('id')->all();
        $orphaned = collect();

        DB::transaction(function () use ($schedule, $photos, $photoIds, &$orphaned) {
            $schedule->photos()->detach();

            // One query for the whole set — this used to be a count() per photo.
            $stillUsed = DB::table('schedule_photo')
                ->whereIn('photo_id', $photoIds)
                ->distinct()
                ->pluck('photo_id')
                ->all();

            $orphaned = $photos->whereNotIn('id', $stillUsed);

            if ($orphaned->isNotEmpty()) {
                SchedulePhoto::whereIn('id', $orphaned->pluck('id')->all())->delete();
            }
        });

        // A mass delete skips model events, so sweep the files ourselves. One job
        // per disk keeps this to a single dispatch no matter how many photos.
        foreach ($orphaned->groupBy(fn (SchedulePhoto $p) => $p->storageDisk()) as $disk => $group) {
            DeleteMediaFilesJob::dispatch($disk, $group->flatMap->mediaPaths()->all());
        }

        return $this->success(
            ['detached' => $photos->count(), 'files_removed' => $orphaned->count()],
            'ลบรูปทั้งหมดของรอบนี้แล้ว'
        );
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
}
