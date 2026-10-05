<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScheduleRoom;
use App\Models\TripSchedule;
use App\Services\ChatService;
use App\Services\ScheduleRoomService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ห้องพักของรอบ — ลูกทริปดู ทีมงานประจำรอบ/แอดมินจัด (ดู ScheduleRoomService)
 * สิทธิ์ใช้ชุดเดียวกับห้องแชททริป: ใครอยู่ในห้องแชทก็เห็นห้องพัก
 */
class ScheduleRoomController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ScheduleRoomService $rooms,
    ) {}

    public function index(Request $request, int $scheduleId): JsonResponse
    {
        [$schedule, $error] = $this->resolve($request, $scheduleId);
        if ($error) {
            return $error;
        }

        return $this->present($request, $schedule);
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'stay_label' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:200'],
            'passenger_ids' => ['nullable', 'array', 'max:'.ScheduleRoom::MAX_GUESTS],
            'passenger_ids.*' => ['integer'],
        ]);

        [$schedule, $error] = $this->resolve($request, $scheduleId, manage: true);
        if ($error) {
            return $error;
        }

        $room = $this->rooms->create($schedule, trim($validated['name']), $validated['stay_label'] ?? null, $validated['note'] ?? null);
        if (! empty($validated['passenger_ids'])) {
            $this->rooms->setGuests($room, $validated['passenger_ids']);
        }

        return $this->present($request, $schedule, 'เพิ่มห้องแล้ว', 201);
    }

    public function update(Request $request, int $scheduleId, int $roomId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        [$schedule, $error] = $this->resolve($request, $scheduleId, manage: true);
        if ($error) {
            return $error;
        }

        $room = ScheduleRoom::where('schedule_id', $scheduleId)->findOrFail($roomId);
        $this->rooms->update($room, trim($validated['name']), $validated['note'] ?? null);

        return $this->present($request, $schedule, 'บันทึกแล้ว');
    }

    public function destroy(Request $request, int $scheduleId, int $roomId): JsonResponse
    {
        [$schedule, $error] = $this->resolve($request, $scheduleId, manage: true);
        if ($error) {
            return $error;
        }

        ScheduleRoom::where('schedule_id', $scheduleId)->findOrFail($roomId)->delete();

        return $this->present($request, $schedule, 'ลบห้องแล้ว');
    }

    public function setGuests(Request $request, int $scheduleId, int $roomId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['present', 'array', 'max:'.ScheduleRoom::MAX_GUESTS],
            'passenger_ids.*' => ['integer'],
        ]);

        [$schedule, $error] = $this->resolve($request, $scheduleId, manage: true);
        if ($error) {
            return $error;
        }

        $room = ScheduleRoom::where('schedule_id', $scheduleId)->findOrFail($roomId);

        try {
            $this->rooms->setGuests($room, $validated['passenger_ids']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->present($request, $schedule, 'บันทึกแล้ว');
    }

    public function auto(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'room_size' => ['required', 'integer', 'min:1', 'max:'.ScheduleRoom::MAX_GUESTS],
            'stay_label' => ['nullable', 'string', 'max:80'],
            'prefix' => ['nullable', 'string', 'max:40'],
        ]);

        [$schedule, $error] = $this->resolve($request, $scheduleId, manage: true);
        if ($error) {
            return $error;
        }

        $created = $this->rooms->autoAssign(
            $schedule,
            $validated['stay_label'] ?? null,
            (int) $validated['room_size'],
            trim($validated['prefix'] ?? '') ?: 'ห้อง',
        );

        return $this->present($request, $schedule, $created > 0 ? "จัดให้แล้ว {$created} ห้อง" : 'ทุกคนมีห้องแล้ว');
    }

    public function announce(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'stay_label' => ['nullable', 'string', 'max:80'],
        ]);

        [$schedule, $error] = $this->resolve($request, $scheduleId, manage: true);
        if ($error) {
            return $error;
        }

        try {
            $count = $this->rooms->announce($schedule, $validated['stay_label'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->present($request, $schedule, "ประกาศ {$count} ห้องในแชทแล้ว");
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?JsonResponse}
     */
    private function resolve(Request $request, int $scheduleId, bool $manage = false): array
    {
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return [null, $this->error('คุณไม่มีสิทธิ์ดูห้องพักของรอบนี้', 403)];
        }

        if ($manage && ! $this->chatService->canModerate($user, $schedule)) {
            return [null, $this->error('จัดห้องพักได้เฉพาะทีมงานประจำรอบ', 403)];
        }

        return [$schedule, null];
    }

    private function present(Request $request, TripSchedule $schedule, string $message = 'ok', int $status = 200): JsonResponse
    {
        $user = $request->user();

        return $this->success(
            $this->rooms->present($schedule, (int) $user->id, $this->chatService->canModerate($user, $schedule)),
            $message,
            $status,
        );
    }
}
