<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ChatJoined;
use App\Events\ChatMessageSent;
use App\Events\ChatPinned;
use App\Events\ChatReactionUpdated;
use App\Events\ChatReadUpdated;
use App\Events\ChatTyping;
use App\Http\Controllers\Controller;
use App\Jobs\PostChatAutoAnswerJob;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\ChatRead;
use App\Models\TripSchedule;
use App\Services\ChatAutoAnswerService;
use App\Services\ChatPollService;
use App\Services\ChatService;
use App\Services\ContentFilterService;
use App\Services\ModerationService;
use App\Services\ScheduleItineraryService;
use App\Services\TripFactsService;
use App\Services\WeatherService;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatPollService $pollService,
        private TripFactsService $tripFacts,
        private WeatherService $weatherService,
        private ContentFilterService $filter,
        private ModerationService $moderation,
        private ScheduleItineraryService $itineraryService,
        private ChatAutoAnswerService $autoAnswer,
    ) {}

    public function index(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $perPage = min(50, max(10, (int) $request->get('per_page', 30)));
        $beforeId = (int) $request->get('before_id', 0);
        $afterId = (int) $request->get('after_id', 0);

        // First time anyone opens the room, seed a welcome system message so the
        // conversation never starts on an empty screen.
        if ($beforeId === 0 && $afterId === 0) {
            $this->chatService->ensureWelcome($schedule);
        }

        // ข้อความของคนที่บล็อกกันไว้ และข้อความที่ถูกซ่อนจากการรายงาน
        // ต้องไม่หลุดออกไปทุกเส้นทางที่อ่านห้องนี้
        $visible = fn ($query) => $this->chatService->scopeVisibleTo($query, $user);

        // Polling for live updates: return messages newer than $afterId, oldest-first.
        if ($afterId > 0) {
            $messages = ChatMessage::where('schedule_id', $scheduleId)
                ->with($this->messageRelations())
                ->tap($visible)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($perPage)
                ->get();

            return $this->success([
                'messages' => $messages->map(fn ($m) => $this->chatService->presentMessage($m, $user->id))->all(),
                'has_more' => false,
            ]);
        }

        $messages = ChatMessage::where('schedule_id', $scheduleId)
            ->with($this->messageRelations())
            ->tap($visible)
            ->when($beforeId > 0, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($perPage)
            ->get()
            ->reverse()
            ->values();

        return $this->success([
            'messages' => $messages->map(fn ($m) => $this->chatService->presentMessage($m, $user->id))->all(),
            'has_more' => $messages->count() === $perPage,
        ]);
    }

    /** Relations needed to present a message (author, quoted message, reactions). */
    private function messageRelations(): array
    {
        return [
            'user:id,name,nickname,avatar',
            'replyTo.user:id,name,nickname,avatar',
            'reactions:id,message_id,user_id,emoji',
            'poll.options',
            'poll.votes',
        ];
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:2000', 'required_without:image'],
            'image' => ['nullable', 'image', 'max:5120'],
            // Reply must point at a message in this same room.
            'reply_to_id' => [
                'nullable', 'integer',
                Rule::exists('chat_messages', 'id')->where('schedule_id', $scheduleId),
            ],
            // user_ids tagged with @mention in the body.
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ส่งข้อความในห้องนี้', 403);
        }

        if ($rejected = $this->filter->check($validated['body'] ?? null)) {
            return $this->error($rejected, 422);
        }

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('chat/'.date('Y/m'), MediaDisk::name())
            : null;

        $body = isset($validated['body']) ? trim($validated['body']) : null;
        $mentions = $this->chatService->sanitizeMentions($schedule, $validated['mentions'] ?? []);

        $message = ChatMessage::create([
            'schedule_id' => $scheduleId,
            'user_id' => $user->id,
            'reply_to_id' => $validated['reply_to_id'] ?? null,
            'sender_role' => $this->chatService->senderRole($user, $schedule),
            'body' => $body !== '' ? $body : null,
            'image_path' => $imagePath,
            'mentions' => $mentions ?: null,
        ]);
        $message->load($this->messageRelations());

        broadcast(new ChatMessageSent($message))->toOthers();

        // ผู้ส่งถือว่าอ่านถึงข้อความล่าสุดแล้ว
        $this->chatService->markRead($user, $schedule, $message->id);

        SendChatPushJob::dispatch($message->id, $user->id, $mentions);
        $this->queueAutoAnswer($message);

        return $this->success($this->chatService->presentMessage($message, $user->id), 'ส่งข้อความสำเร็จ', 201);
    }

    /**
     * ลูกค้าถามคำถามที่ห้องตอบเองได้ไหม — ถ้าใช่ ตั้งงานตอบไว้แบบหน่วงเวลา
     *
     * คัดหยาบ ๆ ตรงนี้ก่อนเพื่อไม่ให้ทุกข้อความในห้องกลายเป็นงานในคิว ส่วนกฎ
     * ที่เหลือ (ทีมงานตอบก่อนไหม / ตอบไปแล้ววันนี้หรือยัง) อยู่ในงานนั้น
     * เพราะต้องตัดสินตอนถึงเวลาโพสต์จริง ไม่ใช่ตอนรับข้อความ
     */
    private function queueAutoAnswer(ChatMessage $message): void
    {
        if (! ChatAutoAnswerService::enabled() || $message->sender_role !== 'customer') {
            return;
        }

        if ($this->autoAnswer->topicFor($message->body) === null) {
            return;
        }

        PostChatAutoAnswerJob::dispatch($message->id)
            ->delay(now()->addMinutes(ChatAutoAnswerService::QUIET_MINUTES));
    }

    /**
     * แก้ไขข้อความของตัวเอง (เฉพาะข้อความตัวอักษร ไม่ใช่รูป/ระบบ และยังไม่ถูกลบ)
     */
    public function update(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        $message = ChatMessage::where('schedule_id', $scheduleId)->findOrFail($messageId);

        if ($message->user_id !== $user->id) {
            return $this->error('แก้ไขได้เฉพาะข้อความของคุณเอง', 403);
        }
        if ($message->is_deleted || $message->sender_role === 'system' || $message->image_path) {
            return $this->error('ข้อความนี้แก้ไขไม่ได้', 422);
        }

        // ตัวกรองต้องดักตอนแก้ไขด้วย ไม่งั้นส่งข้อความสุภาพแล้วค่อยแก้เป็นคำหยาบก็รอด
        if ($rejected = $this->filter->check($validated['body'])) {
            return $this->error($rejected, 422);
        }

        $message->mentions = $this->chatService->sanitizeMentions($schedule, $validated['mentions'] ?? []) ?: null;
        $message->save();

        $this->chatService->editMessage($message, trim($validated['body']));

        return $this->success($this->chatService->presentMessage($message, $user->id), 'แก้ไขข้อความแล้ว');
    }

    /**
     * ลบข้อความ — เจ้าของลบของตัวเองได้ สตาฟ/แอดมินลบของใครก็ได้ในห้องที่ดูแล
     */
    public function destroy(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        $message = ChatMessage::where('schedule_id', $scheduleId)->findOrFail($messageId);

        $isOwner = $message->user_id === $user->id;
        if (! $isOwner && ! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ลบข้อความนี้', 403);
        }
        if ($message->is_deleted) {
            return $this->success($this->chatService->presentMessage($message, $user->id), 'ลบแล้ว');
        }

        $this->chatService->deleteMessage($message);

        return $this->success($this->chatService->presentMessage($message, $user->id), 'ลบข้อความแล้ว');
    }

    public function markRead(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $latestId = (int) ($request->input('message_id')
            ?: ChatMessage::where('schedule_id', $scheduleId)->max('id'));

        $this->chatService->markRead($user, $schedule, $latestId);

        // Live "อ่านแล้ว N" — let others move this member's read marker without
        // waiting for their next poll.
        if ($latestId > 0) {
            broadcast(new ChatReadUpdated($scheduleId, $user->id, $latestId))->toOthers();
        }

        return $this->success(['unread' => 0], 'อ่านแล้ว');
    }

    public function pin(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เฉพาะสตาฟหรือทีมงานเท่านั้นที่ปักหมุดได้', 403);
        }

        $message = ChatMessage::where('schedule_id', $scheduleId)->findOrFail($messageId);
        $this->chatService->pinMessage($user, $message);

        $payload = $this->chatService->pinnedPayload(
            $this->chatService->pinnedMessage($schedule)
        );
        broadcast(new ChatPinned($scheduleId, $payload))->toOthers();

        return $this->success(['pinned_message' => $payload], 'ปักหมุดข้อความแล้ว');
    }

    public function unpin(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เฉพาะสตาฟหรือทีมงานเท่านั้นที่ปลดหมุดได้', 403);
        }

        $message = ChatMessage::where('schedule_id', $scheduleId)->findOrFail($messageId);
        $this->chatService->unpinMessage($message);

        broadcast(new ChatPinned($scheduleId, null))->toOthers();

        return $this->success(['pinned_message' => null], 'ปลดหมุดข้อความแล้ว');
    }

    public function react(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in(ChatService::REACTION_EMOJIS)],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $message = ChatMessage::where('schedule_id', $scheduleId)->findOrFail($messageId);
        $reactions = $this->chatService->toggleReaction($user, $message, $validated['emoji']);

        broadcast(new ChatReactionUpdated($scheduleId, $message->id, $reactions))->toOthers();

        return $this->success([
            'message_id' => $message->id,
            'reactions' => $reactions,
        ], 'อัปเดตรีแอกชันแล้ว');
    }

    /**
     * "ข้อมูลการเดินทางของฉัน" — คำตอบของคำถามที่ถูกถามซ้ำที่สุดในห้อง
     * (ขึ้นรถกี่โมง / รอที่ไหน / ทะเบียนรถ / เบอร์คนขับ-สตาฟ)
     *
     * ตอบเป็นรายบุคคล เพราะจุดรับของแต่ละคนในรอบเดียวกันไม่เหมือนกัน
     */
    public function tripInfo(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        return $this->success($this->tripFacts->forUser($user, $schedule));
    }

    /**
     * สตาฟ/แอดมินกดโพสต์สรุปการเดินทางเข้าห้องด้วยปุ่มเดียว — ตอบทุกคนพร้อมกัน
     * แทนการพิมพ์ตอบทีละคนทุกทริป
     */
    public function postTripSummary(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เฉพาะสตาฟหรือทีมงานเท่านั้นที่ส่งสรุปการเดินทางได้', 403);
        }

        $message = $this->chatService->postSystem(
            $schedule,
            $this->tripFacts->roundSummaryText($schedule),
        );

        $message->load($this->messageRelations());

        return $this->success(
            $this->chatService->presentMessage($message, $user->id),
            'ส่งสรุปการเดินทางเข้าห้องแล้ว',
            201,
        );
    }

    /**
     * สตาฟ/แอดมินโพสต์กำหนดการของรอบเข้าห้อง — คำถามที่ถูกถามซ้ำที่สุดรองจาก
     * จุดรับ ตอบทั้งห้องรวดเดียวแทนการพิมพ์ไล่ตอบทีละคน
     *
     * แยกจากสรุปการเดินทางเพราะทั้งสองข้อความยาวคนละเรื่อง รวมกันแล้วอ่านไม่ไหว
     */
    public function postTripItinerary(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('เฉพาะสตาฟหรือทีมงานเท่านั้นที่ส่งกำหนดการได้', 403);
        }

        $body = $this->tripFacts->itinerarySummaryText($schedule);

        if ($body === null) {
            return $this->error('รอบนี้ยังไม่มีกำหนดการให้ส่ง', 422);
        }

        $message = $this->chatService->postSystem($schedule, $body);

        $message->load($this->messageRelations());

        return $this->success(
            $this->chatService->presentMessage($message, $user->id),
            'ส่งกำหนดการเข้าห้องแล้ว',
            201,
        );
    }

    /**
     * สร้างโพลในห้อง — ใครก็ได้ที่อยู่ในห้อง (ทริปกลุ่มต้องตัดสินใจร่วมกัน)
     */
    public function createPoll(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:200'],
            'options' => ['required', 'array', 'min:'.ChatPoll::MIN_OPTIONS, 'max:'.ChatPoll::MAX_OPTIONS],
            'options.*' => ['required', 'string', 'max:100'],
            'allow_multiple' => ['nullable', 'boolean'],
            // ปิดโหวตอัตโนมัติหลังผ่านไปกี่ชั่วโมง (ไม่ส่ง = เปิดจนกว่าจะปิดเอง)
            'duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ส่งข้อความในห้องนี้', 403);
        }

        try {
            $poll = $this->pollService->create(
                $user,
                $schedule,
                trim($validated['question']),
                $validated['options'],
                (bool) ($validated['allow_multiple'] ?? false),
                $validated['duration_hours'] ?? null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = ChatMessage::with($this->messageRelations())->find($poll->message_id);

        // ผู้สร้างถือว่าอ่านถึงข้อความล่าสุดแล้ว + แจ้งเตือนสมาชิกเหมือนข้อความปกติ
        if ($message) {
            $this->chatService->markRead($user, $schedule, $message->id);
            SendChatPushJob::dispatch($message->id, $user->id, []);
        }

        return $this->success(
            $message ? $this->chatService->presentMessage($message, $user->id) : null,
            'สร้างโพลแล้ว',
            201,
        );
    }

    /**
     * ลงคะแนน — ส่ง option_ids ว่างมาได้ = ถอนโหวตของตัวเองทั้งหมด
     */
    public function votePoll(Request $request, int $scheduleId, int $pollId): JsonResponse
    {
        $validated = $request->validate([
            'option_ids' => ['present', 'array', 'max:'.ChatPoll::MAX_OPTIONS],
            'option_ids.*' => ['integer'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $poll = ChatPoll::where('schedule_id', $scheduleId)->findOrFail($pollId);

        try {
            $poll = $this->pollService->vote($user, $poll, $validated['option_ids']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'message_id' => (int) $poll->message_id,
            'poll' => $this->pollService->present($poll, $user->id),
        ], 'บันทึกคะแนนแล้ว');
    }

    /**
     * ปิดโหวต — เฉพาะคนสร้างโพล หรือสตาฟ/แอดมินประจำรอบ
     */
    public function closePoll(Request $request, int $scheduleId, int $pollId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $poll = ChatPoll::where('schedule_id', $scheduleId)->findOrFail($pollId);

        if ((int) $poll->created_by_id !== (int) $user->id
            && ! $this->chatService->canModerate($user, $schedule)) {
            return $this->error('ปิดโพลได้เฉพาะผู้สร้างโพลหรือทีมงาน', 403);
        }

        $poll = $this->pollService->close($poll);

        return $this->success([
            'message_id' => (int) $poll->message_id,
            'poll' => $this->pollService->present($poll, $user->id),
        ], 'ปิดโพลแล้ว');
    }

    public function typing(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        broadcast(new ChatTyping(
            $scheduleId,
            $user->id,
            $user->nickname ?: $user->name ?: 'สมาชิก',
        ))->toOthers();

        return $this->success(['ok' => true]);
    }

    public function joined(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        broadcast(new ChatJoined(
            $scheduleId,
            $user->id,
            $user->nickname ?: $user->name ?: 'สมาชิก',
            $this->chatService->senderRole($user, $schedule),
        ))->toOthers();

        return $this->success(['ok' => true]);
    }

    public function unreadCount(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        return $this->success([
            'count' => $this->chatService->unreadCount($user, $schedule),
        ]);
    }

    /**
     * ข้อมูลห้องแชท: รายชื่อสมาชิก จำนวนคน ตำแหน่งที่อ่านล่าสุดของแต่ละคน
     * (สำหรับสถานะ "อ่านแล้วกี่คน") และรถประจำรอบ
     */
    public function room(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with([
            'trip:id,title,latitude,longitude',
            'vehicle.driver',
            'pickupPoints' => fn ($q) => $q->orderBy('sort_order'),
        ])->findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $members = $this->chatService->members($schedule);
        $memberIds = $members->map(fn ($m) => $m['user']->id);
        $blockedIds = $this->moderation->blockedIds($user);

        // last_read_message_id ของสมาชิกแต่ละคน → ใช้คำนวณ "อ่านแล้ว N คน" ฝั่ง client
        $reads = ChatRead::where('schedule_id', $scheduleId)
            ->whereIn('user_id', $memberIds)
            ->pluck('last_read_message_id', 'user_id');

        $vehicle = $schedule->vehicle;

        // พยากรณ์อากาศวันเดินทาง (เฉพาะเมื่ออยู่ในกรอบเวลา + trip มีพิกัด) และ
        // ธงว่ารอบนี้มีกำหนดการไหม → ใช้ตัดสินใจแสดงปุ่มลัด "ข้อมูลทริป" ในแอป
        $this->weatherService->attach($schedule);

        return $this->success([
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'return_date' => $schedule->return_date?->toDateString(),
                'status' => $schedule->status,
            ],
            'vehicle' => $vehicle ? [
                'name' => $vehicle->name,
                'type' => $vehicle->type,
                'license_plate' => $vehicle->license_plate,
                'driver_name' => $vehicle->driver_name,
                'driver_phone' => $vehicle->driver_phone,
                // รูปคนขับสำหรับแถบติดต่อด้านบนแชท — snapshot บนรถมาก่อน แล้วค่อย
                // ตกไปที่รูปในทะเบียนคนขับ (รถที่ผูก driver_id ไว้)
                'driver_photo' => $vehicle->driver_photo ?: $vehicle->driver?->photo,
            ] : null,
            'weather' => $schedule->weather_forecast ?? null,
            // กำหนดการระดับทริปก็นับ — ปุ่มลัดจะได้ไม่หายไปทั้งที่มีแผนให้ดู
            'has_itinerary' => $this->itineraryService->hasItinerary($schedule),
            'pickup_points' => $schedule->pickupPoints->map(fn ($p) => [
                'id' => $p->id,
                'region_label' => $p->region_label,
                'pickup_location' => $p->pickup_location,
                'pickup_time' => $p->pickup_time,
                'notes' => $p->notes,
                'map_url' => $p->map_url,
                'latitude' => $p->latitude,
                'longitude' => $p->longitude,
            ])->values()->all(),
            'pinned_message' => $this->chatService->pinnedPayload(
                $this->chatService->pinnedMessage($schedule)
            ),
            'can_moderate' => $this->chatService->canModerate($user, $schedule),
            'reaction_emojis' => ChatService::REACTION_EMOJIS,
            'member_count' => $members->count(),
            'members' => $members->map(function ($m) use ($reads, $user, $blockedIds) {
                $u = $m['user'];

                // เปิดเบอร์เฉพาะสตาฟ/ทีมงาน เพื่อให้ลูกค้าติดต่อไกด์ประจำรอบได้
                // (สอดคล้องกับหน้า "วันเดินทาง" ที่โชว์เบอร์สตาฟอยู่แล้ว) —
                // ไม่เปิดเบอร์ลูกค้าให้กัน
                $isStaff = in_array($m['role'], ['staff', 'admin'], true);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'nickname' => $u->nickname,
                    'avatar_url' => $u->avatar_url,
                    ...$u->tierBadge(),
                    'role' => $m['role'],
                    'phone' => $isStaff ? $u->phone : null,
                    'is_me' => $u->id === $user->id,
                    // ให้แอปขึ้นป้าย "บล็อกแล้ว" และสลับปุ่มเป็น "เลิกบล็อก" ได้
                    'is_blocked' => in_array($u->id, $blockedIds, true),
                    'last_read_message_id' => (int) ($reads[$u->id] ?? 0),
                ];
            })->values()->all(),
        ]);
    }

    /**
     * รายการห้องแชททริปของผู้ใช้ (ลูกค้า/เพื่อนร่วมจอง/สตาฟ) พร้อมข้อความล่าสุด
     * และจำนวนที่ยังไม่อ่าน สำหรับแท็บ "แชท"
     */
    public function myConversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $scheduleIds = $this->chatService->userScheduleIds($user);

        if ($scheduleIds->isEmpty()) {
            return $this->success([]);
        }

        $schedules = TripSchedule::query()
            ->with(['trip:id,title,cover_image,thumbnail_image', 'vehicle:id,name'])
            ->whereIn('id', $scheduleIds)
            // เก็บเฉพาะรอบที่ยังไม่ผ่านไปนาน (กันลิสต์ยาวด้วยทริปเก่า)
            ->whereDate('departure_date', '>=', now()->subDays(14)->toDateString())
            ->get();

        $messagesBySchedule = ChatMessage::whereIn('schedule_id', $schedules->pluck('id'))
            ->with('user:id,name,nickname')
            ->tap(fn ($q) => $this->chatService->scopeVisibleTo($q, $user))
            ->get()
            ->groupBy('schedule_id');

        $reads = ChatRead::where('user_id', $user->id)
            ->whereIn('schedule_id', $schedules->pluck('id'))
            ->pluck('last_read_message_id', 'schedule_id');

        $conversations = $schedules->map(function ($schedule) use ($messagesBySchedule, $reads, $user) {
            $messages = $messagesBySchedule->get($schedule->id) ?? collect();
            $last = $messages->sortByDesc('id')->first();
            $lastReadId = (int) ($reads[$schedule->id] ?? 0);

            $unread = $messages
                ->where('id', '>', $lastReadId)
                ->filter(fn ($m) => $m->user_id === null || $m->user_id !== $user->id)
                ->count();

            return [
                'schedule_id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'trip_image' => $schedule->trip?->thumbnail_image ?: $schedule->trip?->cover_image,
                'vehicle_name' => $schedule->vehicle?->name,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'return_date' => $schedule->return_date?->toDateString(),
                'status' => $schedule->status,
                'unread_count' => $unread,
                'last_message' => $last ? [
                    'body' => $last->body,
                    'image_url' => $last->image_url,
                    'sender_role' => $last->sender_role,
                    'sender_name' => $last->user?->nickname ?: $last->user?->name,
                    'created_at' => $last->created_at?->toISOString(),
                ] : null,
                'last_activity' => $last?->created_at?->toISOString(),
            ];
        });

        // ห้องที่มีความเคลื่อนไหวอยู่บน เรียงตามล่าสุด; ห้องที่ยังไม่มีข้อความเรียงตามวันเดินทาง
        $withMessages = $conversations
            ->filter(fn ($c) => $c['last_activity'] !== null)
            ->sortByDesc('last_activity')
            ->values();

        // ห้องที่จบทริปแล้วและไม่มีข้อความ (ถูกล้างทิ้งหลังจบทริป 3 วัน) ไม่ต้องแสดง
        // แต่ยังคงโชว์ห้องของทริปที่กำลังจะมาถึงที่ยังไม่เริ่มแชท
        $today = now()->toDateString();
        $empty = $conversations
            ->filter(fn ($c) => $c['last_activity'] === null)
            ->reject(function ($c) use ($today) {
                $end = $c['return_date'] ?: $c['departure_date'];

                return $end !== null && $end < $today;
            })
            ->sortBy('departure_date')
            ->values();

        return $this->success($withMessages->concat($empty)->all());
    }

    /**
     * Admin/operator: รายการห้องแชททุกรอบที่กำลังจะเดินทาง (รวมห้องที่ยังไม่มีข้อความ
     * เพื่อให้แอดมินเข้าไปเริ่มแชทในรอบไหนก็ได้) พร้อมข้อความล่าสุดของแต่ละห้อง
     */
    public function adminConversations(Request $request): JsonResponse
    {
        $schedules = TripSchedule::query()
            ->with(['trip:id,title,cover_image,thumbnail_image', 'vehicle:id,name,type'])
            ->where('departure_date', '>=', now()->subDays(7)->startOfDay())
            ->whereIn('status', ['open', 'closed', 'full'])
            ->orderBy('departure_date')
            ->get();

        $messagesBySchedule = ChatMessage::whereIn('schedule_id', $schedules->pluck('id'))
            ->with('user:id,name,nickname')
            ->get()
            ->groupBy('schedule_id');

        $conversations = $schedules->map(function ($schedule) use ($messagesBySchedule) {
            $messages = $messagesBySchedule->get($schedule->id);
            $last = $messages?->sortByDesc('id')->first();

            return [
                'schedule_id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'trip_image' => $schedule->trip?->thumbnail_image ?: $schedule->trip?->cover_image,
                'vehicle_name' => $schedule->vehicle?->name,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'return_date' => $schedule->return_date?->toDateString(),
                'status' => $schedule->status,
                'message_count' => $messages?->count() ?? 0,
                'last_message' => $last ? [
                    'body' => $last->body,
                    'image_url' => $last->image_url,
                    'sender_role' => $last->sender_role,
                    'sender_name' => $last->user?->nickname ?: $last->user?->name,
                    'created_at' => $last->created_at?->toISOString(),
                ] : null,
                'last_activity' => $last?->created_at?->toISOString(),
            ];
        });

        // ห้องที่มีข้อความ: เรียงตามความเคลื่อนไหวล่าสุด (ใหม่สุดอยู่บน)
        $withMessages = $conversations
            ->filter(fn ($c) => $c['message_count'] > 0)
            ->sortByDesc('last_activity')
            ->values();

        // ห้องที่ยังไม่มีข้อความ: เรียงตามวันเดินทางที่ใกล้ที่สุด
        $empty = $conversations
            ->filter(fn ($c) => $c['message_count'] === 0)
            ->sortBy('departure_date')
            ->values();

        return $this->success($withMessages->concat($empty)->all());
    }
}
