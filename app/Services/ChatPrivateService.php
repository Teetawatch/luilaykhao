<?php

namespace App\Services;

use App\Events\ChatPrivateSignal;
use App\Jobs\SendChatPushJob;
use App\Models\ChatPrivateMessage;
use App\Models\ChatPrivateThread;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ถามทีมงานแบบส่วนตัว — เธรดแยกระหว่างลูกทริปหนึ่งคนกับทีมงานของรอบนั้น
 *
 * บางเรื่องไม่มีใครอยากพิมพ์ต่อหน้าคนทั้งรถ (ประจำเดือน อาการป่วย เรื่องเงิน อึดอัด
 * กับเพื่อนร่วมทริป) ทางเลือกเดิมมีแค่ห้องรวม โทรหาสตาฟ หรือศูนย์ช่วยเหลือที่ไปถึง
 * ออฟฟิศแต่ไม่ถึงสตาฟที่อยู่หน้างาน
 *
 * - ลูกทริปเห็นเฉพาะเธรดของตัวเอง ทีมงานของรอบ (สตาฟที่เคยดูแลรอบนี้ + แอดมิน) เห็นทุกเธรด
 * - ทีมงานเป็น "ฝั่งเดียวกัน" — ใครในทีมอ่านแล้วถือว่าทีมอ่านแล้ว ใครตอบก็ได้
 * - ข้อความส่วนตัวเด้งแจ้งเตือนเสมอ (ไม่ขึ้นกับระดับแจ้งเตือนของห้องรวม) เพราะคุยกับเราตรง ๆ
 * - หายไปพร้อมห้องแชทหลังจบทริป (PurgeEndedTripChatsJob)
 */
class ChatPrivateService
{
    public function __construct(
        private ChatService $chat,
        private FcmService $fcm,
    ) {}

    /** ถามทีมงานได้ = อยู่ในห้องแชทของรอบนี้ และไม่ใช่ทีมงานเอง */
    public function canAsk(User $user, TripSchedule $schedule): bool
    {
        return $this->chat->canAccess($user, $schedule) && ! $this->chat->canModerate($user, $schedule);
    }

    /** ตอบได้ / เห็นทุกเธรด = ทีมงานของรอบ (สตาฟที่ดูแล หรือแอดมิน) */
    public function canAnswer(User $user, TripSchedule $schedule): bool
    {
        return $this->chat->canModerate($user, $schedule);
    }

    public function thread(TripSchedule $schedule, int $customerId): ?ChatPrivateThread
    {
        return ChatPrivateThread::where('schedule_id', $schedule->id)
            ->where('user_id', $customerId)
            ->first();
    }

    /**
     * ส่งข้อความเข้าเธรดของ [$customer] — ผู้ส่งเป็นลูกทริปเจ้าของเธรดเอง หรือทีมงาน
     * (ทีมงานเริ่มคุยก่อนได้ เช่น ทักเรื่องค่าใช้จ่ายส่วนตัว)
     */
    public function send(
        User $sender,
        TripSchedule $schedule,
        User $customer,
        ?string $body,
        ?UploadedFile $image = null,
        ?UploadedFile $audio = null,
        ?int $audioDurationMs = null,
    ): ChatPrivateMessage {
        $fromCustomer = $sender->id === $customer->id;
        $body = $body !== null ? trim($body) : null;

        $imagePath = $image?->store('chat/private/'.date('Y/m'), MediaDisk::name());
        $audioPath = $audio?->store('chat/private/audio/'.date('Y/m'), MediaDisk::name());

        [$thread, $message] = DB::transaction(function () use ($schedule, $customer, $sender, $fromCustomer, $body, $imagePath, $audioPath, $audioDurationMs) {
            $thread = ChatPrivateThread::firstOrCreate(
                ['schedule_id' => $schedule->id, 'user_id' => $customer->id],
            );

            $message = ChatPrivateMessage::create([
                'thread_id' => $thread->id,
                'user_id' => $sender->id,
                'sender_role' => $fromCustomer ? ChatPrivateMessage::ROLE_CUSTOMER : $this->chat->senderRole($sender, $schedule),
                'body' => $body !== '' ? $body : null,
                'image_path' => $imagePath,
                'audio_path' => $audioPath,
                'audio_duration_ms' => $audioPath ? $audioDurationMs : null,
            ]);

            // คนส่งอ่านถึงข้อความของตัวเองแล้วเสมอ
            $thread->forceFill([
                'last_message_at' => $message->created_at,
                $fromCustomer ? 'customer_read_id' : 'staff_read_id' => $message->id,
            ])->save();

            return [$thread, $message];
        });

        $message->setRelation('thread', $thread);
        $message->load('user:id,name,nickname,avatar');

        broadcast(new ChatPrivateSignal(
            (int) $schedule->id,
            (int) $customer->id,
            ChatPrivateSignal::MESSAGE,
            ['message' => $this->present($message), 'thread' => $this->threadState($thread)],
        ))->toOthers();

        $this->notify($message, $thread, $schedule, $customer, $sender);

        return $message;
    }

    /**
     * ข้อความของเธรด — เปิดครั้งแรกได้ชุดล่าสุด, เลื่อนขึ้นส่ง before_id, ดึงของใหม่ส่ง after_id
     *
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool}
     */
    public function messages(ChatPrivateThread $thread, ?int $viewerId, int $beforeId = 0, int $afterId = 0, int $perPage = 30): array
    {
        $query = ChatPrivateMessage::where('thread_id', $thread->id)
            ->with('user:id,name,nickname,avatar');

        if ($afterId > 0) {
            $rows = $query->where('id', '>', $afterId)->orderBy('id')->limit($perPage)->get();

            return [
                'messages' => $rows->map(fn ($m) => $this->present($m, $viewerId))->all(),
                'has_more' => false,
            ];
        }

        $rows = $query
            ->when($beforeId > 0, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($perPage)
            ->get()
            ->reverse()
            ->values();

        return [
            'messages' => $rows->map(fn ($m) => $this->present($m, $viewerId))->all(),
            'has_more' => $rows->count() === $perPage,
        ];
    }

    /** อ่านถึงข้อความไหนแล้ว (ไม่ส่ง = ล่าสุด) — ทีมงานอ่านคนเดียวนับเป็นทั้งทีม */
    public function markRead(ChatPrivateThread $thread, bool $asStaff, ?int $messageId = null): ChatPrivateThread
    {
        $latest = (int) ($messageId ?: ChatPrivateMessage::where('thread_id', $thread->id)->max('id'));
        $column = $asStaff ? 'staff_read_id' : 'customer_read_id';

        if ($latest <= (int) $thread->{$column}) {
            return $thread;
        }

        $thread->forceFill([$column => $latest])->save();

        broadcast(new ChatPrivateSignal(
            (int) $thread->schedule_id,
            (int) $thread->user_id,
            ChatPrivateSignal::READ,
            ['thread' => $this->threadState($thread)],
        ))->toOthers();

        return $thread;
    }

    /** ลบข้อความของตัวเอง — เก็บแถวไว้ให้เธรดต่อเนื่อง ล้างเนื้อหาและไฟล์ */
    public function delete(ChatPrivateMessage $message): ChatPrivateMessage
    {
        foreach ([$message->image_path, $message->audio_path] as $path) {
            if ($path) {
                Storage::disk(MediaDisk::name())->delete($path);
            }
        }

        $message->forceFill([
            'is_deleted' => true,
            'body' => null,
            'image_path' => null,
            'audio_path' => null,
            'audio_duration_ms' => null,
        ])->save();

        $thread = $message->thread;

        broadcast(new ChatPrivateSignal(
            (int) $thread->schedule_id,
            (int) $thread->user_id,
            ChatPrivateSignal::UPDATED,
            ['message' => $this->present($message)],
        ))->toOthers();

        return $message;
    }

    /**
     * รายการเธรดของรอบสำหรับกล่องข้อความทีมงาน — ล่าสุดอยู่บน
     *
     * @return array<int, array<string, mixed>>
     */
    public function threads(TripSchedule $schedule): array
    {
        $threads = ChatPrivateThread::where('schedule_id', $schedule->id)
            ->whereNotNull('last_message_at')
            ->with('user:id,name,nickname,avatar')
            ->orderByDesc('last_message_at')
            ->get();

        if ($threads->isEmpty()) {
            return [];
        }

        $lastIds = ChatPrivateMessage::whereIn('thread_id', $threads->pluck('id'))
            ->selectRaw('thread_id, MAX(id) as last_id')
            ->groupBy('thread_id')
            ->pluck('last_id', 'thread_id');
        $last = ChatPrivateMessage::whereIn('id', $lastIds->values())->get()->keyBy('thread_id');
        $unread = $this->unreadByThread($threads, asStaff: true);

        return $threads->map(fn (ChatPrivateThread $t) => [
            ...$this->threadState($t),
            'customer' => $this->person($t->user),
            'unread' => $unread[$t->id] ?? 0,
            'last_message' => ($m = $last->get($t->id)) ? [
                'preview' => Str::limit($m->preview(), 120),
                'from_customer' => $m->fromCustomer(),
                'created_at' => $m->created_at?->toISOString(),
            ] : null,
        ])->values()->all();
    }

    /**
     * สรุปของห้องนี้สำหรับผู้ใช้คนนี้ (แนบไปกับ chat/room) — ลูกทริป: ยังไม่อ่านกี่ข้อความ
     * และใครคือทีมงานที่จะได้อ่าน, ทีมงาน: มีกี่คนรอคำตอบ
     *
     * @return array<string, mixed>|null null = ผู้ใช้นี้ไม่มีสิทธิ์ใช้
     */
    public function roomSummary(User $user, TripSchedule $schedule): ?array
    {
        if ($this->canAnswer($user, $schedule)) {
            $threads = ChatPrivateThread::where('schedule_id', $schedule->id)->get();
            $unread = $this->unreadByThread($threads, asStaff: true);

            return [
                'role' => 'staff',
                'unread' => array_sum($unread),
                'unread_threads' => count(array_filter($unread)),
                'thread_count' => $threads->whereNotNull('last_message_at')->count(),
            ];
        }

        if (! $this->canAsk($user, $schedule)) {
            return null;
        }

        return [
            'role' => 'customer',
            'unread' => $this->unreadForCustomer($user, $schedule),
            'staff' => $this->teamContacts($schedule),
        ];
    }

    /** ข้อความจากทีมงานที่ลูกทริปคนนี้ยังไม่ได้อ่าน */
    public function unreadForCustomer(User $user, TripSchedule $schedule): int
    {
        $thread = $this->thread($schedule, $user->id);

        return $thread ? ($this->unreadByThread(collect([$thread]), asStaff: false)[$thread->id] ?? 0) : 0;
    }

    /**
     * ยังไม่อ่านต่อเธรด — ฝั่งทีมงานนับข้อความจากลูกทริป ฝั่งลูกทริปนับข้อความจากทีมงาน
     *
     * @param  Collection<int, ChatPrivateThread>  $threads
     * @return array<int, int> thread_id => จำนวน
     */
    public function unreadByThread(Collection $threads, bool $asStaff): array
    {
        if ($threads->isEmpty()) {
            return [];
        }

        $floor = $threads->map(fn ($t) => $asStaff ? $t->staff_read_id : $t->customer_read_id)->min();

        $rows = ChatPrivateMessage::whereIn('thread_id', $threads->pluck('id'))
            ->where('id', '>', (int) $floor)
            ->where('is_deleted', false)
            ->when(
                $asStaff,
                fn ($q) => $q->where('sender_role', ChatPrivateMessage::ROLE_CUSTOMER),
                fn ($q) => $q->where('sender_role', '!=', ChatPrivateMessage::ROLE_CUSTOMER),
            )
            ->get(['id', 'thread_id']);

        return $threads->mapWithKeys(function (ChatPrivateThread $t) use ($rows, $asStaff) {
            $after = $asStaff ? $t->staff_read_id : $t->customer_read_id;

            return [$t->id => $rows->where('thread_id', $t->id)->where('id', '>', $after)->count()];
        })->all();
    }

    /**
     * ทีมงานที่ข้อความส่วนตัวจะไปถึง — สตาฟที่ยังดูแลรอบนี้อยู่ หลังจบทริป (ถูกปลดตีสี่)
     * ใช้ทุกคนที่เคยดูแลรอบนี้แทน ไม่งั้นคำถามหลังทริปไม่ถึงใครเลย
     *
     * @return Collection<int, User>
     */
    public function team(TripSchedule $schedule): Collection
    {
        $active = $schedule->activeStaff()->get(['users.id', 'users.name', 'users.nickname', 'users.avatar']);

        return $active->isNotEmpty()
            ? $active
            : $schedule->staff()->get(['users.id', 'users.name', 'users.nickname', 'users.avatar']);
    }

    /** @return array<int, array<string, mixed>> */
    public function teamContacts(TripSchedule $schedule): array
    {
        return $this->team($schedule)->map(fn (User $u) => $this->person($u))->values()->all();
    }

    /** @return array<string, mixed> */
    public function present(ChatPrivateMessage $message, ?int $viewerId = null): array
    {
        $deleted = (bool) $message->is_deleted;

        return [
            'id' => $message->id,
            'thread_user_id' => (int) ($message->thread?->user_id ?? 0),
            'body' => $deleted ? null : $message->body,
            'image_url' => $deleted ? null : $message->image_url,
            'audio_url' => $deleted ? null : $message->audio_url,
            'audio_duration_ms' => $deleted ? null : $message->audio_duration_ms,
            'sender_role' => $message->sender_role,
            'from_customer' => $message->fromCustomer(),
            'is_mine' => $viewerId !== null && (int) $message->user_id === $viewerId,
            'is_deleted' => $deleted,
            'user' => $message->user ? $this->person($message->user) : null,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function threadState(ChatPrivateThread $thread): array
    {
        return [
            'thread_id' => $thread->id,
            'thread_user_id' => (int) $thread->user_id,
            'customer_read_id' => (int) $thread->customer_read_id,
            'staff_read_id' => (int) $thread->staff_read_id,
            'last_message_at' => $thread->last_message_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function person(?User $user): array
    {
        return [
            'id' => $user?->id,
            'name' => $user?->name,
            'nickname' => $user?->nickname,
            'avatar_url' => $user?->avatar_url,
        ];
    }

    /**
     * ลบเธรดของรอบที่จบแล้วพร้อมไฟล์ — เรียกจาก PurgeEndedTripChatsJob
     *
     * @param  array<int, int>  $scheduleIds
     * @return int จำนวนไฟล์ที่ลบ
     */
    public function purge(array $scheduleIds): int
    {
        $threadIds = ChatPrivateThread::whereIn('schedule_id', $scheduleIds)->pluck('id');
        if ($threadIds->isEmpty()) {
            return 0;
        }

        $paths = ChatPrivateMessage::whereIn('thread_id', $threadIds)
            ->where(fn ($q) => $q->whereNotNull('image_path')->orWhereNotNull('audio_path'))
            ->get(['image_path', 'audio_path'])
            ->flatMap(fn (ChatPrivateMessage $m) => [$m->image_path, $m->audio_path])
            ->filter(fn ($p) => $p !== null && $p !== '' && str_starts_with((string) $p, 'chat/'))
            ->values();

        try {
            if ($paths->isNotEmpty()) {
                Storage::disk(MediaDisk::name())->delete($paths->all());
            }
        } catch (\Throwable $e) {
            Log::warning('ChatPrivateService: ลบไฟล์ของเธรดส่วนตัวไม่สำเร็จ', ['error' => $e->getMessage()]);
        }

        ChatPrivateMessage::whereIn('thread_id', $threadIds)->delete();
        ChatPrivateThread::whereIn('id', $threadIds)->delete();

        return $paths->count();
    }

    /**
     * แจ้งอีกฝั่ง — ลูกทริปส่ง: ทีมงานทุกคนของรอบ, ทีมงานส่ง: ลูกทริปเจ้าของเธรด
     * (และทีมงานคนอื่น ๆ ไม่ต้องรู้ เพราะคำถามมีคนตอบแล้ว)
     */
    private function notify(ChatPrivateMessage $message, ChatPrivateThread $thread, TripSchedule $schedule, User $customer, User $sender): void
    {
        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';
        $preview = Str::limit($message->preview(), 120);
        $senderName = $sender->nickname ?: ($sender->name ?: 'ทีมงาน');
        $tag = self::pushTag((int) $schedule->id, (int) $customer->id);

        $data = [
            'type' => 'chat_private',
            'route' => 'chat_private',
            'schedule_id' => (string) $schedule->id,
            'user_id' => (string) $customer->id,
            'message_id' => (string) $message->id,
            'tag' => $tag,
        ];
        $display = ['tag' => $tag, 'thread' => $tag, 'android_channel' => SendChatPushJob::ANDROID_CHANNEL];

        if ($message->fromCustomer()) {
            $recipients = $this->team($schedule)->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => $id === $sender->id);

            foreach ($recipients as $staffId) {
                $this->push($staffId, "🔒 {$senderName} ถามทีมงานแบบส่วนตัว", "{$preview} · {$tripTitle}", $data, $display);
            }

            return;
        }

        $this->push((int) $customer->id, '🔒 ทีมงานตอบกลับ', "{$senderName}: {$preview} · {$tripTitle}", $data, $display);
    }

    /** tag ของแจ้งเตือนเธรดนี้ — ใบใหม่ทับใบเก่าในถาด แอปใช้ค่าเดียวกันล้างตอนเปิดเธรด */
    public static function pushTag(int $scheduleId, int $customerId): string
    {
        return "chat-private-{$scheduleId}-{$customerId}";
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, mixed>  $display
     */
    private function push(int $userId, string $title, string $body, array $data, array $display): void
    {
        try {
            $this->fcm->sendToUser($userId, $title, $body, $data, $display);
        } catch (\Throwable $e) {
            Log::warning('ChatPrivateService: ส่ง push ไม่สำเร็จ', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
