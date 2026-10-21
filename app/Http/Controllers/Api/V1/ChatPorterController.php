<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatPorterRound;
use App\Models\TripSchedule;
use App\Services\ChatPorterService;
use App\Services\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ฝากของลูกหาบในห้องแชททริป — ดู ChatPorterService
 */
class ChatPorterController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatPorterService $porters,
    ) {}

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'rate_per_kg' => ['required', 'numeric', 'min:1', 'max:'.ChatPorterRound::MAX_RATE],
            'min_charge' => ['nullable', 'numeric', 'min:0', 'max:'.ChatPorterRound::MAX_RATE],
            'round_up' => ['nullable', 'boolean'],
            'promptpay_id' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\s]+$/'],
            'payee_name' => ['nullable', 'string', 'max:80'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เปิดรับฝากของได้เฉพาะทีมงานประจำรอบ', 403);
        }

        $digits = preg_replace('/\D/', '', (string) ($validated['promptpay_id'] ?? ''));
        if ($digits !== '' && ! (strlen($digits) === 13 || (strlen($digits) === 10 && str_starts_with($digits, '0')))) {
            return $this->error('พร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลข 13 หลัก', 422);
        }

        try {
            $round = $this->porters->open(
                $user,
                $schedule,
                trim((string) ($validated['title'] ?? '')) ?: 'ฝากของลูกหาบ',
                round((float) $validated['rate_per_kg'], 2),
                round((float) ($validated['min_charge'] ?? 0), 2),
                (bool) ($validated['round_up'] ?? true),
                $digits !== '' ? $digits : null,
                isset($validated['payee_name']) ? trim($validated['payee_name']) : null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = ChatMessage::with([
            'user:id,name,nickname,avatar',
            'replyTo.user:id,name,nickname,avatar',
            'reactions:id,message_id,user_id,emoji',
            'porterRound.bags',
        ])->find($round->message_id);

        if ($message) {
            $this->chatService->markRead($user, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $user->id, [], true);
        }

        return $this->success(
            $message ? $this->chatService->presentMessage($message, $user->id) : null,
            'เปิดรับฝากของแล้ว',
            201,
        );
    }

    public function show(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        [, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        return $this->payload($request, $round);
    }

    public function addBags(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_id' => ['required', 'integer'],
            'count' => ['nullable', 'integer', 'min:1', 'max:'.ChatPorterRound::MAX_BAGS_PER_PASSENGER],
            'note' => ['nullable', 'string', 'max:80'],
            'weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:'.ChatPorterRound::MAX_WEIGHT_KG],
        ]);

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        try {
            $round = $this->porters->addBags(
                $user,
                $round,
                (int) $validated['passenger_id'],
                (int) ($validated['count'] ?? 1),
                $validated['note'] ?? null,
                isset($validated['weight_kg']) ? round((float) $validated['weight_kg'], 2) : null,
                $this->chatService->canModerate($user, $schedule),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $round, 'ลงชื่อฝากแล้ว');
    }

    public function weigh(Request $request, int $scheduleId, int $roundId, int $bagId): JsonResponse
    {
        $validated = $request->validate([
            'weight_kg' => ['present', 'nullable', 'numeric', 'gt:0', 'max:'.ChatPorterRound::MAX_WEIGHT_KG],
        ]);

        [, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $round = $this->porters->weigh(
                $request->user(),
                $round,
                $bagId,
                $validated['weight_kg'] === null ? null : round((float) $validated['weight_kg'], 2),
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $round, 'บันทึกน้ำหนักแล้ว');
    }

    public function removeBag(Request $request, int $scheduleId, int $roundId, int $bagId): JsonResponse
    {
        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        try {
            $round = $this->porters->removeBag($user, $round, $bagId, $this->chatService->canModerate($user, $schedule));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $round, 'เอาออกแล้ว');
    }

    public function returned(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate([
            'bag_ids' => ['required', 'array', 'min:1', 'max:200'],
            'bag_ids.*' => ['integer'],
            'returned' => ['required', 'boolean'],
        ]);

        [$schedule, $round, $error] = $this->resolve($request, $scheduleId, $roundId);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        try {
            $round = $this->porters->setReturned($user, $round, $validated['bag_ids'], (bool) $validated['returned'], $this->chatService->canModerate($user, $schedule));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $round, $validated['returned'] ? 'ได้กระเป๋าคืนแล้ว' : 'ยกเลิกแล้ว');
    }

    public function bill(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        [, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $round = $this->porters->bill($request->user(), $round);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($request, $round, 'ส่งยอดเก็บเงินแล้ว');
    }

    public function close(Request $request, int $scheduleId, int $roundId): JsonResponse
    {
        $validated = $request->validate(['closed' => ['nullable', 'boolean']]);

        [, $round, $error] = $this->resolve($request, $scheduleId, $roundId, staffOnly: true);
        if ($error) {
            return $error;
        }

        $closed = (bool) ($validated['closed'] ?? true);

        return $this->payload($request, $this->porters->setClosed($round, $closed), $closed ? 'ปิดรับฝากแล้ว' : 'เปิดรับฝากต่อแล้ว');
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?ChatPorterRound, 2: ?JsonResponse}
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

        $round = ChatPorterRound::where('schedule_id', $scheduleId)->findOrFail($roundId);

        return [$schedule, $round, null];
    }

    private function payload(Request $request, ChatPorterRound $round, string $message = 'สำเร็จ'): JsonResponse
    {
        return $this->success([
            'message_id' => (int) $round->message_id,
            'porter' => $this->porters->present($round, viewerId: $request->user()->id),
        ], $message);
    }
}
