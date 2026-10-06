<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatSupplyRequest;
use App\Models\TripSchedule;
use App\Services\ChatRestStopService;
use App\Services\ChatService;
use App\Services\ChatSupplyService;
use App\Services\TripRosterService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ขอยา / ของจำเป็นจากสตาฟ ส่งถึงที่นั่ง — ดู ChatSupplyService
 */
class ChatSupplyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatSupplyService $supplies,
        private ChatRestStopService $restStops,
    ) {}

    /** ทีมงาน: คิวทั้งรอบ · ลูกทริป: คำขอของฉันใน 24 ชม. */
    public function index(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $staff = $this->chatService->canModerate($user, $schedule);
        $names = $this->supplies->passengerNames($schedule);
        $list = $staff ? $this->supplies->queue($schedule) : $this->supplies->mine($user, $schedule);

        return $this->success([
            'can_manage' => $staff,
            'pending' => $this->supplies->pendingCount($schedule),
            'requests' => $list->map(fn ($r) => $this->supplies->present($r, $names))->values()->all(),
            // คนที่ฉันขอแทนได้ (ตัวเอง + คนในกลุ่มที่จองให้) พร้อมเลขที่นั่ง
            'passengers' => $staff ? [] : $this->myPassengers($user->id, $schedule),
        ]);
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'item' => ['required', Rule::in(array_keys(ChatSupplyRequest::ITEMS))],
            'passenger_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }
        if ($this->chatService->canModerate($user, $schedule)) {
            return $this->error('ปุ่มนี้สำหรับลูกทริปครับ', 403);
        }
        if (! $this->restStops->isWithinTripWindow($schedule)) {
            return $this->error('ขอของจากทีมงานได้ระหว่างเดินทางเท่านั้นครับ', 422);
        }

        try {
            $supply = $this->supplies->request(
                $user,
                $schedule,
                $validated['item'],
                isset($validated['passenger_id']) ? (int) $validated['passenger_id'] : null,
                $validated['note'] ?? null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            $this->supplies->present($supply->fresh('user'), $this->supplies->passengerNames($schedule)),
            'ส่งคำขอแล้ว ทีมงานกำลังนำไปให้ครับ',
            201,
        );
    }

    public function destroy(Request $request, int $scheduleId, int $requestId): JsonResponse
    {
        [$schedule, $supply, $error] = $this->resolve($request, $scheduleId, $requestId);
        if ($error) {
            return $error;
        }

        if ((int) $supply->user_id !== (int) $request->user()->id) {
            return $this->error('ยกเลิกได้เฉพาะคำขอของตัวเอง', 403);
        }

        try {
            $this->supplies->cancel($supply);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'ยกเลิกคำขอแล้ว');
    }

    public function deliver(Request $request, int $scheduleId, int $requestId): JsonResponse
    {
        [$schedule, $supply, $error] = $this->resolve($request, $scheduleId, $requestId, staffOnly: true);
        if ($error) {
            return $error;
        }

        $supply = $this->supplies->deliver($request->user(), $supply);

        return $this->success($this->supplies->present($supply->load('user'), $this->supplies->passengerNames($schedule)), 'ส่งแล้ว');
    }

    public function decline(Request $request, int $scheduleId, int $requestId): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        [$schedule, $supply, $error] = $this->resolve($request, $scheduleId, $requestId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $supply = $this->supplies->decline($request->user(), $supply, isset($validated['note']) ? trim($validated['note']) : null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->supplies->present($supply->load('user'), $this->supplies->passengerNames($schedule)), 'แจ้งผู้ขอแล้ว');
    }

    /** @return array<int, array<string, mixed>> */
    private function myPassengers(int $userId, TripSchedule $schedule): array
    {
        $seats = $this->supplies->seatLabels($schedule);

        return app(TripRosterService::class)->passengers($schedule)
            ->where('user_id', $userId)
            ->map(fn ($p) => [
                'passenger_id' => $p['passenger_id'],
                'name' => $p['name'],
                'seat_label' => $seats[$p['passenger_id']] ?? null,
            ])->values()->all();
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?ChatSupplyRequest, 2: ?JsonResponse}
     */
    private function resolve(Request $request, int $scheduleId, int $requestId, bool $staffOnly = false): array
    {
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return [null, null, $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403)];
        }
        if ($staffOnly && ! $this->chatService->canModerate($user, $schedule)) {
            return [null, null, $this->error('ทำได้เฉพาะทีมงานประจำรอบ', 403)];
        }

        $supply = ChatSupplyRequest::where('schedule_id', $scheduleId)->with('schedule.trip')->findOrFail($requestId);

        return [$schedule, $supply, null];
    }
}
