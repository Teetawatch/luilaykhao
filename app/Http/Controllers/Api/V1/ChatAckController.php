<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Services\ChatAckService;
use App\Services\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "รับทราบ" ใต้ประกาศ — ทีมงานขอ/ยกเลิก/ดูรายชื่อ/สะกิด ลูกทริปกดรับทราบ
 */
class ChatAckController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chat,
        private ChatAckService $acks,
    ) {}

    /** POST …/messages/{messageId}/ack-request — ทีมงานขอให้ทุกคนกดรับทราบ */
    public function request(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        [$schedule, $message] = $this->resolve($scheduleId, $messageId);

        if (! $this->chat->canModerate($request->user(), $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่ขอให้กดรับทราบได้', 403);
        }

        try {
            $this->acks->request($request->user(), $message);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'message_id' => $message->id,
            'ack' => $this->acks->present($message->fresh(), $request->user()->id),
        ], 'ขอให้ทุกคนกดรับทราบแล้ว');
    }

    /** DELETE …/messages/{messageId}/ack-request */
    public function cancel(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        [$schedule, $message] = $this->resolve($scheduleId, $messageId);

        if (! $this->chat->canModerate($request->user(), $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่ยกเลิกได้', 403);
        }

        $this->acks->cancel($message);

        return $this->success(['message_id' => $message->id, 'ack' => null], 'ยกเลิกการขอรับทราบแล้ว');
    }

    /** POST …/messages/{messageId}/ack — ลูกทริปกดรับทราบ */
    public function ack(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        [$schedule, $message] = $this->resolve($scheduleId, $messageId);
        $user = $request->user();

        if (! $this->chat->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        try {
            $this->acks->ack($user, $message);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'message_id' => $message->id,
            'ack' => $this->acks->present($message->fresh(), $user->id),
        ], 'รับทราบแล้ว');
    }

    /** GET …/messages/{messageId}/acks — ใครกดแล้ว / ใครยังไม่กด (ทีมงาน) */
    public function index(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        [$schedule, $message] = $this->resolve($scheduleId, $messageId);

        if (! $this->chat->canModerate($request->user(), $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่ดูรายชื่อได้', 403);
        }

        return $this->success([
            'message_id' => $message->id,
            'ack' => $this->acks->present($message, $request->user()->id),
            ...$this->acks->detail($message),
        ]);
    }

    /** POST …/messages/{messageId}/acks/nudge — สะกิดคนที่ยังไม่กด */
    public function nudge(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        [$schedule, $message] = $this->resolve($scheduleId, $messageId);

        if (! $this->chat->canModerate($request->user(), $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่สะกิดได้', 403);
        }

        try {
            $count = $this->acks->nudge($message);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'message_id' => $message->id,
            'nudged' => $count,
            'ack' => $this->acks->present($message->fresh(), $request->user()->id),
        ], "สะกิดแล้ว {$count} คน");
    }

    /**
     * @return array{0: TripSchedule, 1: ChatMessage}
     */
    private function resolve(int $scheduleId, int $messageId): array
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $message = ChatMessage::where('schedule_id', $scheduleId)->findOrFail($messageId);

        return [$schedule, $message];
    }
}
