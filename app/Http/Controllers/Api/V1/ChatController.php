<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Services\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
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

        $messages = ChatMessage::where('schedule_id', $scheduleId)
            ->with('user:id,name,nickname,avatar')
            ->when($beforeId > 0, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($perPage)
            ->get()
            ->reverse()
            ->values();

        return $this->success([
            'messages' => $messages->map(fn ($m) => $this->present($m, $user->id))->all(),
            'has_more' => $messages->count() === $perPage,
        ]);
    }

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ส่งข้อความในห้องนี้', 403);
        }

        $message = ChatMessage::create([
            'schedule_id' => $scheduleId,
            'user_id' => $user->id,
            'sender_role' => $this->chatService->senderRole($user, $schedule),
            'body' => trim($validated['body']),
        ]);
        $message->load('user:id,name,nickname,avatar');

        broadcast(new ChatMessageSent($message))->toOthers();

        // ผู้ส่งถือว่าอ่านถึงข้อความล่าสุดแล้ว
        $this->chatService->markRead($user, $schedule, $message->id);

        SendChatPushJob::dispatch($message->id, $user->id);

        return $this->success($this->present($message, $user->id), 'ส่งข้อความสำเร็จ', 201);
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

        return $this->success(['unread' => 0], 'อ่านแล้ว');
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
     * Admin/operator: รายการห้องแชท (รอบที่กำลังจะเดินทาง) พร้อมข้อความล่าสุด
     */
    public function adminConversations(Request $request): JsonResponse
    {
        $schedules = TripSchedule::query()
            ->with('trip:id,title')
            ->where('departure_date', '>=', now()->subDays(7)->startOfDay())
            ->whereIn('status', ['open', 'closed', 'full'])
            ->orderBy('departure_date')
            ->get();

        $lastMessages = ChatMessage::whereIn('schedule_id', $schedules->pluck('id'))
            ->get()
            ->groupBy('schedule_id');

        $conversations = $schedules->map(function ($schedule) use ($lastMessages) {
            $messages = $lastMessages->get($schedule->id);
            $last = $messages?->sortByDesc('id')->first();

            return [
                'schedule_id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'message_count' => $messages?->count() ?? 0,
                'last_message' => $last ? [
                    'body' => $last->body,
                    'sender_role' => $last->sender_role,
                    'created_at' => $last->created_at?->toISOString(),
                ] : null,
            ];
        })->filter(fn ($c) => $c['message_count'] > 0)->values();

        return $this->success($conversations->all());
    }

    private function present(ChatMessage $message, int $currentUserId): array
    {
        $user = $message->user;

        return [
            'id' => $message->id,
            'schedule_id' => $message->schedule_id,
            'body' => $message->body,
            'sender_role' => $message->sender_role,
            'is_mine' => $message->user_id === $currentUserId,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'nickname' => $user->nickname,
                'avatar_url' => $user->avatar_url,
            ] : null,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
