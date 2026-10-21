<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatTrailCheck;
use App\Models\TripSchedule;
use App\Services\ChatService;
use App\Services\ChatTrailService;
use App\Services\TripRosterService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * เช็คพอยต์บนเส้นทางเดินในห้องแชททริป — ดู ChatTrailService
 */
class ChatTrailController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatTrailService $trails,
        private TripRosterService $roster,
    ) {}

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'checkpoints' => ['required', 'array', 'min:'.ChatTrailCheck::MIN_CHECKPOINTS, 'max:'.ChatTrailCheck::MAX_CHECKPOINTS],
            'checkpoints.*' => ['required', 'string', 'max:40'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('ตั้งเช็คพอยต์ได้เฉพาะทีมงานประจำรอบ', 403);
        }

        try {
            $check = $this->trails->open(
                $user,
                $schedule,
                isset($validated['title']) ? trim($validated['title']) : null,
                $validated['checkpoints'],
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = ChatMessage::with([
            'user:id,name,nickname,avatar',
            'replyTo.user:id,name,nickname,avatar',
            'reactions:id,message_id,user_id,emoji',
            'trailCheck.positions',
        ])->find($check->message_id);

        if ($message) {
            $this->chatService->markRead($user, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $user->id, [], true);
        }

        return $this->success(
            $message ? $this->chatService->presentMessage($message, $user->id) : null,
            'ตั้งเช็คพอยต์แล้ว',
            201,
        );
    }

    /** การ์ดล่าสุด — ทีมงานได้รายชื่อคนที่ขอพักและเบอร์โทรด้วย */
    public function show(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId);
        if ($error) {
            return $error;
        }

        return $this->payload($request, $schedule, $check);
    }

    public function reach(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1', 'max:200'],
            'passenger_ids.*' => ['integer'],
            // null = ยังไม่ถึงจุดแรก (แก้กดผิด)
            'index' => ['present', 'nullable', 'integer', 'min:0', 'max:'.(ChatTrailCheck::MAX_CHECKPOINTS - 1)],
        ]);

        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        try {
            $check = $this->trails->reach(
                $user,
                $check,
                $validated['passenger_ids'],
                $validated['index'] === null ? null : (int) $validated['index'],
                $this->chatService->canModerate($user, $schedule),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $schedule, $check, 'บันทึกแล้ว');
    }

    /** "ตามไม่ทัน ขอพัก" — ไม่ส่ง passenger_ids = ทุกคนที่ตัวเองดูแล */
    public function help(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['nullable', 'array', 'max:50'],
            'passenger_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId);
        if ($error) {
            return $error;
        }

        $user = $request->user();
        $ids = $validated['passenger_ids'] ?? $this->roster->travelling($schedule)
            ->where('user_id', $user->id)
            ->pluck('passenger_id')
            ->all();

        try {
            $check = $this->trails->requestHelp($user, $check, $ids, $validated['note'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $schedule, $check, 'แจ้งทีมงานแล้ว');
    }

    public function cancelHelp(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId);
        if ($error) {
            return $error;
        }

        return $this->payload($request, $schedule, $this->trails->cancelHelp($request->user(), $check), 'ถอนคำขอแล้ว');
    }

    public function acknowledgeHelp(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1', 'max:200'],
            'passenger_ids.*' => ['integer'],
        ]);

        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId, staffOnly: true);
        if ($error) {
            return $error;
        }

        $check = $this->trails->acknowledgeHelp($request->user(), $check, $validated['passenger_ids']);

        return $this->payload($request, $schedule, $check, 'รับทราบแล้ว');
    }

    public function close(Request $request, int $scheduleId, int $checkId): JsonResponse
    {
        [$schedule, $check, $error] = $this->resolve($request, $scheduleId, $checkId, staffOnly: true);
        if ($error) {
            return $error;
        }

        return $this->payload($request, $schedule, $this->trails->close($check), 'ปิดเช็คพอยต์แล้ว');
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?ChatTrailCheck, 2: ?JsonResponse}
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

        $check = ChatTrailCheck::where('schedule_id', $scheduleId)->findOrFail($checkId);

        return [$schedule, $check, null];
    }

    private function payload(Request $request, TripSchedule $schedule, ChatTrailCheck $check, string $message = 'สำเร็จ'): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'message_id' => (int) $check->message_id,
            'trail_check' => $this->trails->present(
                $check,
                withStaffDetail: $this->chatService->canModerate($user, $schedule),
                viewerId: $user->id,
            ),
        ], $message);
    }
}
