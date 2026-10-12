<?php

namespace App\Services;

use App\Jobs\GeneratePhotoThumbnailJob;
use App\Jobs\ReleaseEndedTripStaffJob;
use App\Models\SchedulePhoto;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\Thumbnailer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * อัปรูปเข้าอัลบั้มของรอบเดินทาง — ทางเดียวที่ทั้งแอดมิน (หน้าเว็บ) และสตาฟ (แอป)
 * ใช้ร่วมกัน จะได้มีกติกาเรื่อง R2/ธุรกรรม/ภาพย่อชุดเดียว
 */
class SchedulePhotoService
{
    /** รับได้กี่รูปต่อคำขอ — แอป/เว็บแบ่งส่งเป็นชุดเองเมื่อเลือกมากกว่านี้ */
    public const MAX_FILES_PER_REQUEST = 20;

    public const MAX_FILE_SIZE_KB = 15360; // 15 MB per photo

    public const ALLOWED_MIMES = 'jpeg,jpg,png,webp,heic,heif';

    /**
     * สตาฟอัปรูปต่อได้อีกกี่วันหลังวันกลับ — รูปส่วนใหญ่ถูกคัดตอนถึงบ้านแล้ว
     * ทั้งที่สตาฟถูกปลดจากรอบตั้งแต่ตีสี่ของวันถัดไป ({@see ReleaseEndedTripStaffJob})
     */
    public const STAFF_GRACE_DAYS = 3;

    private const TIMEZONE = 'Asia/Bangkok';

    /** กติกา validate ของ `files[]` ที่ทุกจุดอัปใช้ร่วมกัน */
    public static function uploadRules(): array
    {
        return [
            'files' => ['required', 'array', 'max:'.self::MAX_FILES_PER_REQUEST],
            'files.*' => ['required', 'file', 'mimes:'.self::ALLOWED_MIMES, 'max:'.self::MAX_FILE_SIZE_KB],
        ];
    }

    /**
     * สตาฟอัปรูปเข้ารอบนี้ได้ไหม ตามวัน — ตั้งแต่วันออกเดินทางถึงวันกลับ + {@see STAFF_GRACE_DAYS}
     * (นับวันแบบเวลาไทย) ก่อนออกเดินทางยังไม่มีรูปทริปให้อัป และรอบที่ถูกยกเลิกปิดเสมอ
     */
    public function staffWindowOpen(TripSchedule $schedule): bool
    {
        if ($schedule->status === 'cancelled' || ! $schedule->departure_date) {
            return false;
        }

        $today = now(self::TIMEZONE)->toDateString();
        $lastDay = ($schedule->return_date ?? $schedule->departure_date)
            ->copy()->addDays(self::STAFF_GRACE_DAYS)->toDateString();

        return $today >= $schedule->departure_date->toDateString() && $today <= $lastDay;
    }

    /** วันสุดท้ายที่สตาฟยังอัปรูปเข้ารอบนี้ได้ (Y-m-d) */
    public function staffWindowEndsOn(TripSchedule $schedule): ?string
    {
        return ($schedule->return_date ?? $schedule->departure_date)
            ?->copy()->addDays(self::STAFF_GRACE_DAYS)->toDateString();
    }

    /**
     * เก็บรูปลงดิสก์สื่อ (R2) แล้วผูกเข้ารอบ ต่อท้ายลำดับเดิม
     *
     * @param  UploadedFile[]  $files
     * @return Collection<int, SchedulePhoto> รูปที่เพิ่งเพิ่ม โหลดผ่านความสัมพันธ์ของรอบ (มี pivot)
     */
    public function upload(TripSchedule $schedule, array $files, ?User $uploader = null): Collection
    {
        $disk = MediaDisk::name();
        $baseSort = (int) $schedule->photos()->max('schedule_photo.sort_order');

        // R2 round-trips first, transaction after — holding a transaction open
        // across network uploads is what used to pin DB connections.
        $uploads = [];
        foreach ($files as $file) {
            $uploads[] = $this->storeFile($file, "schedules/{$schedule->id}", $disk);
        }

        $ids = [];

        DB::transaction(function () use ($schedule, $uploads, $disk, $baseSort, $uploader, &$ids) {
            foreach ($uploads as $idx => $info) {
                $photo = new SchedulePhoto($info + ['disk' => $disk]);
                $photo->uploaded_by = $uploader?->id;
                $photo->save();
                $schedule->photos()->attach($photo->id, ['sort_order' => $baseSort + $idx + 1]);
                $ids[] = $photo->id;
            }
        });

        foreach ($ids as $id) {
            GeneratePhotoThumbnailJob::dispatch(SchedulePhoto::class, $id)->afterCommit();
        }

        // Reload through the relation so each resource carries its pivot (sort_order).
        return $schedule->photos()->whereIn('schedule_photos.id', $ids)->get();
    }

    /**
     * เอารูปออกจากรอบนี้ ไฟล์บน R2 ถูกลบเมื่อไม่มีรอบไหนใช้รูปนี้แล้วเท่านั้น
     * (รูปเดียวกันอาจถูก "ใช้กับรอบอื่น" ไว้)
     */
    public function detach(TripSchedule $schedule, SchedulePhoto $photo): void
    {
        DB::transaction(function () use ($schedule, $photo) {
            $schedule->photos()->detach($photo->id);
            if ($photo->schedules()->count() === 0) {
                $photo->delete(); // model boot hook removes the file from disk
            }
        });
    }

    /**
     * Store the original on the media disk. Reading the dimensions only parses the
     * file header, so it stays here; the thumbnail needs a full GD decode and is
     * left to {@see GeneratePhotoThumbnailJob}.
     */
    public function storeFile(UploadedFile $file, string $folder, string $disk): array
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

        [$width, $height] = Thumbnailer::dimensions($file->getRealPath());

        return [
            'path' => $path,
            'thumb_path' => null,
            'url' => Storage::disk($disk)->url($path),
            'thumb_url' => null,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ];
    }
}
