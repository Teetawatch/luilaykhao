<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ChatRead;
use App\Models\ChatRoomPreference;
use App\Models\UserBlock;
use App\Services\ChatService;
use App\Services\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * แจ้งเตือนข้อความใหม่ในห้องแชททริป
 *
 * ห้องกลุ่มคุยกันรัว ๆ จึงไม่ปล่อยให้เด้งทุกข้อความ:
 * - เคารพการตั้งค่าของแต่ละคน (ทุกข้อความ / เฉพาะทีมงาน+แท็กถึงฉัน / ปิด)
 * - ทุกใบของห้องเดียวกันใช้ tag เดียวกัน → ทับใบเดิมในถาด พร้อมบอกจำนวนที่ยังไม่อ่าน
 * - มีเสียงได้ครั้งเดียวต่อห้องต่อ ALERT_COOLDOWN_SECONDS ใบที่ตามมาในช่วงนั้นเข้าถาดเงียบ ๆ
 *   (ยกเว้นแท็กถึงตัวเอง ซึ่งเป็นเรื่องของเขาโดยตรงเสมอ)
 * - คนที่อ่านถึงข้อความนี้ไปแล้ว (เปิดห้องค้างไว้อยู่) ไม่ต้องเด้ง
 */
class SendChatPushJob implements ShouldQueue
{
    use Queueable;

    /** ช่วงที่ห้องเดียวกันเด้งมีเสียงได้ครั้งเดียวต่อคน */
    public const ALERT_COOLDOWN_SECONDS = 180;

    /** channel ที่แอปสร้างไว้ — ต้องตรงกับ PushNotificationService ในแอป */
    public const ANDROID_CHANNEL = 'chat_messages';

    public const ANDROID_QUIET_CHANNEL = 'chat_quiet';

    public int $tries = 2;

    public int $backoff = 15;

    /**
     * @param  array<int>  $mentionedUserIds
     */
    public function __construct(
        public readonly int $messageId,
        public readonly int $senderUserId,
        public readonly array $mentionedUserIds = [],
    ) {}

    /** tag ของแจ้งเตือนห้องนี้ — แอปใช้ค่าเดียวกันล้างถาดตอนเปิดห้อง */
    public static function tagFor(int $scheduleId, bool $mention = false): string
    {
        return $mention ? "chat-{$scheduleId}-mention" : "chat-{$scheduleId}";
    }

    public static function alertCacheKey(int $scheduleId, int $userId): string
    {
        return "chat_push_alert:{$scheduleId}:{$userId}";
    }

    public function handle(ChatService $chatService, FcmService $fcm): void
    {
        $message = ChatMessage::with('schedule.trip', 'user')->find($this->messageId);
        if (! $message || ! $message->schedule) {
            return;
        }

        // ถูกลบหรือถูกซ่อนจากการรายงานก่อนคิวมาถึง — ไม่มีอะไรให้เปิดอ่านแล้ว
        if ($message->is_deleted || $message->hidden_at !== null) {
            return;
        }

        $scheduleId = (int) $message->schedule_id;
        $tripTitle = $message->schedule->trip?->title ?? 'ทริปของคุณ';
        $senderName = $message->user?->nickname
            ?: ($message->user?->name ?? 'ทีมงาน');
        $preview = filled($message->body)
            ? Str::limit($message->body, 120)
            : ($message->image_path ? '📷 ส่งรูปภาพ' : '');

        $mentioned = collect($this->mentionedUserIds)->map(fn ($id) => (int) $id);
        $fromTeam = in_array($message->sender_role, ['staff', 'admin'], true);

        // คนที่บล็อกผู้ส่งไว้ (หรือถูกผู้ส่งบล็อก) ไม่ควรได้รับ push ของข้อความนี้ —
        // ข้อความที่เขาเปิดเข้าไปแล้วมองไม่เห็น ไม่ควรเด้งเตือนตั้งแต่แรก
        $blockedPairIds = UserBlock::where('blocker_id', $this->senderUserId)
            ->orWhere('blocked_id', $this->senderUserId)
            ->get(['blocker_id', 'blocked_id'])
            ->map(fn (UserBlock $b) => $b->blocker_id === $this->senderUserId ? $b->blocked_id : $b->blocker_id)
            ->unique();

        $recipientIds = $chatService->pushRecipientIds($message->schedule)
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $this->senderUserId)
            ->reject(fn (int $id) => $blockedPairIds->contains($id))
            ->values();

        $levels = $chatService->notifyLevels($scheduleId, $recipientIds);

        $recipientIds = $recipientIds
            ->filter(fn (int $id) => ChatRoomPreference::wantsPush(
                $levels[$id] ?? ChatRoomPreference::LEVEL_ALL,
                $mentioned->contains($id),
                $fromTeam,
            ))
            ->values();

        $unread = $this->unreadCounts($message, $recipientIds);

        foreach ($recipientIds as $userId) {
            $unreadCount = $unread[$userId] ?? 0;

            // อ่านถึงข้อความนี้ไปแล้ว — เปิดห้องค้างไว้และเห็นมันขึ้นมาเอง
            if ($unreadCount === 0) {
                continue;
            }

            // Mentioned members get a more salient "you were mentioned" push
            // instead of the regular new-message one.
            $isMention = $mentioned->contains($userId);
            $quiet = ! $isMention && ! $this->claimAlert($scheduleId, $userId);

            $title = $isMention ? "📣 $tripTitle" : "💬 $tripTitle";
            if (! $isMention && $unreadCount > 1) {
                $title .= " · {$unreadCount} ข้อความใหม่";
            }

            try {
                $fcm->sendToUser(
                    $userId,
                    $title,
                    $isMention
                        ? "$senderName กล่าวถึงคุณ: $preview"
                        : "$senderName: $preview",
                    [
                        'type' => 'chat_message',
                        'route' => 'chat',
                        'schedule_id' => (string) $scheduleId,
                        'message_id' => (string) $message->id,
                        'mention' => $isMention ? '1' : '0',
                        'quiet' => $quiet ? '1' : '0',
                        'tag' => self::tagFor($scheduleId, $isMention),
                    ],
                    [
                        // แท็กถึงฉันแยก tag ไว้ ไม่ให้ข้อความทั่วไปที่ตามมาทับหายไป
                        'tag' => self::tagFor($scheduleId, $isMention),
                        'thread' => self::tagFor($scheduleId),
                        'quiet' => $quiet,
                        'android_channel' => $quiet ? self::ANDROID_QUIET_CHANNEL : self::ANDROID_CHANNEL,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('SendChatPushJob: ส่ง push ไม่สำเร็จ', [
                    'user_id' => $userId,
                    'message_id' => $this->messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * จองสิทธิ์ "เด้งมีเสียง" ของห้องนี้ — ได้ครั้งเดียวต่อช่วง cooldown
     * แคชล่มเมื่อไหร่ให้มีเสียงไว้ก่อน: ดังเกินแค่รำคาญ แต่เงียบเกินคือพลาด
     */
    private function claimAlert(int $scheduleId, int $userId): bool
    {
        try {
            return Cache::add(self::alertCacheKey($scheduleId, $userId), true, self::ALERT_COOLDOWN_SECONDS);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * จำนวนข้อความที่แต่ละคนยังไม่อ่าน นับถึงข้อความนี้ (ไม่นับของตัวเอง
     * และข้อความที่ถูกซ่อน) — เกณฑ์เดียวกับ ChatService::unreadCount แต่ดึงทีเดียว
     * ทั้งห้องแทนการยิงคิวรีต่อคน
     *
     * @param  Collection<int, int>  $recipientIds
     * @return array<int, int>
     */
    private function unreadCounts(ChatMessage $message, Collection $recipientIds): array
    {
        if ($recipientIds->isEmpty()) {
            return [];
        }

        $lastRead = ChatRead::where('schedule_id', $message->schedule_id)
            ->whereIn('user_id', $recipientIds)
            ->pluck('last_read_message_id', 'user_id')
            ->mapWithKeys(fn ($id, $userId) => [(int) $userId => (int) $id]);

        $floor = $recipientIds->map(fn (int $id) => $lastRead[$id] ?? 0)->min();

        $pending = ChatMessage::where('schedule_id', $message->schedule_id)
            ->where('id', '>', $floor)
            ->where('id', '<=', $message->id)
            ->whereNull('hidden_at')
            ->get(['id', 'user_id']);

        return $recipientIds->mapWithKeys(function (int $userId) use ($pending, $lastRead) {
            $after = $lastRead[$userId] ?? 0;

            return [$userId => $pending
                ->filter(fn (ChatMessage $m) => $m->id > $after && (int) $m->user_id !== $userId)
                ->count()];
        })->all();
    }
}
