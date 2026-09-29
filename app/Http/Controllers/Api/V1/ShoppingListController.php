<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Services\ShoppingListService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ใบซื้อของก่อนออกทริป — ฝั่งสตาฟ (ติ๊ก/เพิ่มของ/ส่งรายงานพร้อมรูป)
 * และฝั่งแอดมิน (ตั้งรายการประจำทริป/แก้ใบของรอบ/รับทราบ/ตีกลับ)
 */
class ShoppingListController extends Controller
{
    use ApiResponse;

    public function __construct(private ShoppingListService $service) {}

    // ─── สตาฟ ──────────────────────────────────────────────────

    public function show(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = $this->assignedSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        return $this->success($this->service->payload($schedule, $request->user()));
    }

    public function mark(Request $request, int $scheduleId, int $itemId): JsonResponse
    {
        $validated = $request->validate(['bought' => ['required', 'boolean']]);
        $schedule = $this->assignedSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        try {
            $this->service->setBought($schedule, $itemId, $request->user(), $validated['bought']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->payload($schedule, $request->user()));
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate($this->itemRules());
        $schedule = $this->assignedSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        try {
            $this->service->addItem($schedule, $request->user(), $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->payload($schedule, $request->user()), 'เพิ่มรายการแล้ว', 201);
    }

    public function destroy(Request $request, int $scheduleId, int $itemId): JsonResponse
    {
        $schedule = $this->assignedSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        try {
            $this->service->deleteItem($schedule, $itemId, $request->user());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->payload($schedule, $request->user()), 'ลบรายการแล้ว');
    }

    public function submit(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.ShoppingListService::MAX_PHOTOS_PER_SUBMIT],
            'photos.*' => ['image', 'max:8192'],
            'total_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'add_to_ledger' => ['nullable', 'boolean'],
        ], [
            'photos.required' => 'ต้องถ่ายรูปของที่ซื้อหรือใบเสร็จอย่างน้อย 1 รูปก่อนส่งรายงาน',
            'photos.min' => 'ต้องถ่ายรูปของที่ซื้อหรือใบเสร็จอย่างน้อย 1 รูปก่อนส่งรายงาน',
        ]);

        $schedule = $this->assignedSchedule($request, $scheduleId);

        if (! $schedule) {
            return $this->error('คุณไม่ได้รับผิดชอบรอบเดินทางนี้', 403);
        }

        try {
            $this->service->submit($schedule, $request->user(), $request->file('photos', []), $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->service->payload($schedule, $request->user()),
            'ส่งรายงานให้แอดมินแล้ว',
        );
    }

    // ─── แอดมิน ────────────────────────────────────────────────

    public function adminRounds(Request $request): JsonResponse
    {
        return $this->success($this->service->adminRounds($request->boolean('include_past')));
    }

    public function adminShow(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        return $this->success($this->service->payload($schedule, $request->user(), true));
    }

    public function adminStore(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate($this->itemRules() + ['per_person' => ['nullable', 'boolean']]);
        $schedule = TripSchedule::with('trip')->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        $this->service->addItem($schedule, $request->user(), $validated, true);

        return $this->success($this->service->payload($schedule, $request->user(), true), 'เพิ่มรายการแล้ว', 201);
    }

    public function adminUpdate(Request $request, int $scheduleId, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'quantity' => ['sometimes', 'numeric', 'min:0.01', 'max:99999'],
            'unit' => ['nullable', 'string', 'max:32'],
            'per_person' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $schedule = TripSchedule::with('trip')->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        try {
            $this->service->updateItem($schedule, $itemId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 404);
        }

        return $this->success($this->service->payload($schedule, $request->user(), true), 'แก้ไขรายการแล้ว');
    }

    public function adminDestroy(Request $request, int $scheduleId, int $itemId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        try {
            $this->service->deleteItem($schedule, $itemId, $request->user(), true);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 404);
        }

        return $this->success($this->service->payload($schedule, $request->user(), true), 'ลบรายการแล้ว');
    }

    public function adminResync(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        $result = $this->service->resync($schedule);

        return $this->success(
            $this->service->payload($schedule, $request->user(), true),
            "ดึงรายการจากทริปแล้ว — เพิ่ม {$result['added']} · อัปเดต {$result['updated']} รายการ",
        );
    }

    public function adminAcknowledge(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        try {
            $this->service->acknowledge($schedule, $request->user());
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->payload($schedule, $request->user(), true), 'รับทราบรายงานแล้ว');
    }

    public function adminReopen(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $schedule = TripSchedule::with('trip')->find($scheduleId);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        try {
            $this->service->reopen($schedule, $request->user(), $validated['reason']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->service->payload($schedule, $request->user(), true), 'ตีกลับให้สตาฟแก้แล้ว');
    }

    public function templateShow(int $tripId): JsonResponse
    {
        $trip = Trip::find($tripId);

        if (! $trip) {
            return $this->error('ไม่พบทริปนี้', 404);
        }

        return $this->success(['trip_id' => $trip->id, 'items' => $this->service->templateFor($trip)]);
    }

    public function templateUpdate(Request $request, int $tripId): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['present', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.per_person' => ['nullable', 'boolean'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);
        $trip = Trip::find($tripId);

        if (! $trip) {
            return $this->error('ไม่พบทริปนี้', 404);
        }

        return $this->success(
            ['trip_id' => $trip->id, 'items' => $this->service->saveTemplate($trip, $validated['items'])],
            'บันทึกรายการประจำทริปแล้ว',
        );
    }

    // ─── ภายใน ─────────────────────────────────────────────────

    /** @return array<string, array<int, string>> */
    private function itemRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999'],
            'unit' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** รอบที่สตาฟคนนี้ประจำอยู่ (ยังไม่ถูกปล่อยตัว) */
    private function assignedSchedule(Request $request, int $scheduleId): ?TripSchedule
    {
        if (! $request->user()->hasRole('staff')) {
            return null;
        }

        return TripSchedule::with('trip')
            ->whereKey($scheduleId)
            ->whereHas('activeStaff', fn ($q) => $q->where('users.id', $request->user()->id))
            ->first();
    }
}
