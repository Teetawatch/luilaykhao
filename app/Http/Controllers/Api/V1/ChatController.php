<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatRead;
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
        $afterId = (int) $request->get('after_id', 0);

        // Polling for live updates: return messages newer than $afterId, oldest-first.
        if ($afterId > 0) {
            $messages = ChatMessage::where('schedule_id', $scheduleId)
                ->with('user:id,name,nickname,avatar')
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($perPage)
                ->get();

            return $this->success([
                'messages' => $messages->map(fn ($m) => $this->present($m, $user->id))->all(),
                'has_more' => false,
            ]);
        }

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
            'body' => ['nullable', 'string', 'max:2000', 'required_without:image'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ส่งข้อความในห้องนี้', 403);
        }

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('chat/'.date('Y/m'), 'public')
            : null;

        $body = isset($validated['body']) ? trim($validated['body']) : null;

        $message = ChatMessage::create([
            'schedule_id' => $scheduleId,
            'user_id' => $user->id,
            'sender_role' => $this->chatService->senderRole($user, $schedule),
            'body' => $body !== '' ? $body : null,
            'image_path' => $imagePath,
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
     * ข้อมูลห้องแชท: รายชื่อสมาชิก จำนวนคน ตำแหน่งที่อ่านล่าสุดของแต่ละคน
     * (สำหรับสถานะ "อ่านแล้วกี่คน") และรถประจำรอบ
     */
    public function room(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['trip:id,title', 'vehicle'])->findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $members = $this->chatService->members($schedule);
        $memberIds = $members->map(fn ($m) => $m['user']->id);

        // last_read_message_id ของสมาชิกแต่ละคน → ใช้คำนวณ "อ่านแล้ว N คน" ฝั่ง client
        $reads = ChatRead::where('schedule_id', $scheduleId)
            ->whereIn('user_id', $memberIds)
            ->pluck('last_read_message_id', 'user_id');

        $vehicle = $schedule->vehicle;

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
            ] : null,
            'member_count' => $members->count(),
            'members' => $members->map(function ($m) use ($reads, $user) {
                $u = $m['user'];

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'nickname' => $u->nickname,
                    'avatar_url' => $u->avatar_url,
                    'role' => $m['role'],
                    'is_me' => $u->id === $user->id,
                    'last_read_message_id' => (int) ($reads[$u->id] ?? 0),
                ];
            })->values()->all(),
        ]);
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

    private function present(ChatMessage $message, int $currentUserId): array
    {
        $user = $message->user;

        return [
            'id' => $message->id,
            'schedule_id' => $message->schedule_id,
            'body' => $message->body,
            'image_url' => $message->image_url,
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
