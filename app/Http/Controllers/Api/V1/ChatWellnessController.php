<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatWellnessCheck;
use App\Models\TripSchedule;
use App\Services\ChatService;
use App\Services\ChatWellnessService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * เช็คอาการ "ยังไหวไหม" ในห้องแชททริป — ดู ChatWellnessService
 */
class ChatWellnessController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatWellnessService $wellness,
    ) {}

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เช็คอาการได้เฉพาะทีมงานประจำรอบ', 403);
        }

        try {
            $check = $this->wellness->open($user, $schedule, isset($validated['label']) ? trim($validated['label']) : null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = ChatMessage::with([
            'user:id,name,nickname,avatar',
            'replyTo.user:id,name,nickname,avatar',
            'reactions:id,message_id,user_id,emoji',
            'wellnessCheck.answers',
        ])->find($check->message_id);

        if ($message) {
            $this->chatService->markRead($user, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $user->id, [], true);
        }

        return $this->success(
            $message ? $this->chatService->presentMessage($message, $user->id) : null,
            'ส่งเช็คอาการแล้ว',
            201,
        );
    }

    /** ของฉัน (+ รายชื่อทั้งหมดสำหรับทีมงาน) */
    public function show(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId);
        if ($error) {
            return $error;
        }

        return $this->payload($request, $schedule, $check);
    }

    public function answer(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1', 'max:200'],
            'passenger_ids.*' => ['integer'],
            'status' => ['required', Rule::in(ChatWellnessCheck::STATUSES)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        try {
            $check = $this->wellness->answer(
                $user,
                $check,
                $validated['passenger_ids'],
                $validated['status'],
                $validated['note'] ?? null,
                $this->chatService->canModerate($user, $schedule),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $schedule, $check, 'ส่งคำตอบแล้ว');
    }

    public function handle(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        $validated = $request->validate(['passenger_id' => ['required', 'integer']]);

        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $check = $this->wellness->markHandled($request->user(), $check, (int) $validated['passenger_id']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $schedule, $check, 'บันทึกแล้ว');
    }

    public function close(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId, staffOnly: true);
        if ($error) {
            return $error;
        }

        return $this->payload($request, $schedule, $this->wellness->close($check), 'ปิดเช็คอาการแล้ว');
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?ChatWellnessCheck, 2: ?JsonResponse}
     */
    private function resolve(Request $request, int $scheduleId, int $checkId, bool $staffOnly = false): array
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return [null, null, $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403)];
        }

        if ($staffOnly && ! $this->chatService->canModerate($user, $schedule)) {
            return [null, null, $this->error('ทำได้เฉพาะทีมงานประจำรอบ', 403)];
        }

        $check = ChatWellnessCheck::where('schedule_id', $scheduleId)->findOrFail($checkId);

        return [$schedule, $check, null];
    }

    private function payload(Request $request, TripSchedule $schedule, ChatWellnessCheck $check, string $message = 'สำเร็จ'): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'message_id' => (int) $check->message_id,
            'wellness' => $this->wellness->present(
                $check,
                viewerId: $user->id,
                withStaffDetail: $this->chatService->canModerate($user, $schedule),
            ),
        ], $message);
    }
}
