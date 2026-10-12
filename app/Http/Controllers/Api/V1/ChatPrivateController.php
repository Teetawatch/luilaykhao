<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatPrivateMessage;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatPrivateService;
use App\Services\ContentFilterService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ถามทีมงานแบบส่วนตัว — ลูกทริปใช้เธรดของตัวเอง (ไม่ต้องส่ง user_id) ทีมงานระบุ
 * user_id ของลูกทริปที่จะคุยด้วย — ดู ChatPrivateService
 */
class ChatPrivateController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatPrivateService $private,
        private ContentFilterService $filter,
    ) {}

    /** กล่องข้อความของทีมงาน — ทุกเธรดของรอบนี้ */
    public function threads(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        if (! $this->private->canAnswer($request->user(), $schedule)) {
            return $this->error('เฉพาะทีมงานประจำรอบเท่านั้น', 403);
        }

        return $this->success([
            'threads' => $this->private->threads($schedule),
        ]);
    }

    public function messages(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        [$customer, $asStaff, $error] = $this->resolveCustomer($request, $schedule);
        if ($error) {
            return $error;
        }

        $perPage = min(50, max(10, (int) $request->get('per_page', 30)));
        $thread = $this->private->thread($schedule, $customer->id);
        $page = $thread
            ? $this->private->messages(
                $thread,
                $request->user()->id,
                (int) $request->get('before_id', 0),
                (int) $request->get('after_id', 0),
                $perPage,
            )
            : ['messages' => [], 'has_more' => false];

        return $this->success([
            ...$page,
            'thread' => $thread ? $this->private->threadState($thread) : null,
            'customer' => $this->private->person($customer),
            // ลูกทริปเห็นว่าใครจะได้อ่าน — ไม่มีสตาฟประจำรอบ แอปบอกให้ทักศูนย์ช่วยเหลือแทน
            'staff' => $this->private->teamContacts($schedule),
            'viewer_role' => $asStaff ? 'staff' : 'customer',
        ]);
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'body' => ['nullable', 'string', 'max:2000', 'required_without_all:image,audio'],
            'image' => ['nullable', 'image', 'max:5120', 'prohibits:audio'],
            'audio' => ['nullable', 'file', 'mimes:'.implode(',', ChatMessage::AUDIO_EXTENSIONS), 'max:'.ChatMessage::AUDIO_MAX_KB, 'prohibits:body'],
            'audio_duration_ms' => ['nullable', 'required_with:audio', 'integer', 'min:'.ChatMessage::AUDIO_MIN_MS, 'max:'.((ChatMessage::AUDIO_MAX_SECONDS + 5) * 1000)],
        ]);

        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        [$customer, , $error] = $this->resolveCustomer($request, $schedule);
        if ($error) {
            return $error;
        }

        if ($rejected = $this->filter->check($validated['body'] ?? null)) {
            return $this->error($rejected, 422);
        }

        $message = $this->private->send(
            $request->user(),
            $schedule,
            $customer,
            $validated['body'] ?? null,
            $request->file('image'),
            $request->file('audio'),
            isset($validated['audio_duration_ms']) ? (int) $validated['audio_duration_ms'] : null,
        );

        return $this->success([
            'message' => $this->private->present($message, $request->user()->id),
            'thread' => $this->private->threadState($message->thread),
        ], 'ส่งแล้ว ทีมงานประจำรอบเท่านั้นที่เห็น', 201);
    }

    public function read(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'message_id' => ['nullable', 'integer'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        [$customer, $asStaff, $error] = $this->resolveCustomer($request, $schedule);
        if ($error) {
            return $error;
        }

        $thread = $this->private->thread($schedule, $customer->id);
        if (! $thread) {
            return $this->success(['thread' => null]);
        }

        $thread = $this->private->markRead($thread, $asStaff, isset($validated['message_id']) ? (int) $validated['message_id'] : null);

        return $this->success(['thread' => $this->private->threadState($thread)]);
    }

    /** ลบข้อความของตัวเอง (ทีมงานลบของทีมงานคนอื่นในเธรดเดียวกันไม่ได้ — ต่างคนต่างรับผิดชอบคำพูดตัวเอง) */
    public function destroy(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        $message = ChatPrivateMessage::with('thread')->findOrFail($messageId);
        if ((int) $message->thread->schedule_id !== $schedule->id) {
            abort(404);
        }

        $allowed = $this->private->canAnswer($user, $schedule)
            || ((int) $message->thread->user_id === $user->id && $this->private->canAsk($user, $schedule));

        if (! $allowed || (int) $message->user_id !== $user->id) {
            return $this->error('ลบได้เฉพาะข้อความของคุณเอง', 403);
        }

        if (! $message->is_deleted) {
            $this->private->delete($message);
        }

        return $this->success(['message' => $this->private->present($message->fresh(['thread', 'user']), $user->id)], 'ลบข้อความแล้ว');
    }

    /**
     * ใครคือลูกทริปเจ้าของเธรดที่กำลังคุยกัน — ลูกทริปได้เธรดตัวเองเสมอ ทีมงานต้องระบุ
     * user_id และคนนั้นต้องเป็นลูกทริปในห้องนี้จริง
     *
     * @return array{0: ?User, 1: bool, 2: ?JsonResponse}
     */
    private function resolveCustomer(Request $request, TripSchedule $schedule): array
    {
        $user = $request->user();

        if ($this->private->canAnswer($user, $schedule)) {
            $customer = User::find((int) $request->input('user_id', $request->query('user_id')));

            if (! $customer || ! $this->private->canAsk($customer, $schedule)) {
                return [null, true, $this->error('ไม่พบลูกทริปคนนี้ในรอบนี้', 422)];
            }

            return [$customer, true, null];
        }

        if (! $this->private->canAsk($user, $schedule)) {
            return [null, false, $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403)];
        }

        $target = (int) $request->input('user_id', $request->query('user_id', $user->id));
        if ($target !== $user->id) {
            return [null, false, $this->error('ดูได้เฉพาะข้อความของคุณเอง', 403)];
        }

        return [$user, false, null];
    }
}
