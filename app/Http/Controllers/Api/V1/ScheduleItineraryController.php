<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScheduleItineraryItem;
use App\Models\TripSchedule;
use App\Services\ScheduleItineraryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * กำหนดการต่อรอบเดินทาง — ฝั่งสตาฟ (แอปลูกค้า) อ่านอย่างเดียว, ฝั่งแอดมิน/operator
 * (admin panel) สร้าง/แก้/ลบ/จัดลำดับ
 */
class ScheduleItineraryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ScheduleItineraryService $service,
    ) {}

    // ── อ่าน (สตาฟประจำรอบ + ทีมงาน) ──────────────────────────────────────────

    public function index(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canRead($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ดูกำหนดการของรอบนี้', 403);
        }

        return $this->success([
            'items' => $this->service->list($schedule)
                ->map(fn ($i) => $this->service->present($i))
                ->all(),
            'can_manage' => $this->service->canManage($user, $schedule),
        ]);
    }

    /**
     * เช็คอินจุดกำหนดการ — สตาฟประจำรอบ (และทีมงาน) กดยืนยันว่ามาถึงจุดนี้แล้ว
     * สถานะแชร์ทั้งทีมของรอบ ใช้กันลืม/ผิดแผน ส่ง reached=false เพื่อยกเลิก
     */
    public function reach(Request $request, int $scheduleId, int $itemId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        // เช็คอินเป็นการกระทำของทีมงาน (สตาฟประจำรอบ/แอดมิน) เท่านั้น — ลูกค้าที่
        // อ่านกำหนดการได้ผ่าน canRead ต้องไม่มีสิทธิ์กดยืนยันจุด
        if (! $this->service->canCheckIn($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์อัปเดตกำหนดการของรอบนี้', 403);
        }

        $item = ScheduleItineraryItem::where('schedule_id', $schedule->id)->findOrFail($itemId);
        $reached = $request->boolean('reached', true);
        $item = $this->service->setReached($item, $user, $reached);

        return $this->success(
            $this->service->present($item),
            $reached ? 'เช็คอินจุดนี้แล้ว' : 'ยกเลิกเช็คอินแล้ว',
        );
    }

    // ── จัดการ (admin/operator เท่านั้น) ───────────────────────────────────────

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $this->validatePayload($request, true);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canManage($user, $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่จัดการกำหนดการได้', 403);
        }

        $item = $this->service->create($schedule, $user, $validated);

        return $this->success($this->service->present($item), 'เพิ่มกำหนดการแล้ว', 201);
    }

    public function update(Request $request, int $scheduleId, int $itemId): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canManage($user, $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่จัดการกำหนดการได้', 403);
        }

        $item = ScheduleItineraryItem::where('schedule_id', $scheduleId)->findOrFail($itemId);
        $updated = $this->service->update($item, $validated);

        return $this->success($this->service->present($updated), 'แก้ไขกำหนดการแล้ว');
    }

    public function destroy(Request $request, int $scheduleId, int $itemId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canManage($user, $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่จัดการกำหนดการได้', 403);
        }

        $item = ScheduleItineraryItem::where('schedule_id', $scheduleId)->findOrFail($itemId);
        $this->service->delete($item);

        return $this->success(['deleted' => true], 'ลบกำหนดการแล้ว');
    }

    public function reorder(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canManage($user, $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่จัดการกำหนดการได้', 403);
        }

        $this->service->reorder($schedule, $validated['ids']);

        return $this->success([
            'items' => $this->service->list($schedule)
                ->map(fn ($i) => $this->service->present($i))
                ->all(),
        ], 'จัดลำดับกำหนดการแล้ว');
    }

    private function validatePayload(Request $request, bool $isCreate): array
    {
        return $request->validate([
            'item_date' => ['nullable', 'date'],
            'time' => ['nullable', 'date_format:H:i'],
            'title' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:200'],
            'detail' => ['nullable', 'string', 'max:4000'],
            'link' => ['nullable', 'string', 'url', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
