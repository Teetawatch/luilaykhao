<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatRestStop;
use App\Models\ChatStopRequest;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatRestStopService;
use App\Services\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * จุดพักระหว่างทาง (นัดเวลากลับรถ + เช็คชื่อขึ้นรถ) และคำขอแวะห้องน้ำแบบไม่บอกชื่อ
 * — ดู ChatRestStopService
 */
class ChatRestStopController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatRestStopService $restStops,
    ) {}

    /** สตาฟประกาศพัก — การ์ดนับถอยหลังลงห้อง และเด้งแจ้งทุกคน */
    public function store(Request $request, int $scheduleId): JsonResponse
    {
        // minutes = พักรถตอนนี้, meet_at = นัดรวมพลล่วงหน้า (ISO-8601 มีโซนเวลา)
        $validated = $request->validate([
            'minutes' => ['required_without:meet_at', 'nullable', 'integer', 'min:'.ChatRestStop::MIN_MINUTES, 'max:'.ChatRestStop::MAX_MINUTES],
            'meet_at' => ['required_without:minutes', 'nullable', 'date'],
            'place' => ['nullable', 'string', 'max:120'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('นัดเวลาพักได้เฉพาะทีมงานประจำรอบ', 403);
        }

        $place = isset($validated['place']) ? trim($validated['place']) : null;

        try {
            $stop = ! empty($validated['meet_at'])
                ? $this->restStops->openMeetup($user, $schedule, Carbon::parse($validated['meet_at'])->utc(), $place)
                : $this->restStops->open($user, $schedule, (int) $validated['minutes'], $place);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = ChatMessage::with([
            'user:id,name,nickname,avatar',
            'replyTo.user:id,name,nickname,avatar',
            'reactions:id,message_id,user_id,emoji',
            'restStop.boardings',
        ])->find($stop->message_id);

        if ($message) {
            $this->chatService->markRead($user, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $user->id, [], true);
        }

        return $this->success(
            $message ? $this->chatService->presentMessage($message, $user->id) : null,
            $stop->isMeetup() ? 'ประกาศนัดรวมพลแล้ว' : 'ประกาศเวลาพักแล้ว',
            201,
        );
    }

    /** การ์ดล่าสุด — ทีมงานได้เบอร์โทรของแต่ละคนไว้โทรตามด้วย */
    public function show(Request $request, int $scheduleId, int $stopId): JsonResponse
    {
        [$schedule, $stop, $error] = $this->resolve($request, $scheduleId, $stopId);
        if ($error) {
            return $error;
        }

        return $this->payload($stop, $this->chatService->canModerate($request->user(), $schedule));
    }

    public function board(Request $request, int $scheduleId, int $stopId): JsonResponse
    {
        $validated = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1', 'max:100'],
            'passenger_ids.*' => ['integer'],
            'boarded' => ['required', 'boolean'],
        ]);

        [$schedule, $stop, $error] = $this->resolve($request, $scheduleId, $stopId);
        if ($error) {
            return $error;
        }

        $user = $request->user();
        $asStaff = $this->chatService->canModerate($user, $schedule);

        try {
            $stop = $this->restStops->setBoarded($user, $stop, $validated['passenger_ids'], (bool) $validated['boarded'], $asStaff);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($stop, $asStaff, $validated['boarded'] ? 'ขึ้นรถแล้ว' : 'ยกเลิกแล้ว');
    }

    public function extend(Request $request, int $scheduleId, int $stopId): JsonResponse
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        [$schedule, $stop, $error] = $this->resolve($request, $scheduleId, $stopId, staffOnly: true);
        if ($error) {
            return $error;
        }

        try {
            $stop = $this->restStops->extend($stop, (int) $validated['minutes']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->payload($stop, true, 'ขยายเวลาแล้ว');
    }

    public function depart(Request $request, int $scheduleId, int $stopId): JsonResponse
    {
        [$schedule, $stop, $error] = $this->resolve($request, $scheduleId, $stopId, staffOnly: true);
        if ($error) {
            return $error;
        }

        return $this->payload($this->restStops->depart($stop), true, 'ออกรถแล้ว');
    }

    /**
     * คำขอแบบไม่บอกชื่อ (ห้องน้ำ / แอร์ / ความเร็ว / เพลง) — ทีมงานเห็นแค่จำนวน
     * เป็นปุ่มของลูกทริปเท่านั้น ทีมงานไม่ต้องขอทีมงาน
     */
    public function requestStop(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'urgent' => ['nullable', 'boolean'],
            'kind' => ['nullable', Rule::in(array_keys(ChatStopRequest::KINDS))],
        ]);
        $kind = $validated['kind'] ?? ChatStopRequest::KIND_TOILET;

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        if ($this->chatService->canModerate($user, $schedule)) {
            return $this->error('ปุ่มนี้สำหรับลูกทริปครับ ทีมงานเห็นคำขอของทุกคนที่แถบด้านบนแชท', 403);
        }

        if (! $this->restStops->isWithinTripWindow($schedule)) {
            return $this->error('ส่งคำขอนี้ได้ระหว่างเดินทางเท่านั้นครับ', 422);
        }

        $summary = $this->restStops->requestStop($user, $schedule, (bool) ($validated['urgent'] ?? false), $kind);

        return $this->success($this->mine($summary, $user, $schedule), 'ส่งแล้ว ทีมงานไม่เห็นชื่อคุณ');
    }

    public function cancelRequest(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(ChatStopRequest::KINDS))],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $summary = $this->restStops->cancelRequest($user, $schedule, $validated['kind'] ?? ChatStopRequest::KIND_TOILET);

        return $this->success($this->mine($summary, $user, $schedule), 'ถอนคำขอแล้ว');
    }

    public function acknowledge(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            // จะแวะในอีกกี่นาที (0 = แวะเลย, ไม่ส่ง = เร็ว ๆ นี้) — ใช้กับห้องน้ำเท่านั้น
            'minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'kind' => ['nullable', Rule::in(array_keys(ChatStopRequest::KINDS))],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('ทำได้เฉพาะทีมงานประจำรอบ', 403);
        }

        $summary = $this->restStops->acknowledge(
            $schedule,
            isset($validated['minutes']) ? (int) $validated['minutes'] : null,
            $validated['kind'] ?? ChatStopRequest::KIND_TOILET,
        );

        return $this->success([...$summary, 'mine' => false, 'mine_kinds' => []], 'แจ้งทุกคนแล้ว');
    }

    /**
     * สรุปจำนวน + มุมมองของฉัน (mine = ห้องน้ำ สำหรับแอปรุ่นก่อน, mine_kinds = ทุกเรื่อง)
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function mine(array $summary, User $user, TripSchedule $schedule): array
    {
        $kinds = $this->restStops->myOpenKinds($user, $schedule);

        return [
            ...$summary,
            'mine' => in_array(ChatStopRequest::KIND_TOILET, $kinds, true),
            'mine_kinds' => $kinds,
        ];
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?ChatRestStop, 2: ?JsonResponse}
     */
    private function resolve(Request $request, int $scheduleId, int $stopId, bool $staffOnly = false): array
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return [null, null, $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403)];
        }

        if ($staffOnly && ! $this->chatService->canModerate($user, $schedule)) {
            return [null, null, $this->error('ทำได้เฉพาะทีมงานประจำรอบ', 403)];
        }

        $stop = ChatRestStop::where('schedule_id', $scheduleId)->findOrFail($stopId);

        return [$schedule, $stop, null];
    }

    private function payload(ChatRestStop $stop, bool $withPhones, string $message = 'ok'): JsonResponse
    {
        return $this->success([
            'message_id' => (int) $stop->message_id,
            'rest_stop' => $this->restStops->present($stop, $withPhones),
        ], $message);
    }
}
