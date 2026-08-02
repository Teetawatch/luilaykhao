<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchedulePhotoResource;
use App\Models\FaceSearchConsent;
use App\Models\SchedulePhoto;
use App\Models\TripSchedule;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            // เวอร์ชันข้อความยินยอมปัจจุบัน — หน้าอัลบั้มถามใหม่เมื่อเวอร์ชันขยับ
            'face_search_consent_version' => FaceSearchConsent::CURRENT_VERSION,
            'photos' => SchedulePhotoResource::collection($schedule->photos),
        ]);
    }

    /**
     * บันทึกความยินยอม PDPA ก่อนเริ่มค้นหารูปด้วยใบหน้า
     *
     * รับแค่ "รหัสเครื่องแบบสุ่ม + เวอร์ชันข้อความ" ไม่รับรูปและไม่รับเวกเตอร์ใบหน้า
     * การประมวลผลใบหน้าทั้งหมดเกิดบนเบราว์เซอร์ของลูกค้า
     */
    public function storeFaceConsent(Request $request, string $token): JsonResponse
    {
        $schedule = $this->resolveSchedule($token);

        $data = $request->validate([
            'subject_key' => ['required', 'uuid'],
            'consent_version' => ['required', 'string', 'max:20'],
            'accepted' => ['required', 'accepted'],
        ]);

        if ($data['consent_version'] !== FaceSearchConsent::CURRENT_VERSION) {
            return $this->error('ข้อความขอความยินยอมมีการอัปเดต กรุณารีเฟรชหน้านี้แล้วยินยอมอีกครั้ง', 409);
        }

        $consent = FaceSearchConsent::updateOrCreate(
            ['photo_token' => $token, 'subject_key' => $data['subject_key']],
            [
                'trip_schedule_id' => $schedule->id,
                'consent_version' => $data['consent_version'],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'consented_at' => now(),
                'revoked_at' => null,
            ],
        );

        return $this->success([
            'consent_version' => $consent->consent_version,
            'consented_at' => $consent->consented_at?->toISOString(),
        ], 'บันทึกความยินยอมแล้ว');
    }

    /** ถอนความยินยอม — ลูกค้ากด "ล้างข้อมูลใบหน้า" บนหน้าอัลบั้ม */
    public function revokeFaceConsent(Request $request, string $token): JsonResponse
    {
        $this->resolveSchedule($token);

        $data = $request->validate([
            'subject_key' => ['required', 'uuid'],
        ]);

        FaceSearchConsent::where('photo_token', $token)
            ->where('subject_key', $data['subject_key'])
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return $this->success(null, 'ถอนความยินยอมแล้ว');
    }

    /**
     * ส่งรูปแบบ inline จากโดเมนเดียวกับหน้าอัลบั้ม
     *
     * การอ่านพิกเซลลง canvas เพื่อตรวจใบหน้าต้องเป็น same-origin หรือมี CORS ครบ
     * รูปจริงอยู่บน R2 ซึ่งอาจไม่ได้เปิด CORS ไว้ หน้าอัลบั้มจึงถอยมาใช้เส้นทางนี้
     * เมื่อโหลดจาก R2 แบบ crossOrigin ไม่สำเร็จ
     */
    public function photoFile(string $token, int $photoId): StreamedResponse
    {
        $schedule = $this->resolveSchedule($token);
        $photo = $schedule->photos()->where('schedule_photos.id', $photoId)->firstOrFail();

        $disk = Storage::disk($photo->storageDisk());
        // ใช้ภาพย่อเมื่อมี — เบากว่ามากและใหญ่พอ (ด้านยาว 800px) สำหรับตรวจใบหน้า
        $path = ($photo->thumb_path && $disk->exists($photo->thumb_path))
            ? $photo->thumb_path
            : $photo->path;

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => $path === $photo->thumb_path ? 'image/jpeg' : ($photo->mime ?: 'image/jpeg'),
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
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

    /**
     * Stream a ZIP of the album. `?ids=1,2,3` narrows it to a subset — used by
     * "ดาวน์โหลดรูปของฉันทั้งหมด" after a face search. Unknown ids are ignored,
     * so a subset can never reach photos outside this album.
     */
    public function downloadAll(Request $request, string $token): StreamedResponse
    {
        $schedule = $this->resolveSchedule($token);
        $photos = $this->filterByIds($schedule->photos, $request->query('ids'));
        abort_if($photos->isEmpty(), 404, 'ยังไม่มีรูปในอัลบั้มนี้');

        $zipName = $this->albumSlug($schedule).($request->filled('ids') ? '-my-photos' : '').'.zip';

        return response()->streamDownload(function () use ($photos, $schedule) {
            $tmp = tempnam(sys_get_temp_dir(), 'album');
            // กันไฟล์ zip ค้างเมื่อ request ตายกลางคัน (timeout / fatal) — โค้ดหลัง
            // จุดที่ตายจะไม่ถูกรัน แต่ shutdown function ยังทำงาน
            register_shutdown_function(fn () => @unlink($tmp));

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

            // เปิดไฟล์แล้วลบทิ้งทันทีก่อนเริ่มส่ง — ข้อมูลยังอ่านได้จาก fd ที่ถือไว้
            // แต่พื้นที่ถูกคืนทันทีที่สตรีมจบ "หรือ" ลูกค้ากดยกเลิกกลางทาง ซึ่งเดิม
            // ทำให้ไฟล์ก้อนละ ~100MB ค้างใน /tmp เพราะ PHP หยุดสคริปต์ตอน abort
            $handle = fopen($tmp, 'rb');
            @unlink($tmp);

            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }
        }, $zipName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * @param  Collection<int, SchedulePhoto>  $photos
     * @return Collection<int, SchedulePhoto>
     */
    private function filterByIds(Collection $photos, mixed $ids): Collection
    {
        if (! is_string($ids) || trim($ids) === '') {
            return $photos;
        }

        $wanted = collect(explode(',', $ids))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->all();

        return $photos->whereIn('id', $wanted)->values();
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
