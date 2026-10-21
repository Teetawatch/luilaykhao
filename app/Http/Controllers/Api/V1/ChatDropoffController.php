<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatDropoffRound;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Services\ChatDropoffService;
use App\Services\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * จุดลงขากลับในห้องแชททริป — ดู ChatDropoffService
 */
class ChatDropoffController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatDropoffService $dropoffs,
    ) {}

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เปิดการ์ดจุดลงได้เฉพาะทีมงานประจำรอบ', 403);
        }

        try {
            $round = $this->dropoffs->open($user, $schedule);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = ChatMessage::with([
            'user:id,name,nickname,avatar',
            'replyTo.user:id,name,nickname,avatar',
            'reactions:id,message_id,user_id,emoji',
            'dropoffRound',
        ])->find($round->message_id);

        if ($message) {
            $this->chatService->markRead($user, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $user->id, [], true);
        }

        return $this->success(
            $message ? $this->chatService->presentMessage($message, $user->id) : null,
            'เปิดการ์ดจุดลงขากลับแล้ว',
            201,
        );
    }

    /** ของฉัน + จุดที่เลือกได้ (+ รายการจุดเรียงตามทางสำหรับทีมงาน) */
    public function show(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        return $this->payload($request, $schedule, $round, withOptions: true);
    }

    /**
     * เลือกจุดลง — pickup_point_id = จุดรับของรอบ, label (+lat/lng) = พิมพ์เอง,
     * ไม่ส่งทั้งคู่ = ใช้จุดรับขาไป
     */
    public function choose(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1', 'max:50'],
            'passenger_ids.*' => ['integer'],
            'pickup_point_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:120', 'prohibits:pickup_point_id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        try {
            $round = $this->dropoffs->choose(
                $user,
                $round,
                $validated['passenger_ids'],
                isset($validated['pickup_point_id']) ? (int) $validated['pickup_point_id'] : null,
                $validated['label'] ?? null,
                isset($validated['lat']) ? (float) $validated['lat'] : null,
                isset($validated['lng']) ? (float) $validated['lng'] : null,
                $validated['note'] ?? null,
                $this->chatService->canModerate($user, $schedule),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $schedule, $round, message: 'บันทึกจุดลงแล้ว');
    }

    public function drop(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1', 'max:200'],
            'passenger_ids.*' => ['integer'],
            'dropped' => ['required', 'boolean'],
        ]);

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $round = $this->dropoffs->setDropped($request->user(), $round, $validated['passenger_ids'], (bool) $validated['dropped']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $schedule, $round, message: $validated['dropped'] ? 'ส่งถึงแล้ว' : 'ยกเลิกแล้ว');
    }

    public function close(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        return $this->payload($request, $schedule, $this->dropoffs->close($round), message: 'ปิดการ์ดแล้ว');
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?ChatDropoffRound, 2: ?JsonResponse}
     */
    private function resolve(Request $request, int $scheduleId, int $roundId, bool $staffOnly = false): array
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return [null, null, $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403)];
        }

        if ($staffOnly && ! $this->chatService->canModerate($user, $schedule)) {
            return [null, null, $this->error('ทำได้เฉพาะทีมงานประจำรอบ', 403)];
        }

        $round = ChatDropoffRound::where('schedule_id', $scheduleId)->findOrFail($roundId);

        return [$schedule, $round, null];
    }

    private function payload(Request $request, TripSchedule $schedule, ChatDropoffRound $round, bool $withOptions = false, string $message = 'สำเร็จ'): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'message_id' => (int) $round->message_id,
            'dropoff' => $this->dropoffs->present(
                $round,
                viewerId: $user->id,
                withStaffDetail: $this->chatService->canModerate($user, $schedule),
            ),
            ...($withOptions ? ['options' => $this->dropoffs->options($schedule)] : []),
        ], $message);
    }
}
