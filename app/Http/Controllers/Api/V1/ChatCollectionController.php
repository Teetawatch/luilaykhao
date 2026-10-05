<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatCollection;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Services\ChatCollectionService;
use App\Services\ChatService;
use App\Services\TripRosterService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * เก็บเงินหน้างานในห้องแชททริป — ดู ChatCollectionService
 */
class ChatCollectionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatCollectionService $collections,
        private TripRosterService $roster,
    ) {}

    /** รายชื่อผู้เดินทางของรอบ (ไว้เลือกว่าเก็บใคร) — เฉพาะทีมงาน */
    public function roster(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        if (! $this->chatService->canModerate($request->user(), $schedule)) {
            return $this->error('ทำได้เฉพาะทีมงานประจำรอบ', 403);
        }

        return $this->success([
            'passengers' => $this->roster->passengers($schedule)->map(fn ($p) => [
                'passenger_id' => $p['passenger_id'],
                'name' => $p['name'],
                'full_name' => $p['full_name'],
                'booking_id' => $p['booking_id'],
                'is_join_trip' => $p['is_join_trip'],
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.ChatCollection::MAX_AMOUNT],
            // ไม่ส่ง = เก็บทุกคนในรอบ
            'passenger_ids' => ['nullable', 'array', 'max:200'],
            'passenger_ids.*' => ['integer'],
            'promptpay_id' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\s]+$/'],
            'payee_name' => ['nullable', 'string', 'max:80'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เก็บเงินได้เฉพาะทีมงานประจำรอบ', 403);
        }

        $digits = $this->promptPayDigits($validated['promptpay_id'] ?? null);
        if ($digits === false) {
            return $this->error('พร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลข 13 หลัก', 422);
        }

        try {
            $collection = $this->collections->open(
                $user,
                $schedule,
                trim($validated['title']),
                isset($validated['note']) ? trim($validated['note']) : null,
                round((float) $validated['amount'], 2),
                array_key_exists('passenger_ids', $validated) && $validated['passenger_ids'] !== null
                    ? $validated['passenger_ids']
                    : null,
                $digits,
                isset($validated['payee_name']) ? trim($validated['payee_name']) : null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = ChatMessage::with([
            'user:id,name,nickname,avatar',
            'replyTo.user:id,name,nickname,avatar',
            'reactions:id,message_id,user_id,emoji',
            'collection.dues',
        ])->find($collection->message_id);

        if ($message) {
            $this->chatService->markRead($user, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $user->id, [], true);
        }

        return $this->success(
            $message ? $this->chatService->presentMessage($message, $user->id) : null,
            'เริ่มเก็บเงินแล้ว',
            201,
        );
    }

    public function setPayers(Request $request, int $scheduleId, int $collectionId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1', 'max:200'],
            'passenger_ids.*' => ['integer'],
        ]);

        [$schedule, $collection, $error] = $this->resolve($request, $scheduleId, $collectionId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $collection = $this->collections->setPayers($collection, $validated['passenger_ids']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($collection, 'แก้รายชื่อแล้ว');
    }

    public function claim(Request $request, int $scheduleId, int $collectionId): JsonResponse
    {
        $validated = $request->validate(['claimed' => ['nullable', 'boolean']]);

        [$schedule, $collection, $error] = $this->resolve($request, $scheduleId, $collectionId);
        if ($error) {
            return $error;
        }

        try {
            $collection = $this->collections->claim($request->user(), $collection, (bool) ($validated['claimed'] ?? true));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($collection, 'แจ้งทีมงานแล้ว');
    }

    public function setPaid(Request $request, int $scheduleId, int $collectionId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1', 'max:200'],
            'passenger_ids.*' => ['integer'],
            'paid' => ['required', 'boolean'],
        ]);

        [$schedule, $collection, $error] = $this->resolve($request, $scheduleId, $collectionId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $collection = $this->collections->setPaid($request->user(), $collection, $validated['passenger_ids'], (bool) $validated['paid']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($collection, $validated['paid'] ? 'บันทึกว่าจ่ายแล้ว' : 'ยกเลิกแล้ว');
    }

    public function close(Request $request, int $scheduleId, int $collectionId): JsonResponse
    {
        [$schedule, $collection, $error] = $this->resolve($request, $scheduleId, $collectionId, staffOnly: true);
        if ($error) {
            return $error;
        }

        return $this->payload($this->collections->close($collection), 'ปิดยอดแล้ว');
    }

    public function reopen(Request $request, int $scheduleId, int $collectionId): JsonResponse
    {
        [$schedule, $collection, $error] = $this->resolve($request, $scheduleId, $collectionId, staffOnly: true);
        if ($error) {
            return $error;
        }

        return $this->payload($this->collections->reopen($collection), 'เปิดเก็บต่อแล้ว');
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?ChatCollection, 2: ?JsonResponse}
     */
    private function resolve(Request $request, int $scheduleId, int $collectionId, bool $staffOnly = false): array
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return [null, null, $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403)];
        }

        if ($staffOnly && ! $this->chatService->canModerate($user, $schedule)) {
            return [null, null, $this->error('ทำได้เฉพาะทีมงานประจำรอบ', 403)];
        }

        $collection = ChatCollection::where('schedule_id', $scheduleId)->findOrFail($collectionId);

        return [$schedule, $collection, null];
    }

    /** null = ไม่ได้ใส่, false = รูปแบบผิด */
    private function promptPayDigits(?string $raw): string|false|null
    {
        $digits = preg_replace('/\D/', '', (string) $raw);
        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 13 || (strlen($digits) === 10 && str_starts_with($digits, '0'))
            ? $digits
            : false;
    }

    private function payload(ChatCollection $collection, string $message): JsonResponse
    {
        return $this->success([
            'message_id' => (int) $collection->message_id,
            'collection' => $this->collections->present($collection),
        ], $message);
    }
}
