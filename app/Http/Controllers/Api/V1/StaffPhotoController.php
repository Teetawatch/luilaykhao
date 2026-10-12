<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SchedulePhotoResource;
use App\Models\SchedulePhoto;
use App\Models\TripSchedule;
use App\Services\SchedulePhotoService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * อัลบั้มรอบเดินทาง ฝั่งสตาฟ — สตาฟอัปรูปจากมือถือเข้ารอบที่ตัวเองทำงาน (ทีละหลายรูป
 * ขึ้น R2 ทางเดียวกับหน้าแอดมิน) แล้วลูกค้าเห็นในหน้ารูปของการจองทันที
 *
 * สิทธิ์อิงประวัติการมอบหมาย (`staff()`) ไม่ใช่แค่คนที่ยังไม่ถูกปลด เพราะสตาฟถูกปลด
 * ตั้งแต่ตีสี่ของวันหลังกลับ แต่รูปส่วนใหญ่ถูกคัดตอนถึงบ้าน — กรอบเวลาจริงคือ
 * {@see SchedulePhotoService::staffWindowOpen()}
 */
class StaffPhotoController extends Controller
{
    use ApiResponse;

    public function __construct(private SchedulePhotoService $photos) {}

    public function index(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = $this->staffSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        return $this->success($this->payload($schedule));
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = $this->staffSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        if (! $this->photos->staffWindowOpen($schedule)) {
            return $this->error('รอบนี้ปิดรับรูปแล้ว — อัปได้ตั้งแต่วันเดินทางจนถึง '
                .SchedulePhotoService::STAFF_GRACE_DAYS.' วันหลังกลับ', 422);
        }

        $request->validate(SchedulePhotoService::uploadRules());

        $uploaded = $this->photos->upload($schedule, $request->file('files'), $request->user());
        $uploaded->load('uploader:id,name');

        return $this->success([
            'uploaded' => SchedulePhotoResource::collection($uploaded),
            'photos_count' => $schedule->photos()->count(),
        ], 'อัปรูปเข้าอัลบั้มรอบแล้ว', 201);
    }

    /** ลบได้เฉพาะรูปที่ตัวเองอัป — รูปของแอดมินหรือสตาฟคนอื่นไม่ใช่ของเรา */
    public function destroy(Request $request, int $scheduleId, int $photoId): JsonResponse
    {
        $schedule = $this->staffSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        /** @var SchedulePhoto|null $photo */
        $photo = $schedule->photos()->where('schedule_photos.id', $photoId)->first();

        if (! $photo) {
            return $this->error('ไม่พบรูปนี้ในรอบเดินทาง', 404);
        }

        if ($photo->uploaded_by !== $request->user()->id) {
            return $this->error('ลบได้เฉพาะรูปที่คุณอัปเอง', 403);
        }

        $this->photos->detach($schedule, $photo);

        return $this->success($this->payload($schedule), 'ลบรูปแล้ว');
    }

    private function payload(TripSchedule $schedule): array
    {
        $photos = $schedule->photos()->with('uploader:id,name')->get();

        return [
            'photos' => SchedulePhotoResource::collection($photos),
            'photos_count' => $photos->count(),
            'can_upload' => $this->photos->staffWindowOpen($schedule),
            'upload_until' => $this->photos->staffWindowEndsOn($schedule),
            'max_files_per_request' => SchedulePhotoService::MAX_FILES_PER_REQUEST,
            'retention_days' => SchedulePhoto::RETENTION_DAYS,
        ];
    }

    private function staffSchedule(Request $request, int $scheduleId): ?TripSchedule
    {
        if (! $request->user()->hasRole('staff')) {
            return null;
        }

        return TripSchedule::with('trip')
            ->whereKey($scheduleId)
            ->whereHas('staff', fn ($q) => $q->where('users.id', $request->user()->id))
            ->first();
    }
}
