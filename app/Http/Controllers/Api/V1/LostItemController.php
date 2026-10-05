<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LostItem;
use App\Models\TripSchedule;
use App\Services\LostItemService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ของหาย / ลืมของในทริป — ดู LostItemService
 */
class LostItemController extends Controller
{
    use ApiResponse;

    public function __construct(private LostItemService $items) {}

    /** ของที่เจอในทุกทริปที่ฉันไป (หรือเป็นสตาฟ) ย้อนหลัง 90 วัน */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'items' => $this->items->forUser($user)
                ->map(fn ($i) => $this->items->present($i, $user))
                ->values()->all(),
        ]);
    }

    public function index(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->items->canView($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ดูของในทริปนี้', 403);
        }

        $manager = $this->items->canManage($user, $schedule);

        return $this->success([
            'can_manage' => $manager,
            'items' => $this->items->forUser($user, $schedule)
                ->map(fn ($i) => $this->items->present($i, $user, $manager))
                ->values()->all(),
        ]);
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:300'],
            'photo' => ['nullable', 'image', 'max:8192'],
        ]);

        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->items->canManage($user, $schedule)) {
            return $this->error('โพสต์ของที่เจอได้เฉพาะทีมงาน', 403);
        }

        $item = $this->items->post($user, $schedule, trim($validated['description']), $request->file('photo'));

        return $this->success($this->items->present($item, $user, true), 'แจ้งทุกคนในทริปแล้ว', 201);
    }

    public function claim(Request $request, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        [$item, $error] = $this->resolve($request, $itemId);
        if ($error) {
            return $error;
        }

        try {
            $item = $this->items->claim($request->user(), $item, isset($validated['note']) ? trim($validated['note']) : null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->items->present($item, $request->user()), 'แจ้งทีมงานแล้ว');
    }

    public function unclaim(Request $request, int $itemId): JsonResponse
    {
        [$item, $error] = $this->resolve($request, $itemId);
        if ($error) {
            return $error;
        }

        $user = $request->user();
        if ((int) $item->claimed_by_id !== (int) $user->id && ! $this->items->canManage($user, $item->schedule)) {
            return $this->error('ยกเลิกได้เฉพาะคนที่แจ้งไว้หรือทีมงาน', 403);
        }

        try {
            $item = $this->items->unclaim($item);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->items->present($item, $user), 'ยกเลิกแล้ว');
    }

    public function returned(Request $request, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'returned' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        [$item, $error] = $this->resolve($request, $itemId, manage: true);
        if ($error) {
            return $error;
        }

        try {
            $item = $this->items->markReturned($item, (bool) $validated['returned'], isset($validated['note']) ? trim($validated['note']) : null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->items->present($item, $request->user(), true), $validated['returned'] ? 'บันทึกว่าคืนแล้ว' : 'ยกเลิกแล้ว');
    }

    public function destroy(Request $request, int $itemId): JsonResponse
    {
        [$item, $error] = $this->resolve($request, $itemId, manage: true);
        if ($error) {
            return $error;
        }

        $this->items->delete($item);

        return $this->success(null, 'ลบแล้ว');
    }

    /** หลังบ้าน: ของทุกทริป กรองสถานะได้ (open / claimed / returned) */
    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:open,claimed,returned'],
        ]);

        $items = LostItem::with(['schedule.trip', 'claimedBy', 'postedBy'])
            ->when(($validated['status'] ?? null) === LostItem::STATUS_OPEN, fn ($q) => $q->whereNull('claimed_by_id')->whereNull('returned_at'))
            ->when(($validated['status'] ?? null) === LostItem::STATUS_CLAIMED, fn ($q) => $q->whereNotNull('claimed_by_id')->whereNull('returned_at'))
            ->when(($validated['status'] ?? null) === LostItem::STATUS_RETURNED, fn ($q) => $q->whereNotNull('returned_at'))
            ->latest('id')
            ->limit(300)
            ->get();

        $user = $request->user();

        return $this->success([
            'items' => $items->map(fn ($i) => $this->items->present($i, $user, true))->values()->all(),
            'counts' => [
                'open' => LostItem::whereNull('claimed_by_id')->whereNull('returned_at')->count(),
                'claimed' => LostItem::whereNotNull('claimed_by_id')->whereNull('returned_at')->count(),
                'returned' => LostItem::whereNotNull('returned_at')->count(),
            ],
        ]);
    }

    /**
     * @return array{0: ?LostItem, 1: ?JsonResponse}
     */
    private function resolve(Request $request, int $itemId, bool $manage = false): array
    {
        $item = LostItem::with('schedule.trip')->findOrFail($itemId);
        $user = $request->user();

        if (! $this->items->canView($user, $item->schedule)) {
            return [null, $this->error('คุณไม่มีสิทธิ์ดูของในทริปนี้', 403)];
        }

        if ($manage && ! $this->items->canManage($user, $item->schedule)) {
            return [null, $this->error('ทำได้เฉพาะทีมงาน', 403)];
        }

        return [$item, null];
    }
}
