<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ChatRoomPreference;
use App\Services\ChatAckService;
use App\Services\ChatService;
use App\Services\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * แจ้งลูกทริปที่ยังไม่กดรับทราบประกาศ — ตอนทีมงานเพิ่งขอ หรือตอนกด "สะกิด"
 *
 * ผ่านระดับ "เฉพาะสำคัญ" (เป็นเรื่องจากทีมงาน) แต่เคารพคนที่ปิดห้องนี้ไว้ทั้งห้อง
 * ใช้ tag เดียวกับแจ้งเตือนแชทของห้อง จึงทับใบเดิมในถาดแทนการกองเพิ่ม
 */
class SendChatAckPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(
        public readonly int $messageId,
        public readonly bool $nudge,
    ) {}

    public function handle(ChatAckService $acks, ChatService $chat, FcmService $fcm): void
    {
        $message = ChatMessage::with('schedule.trip')->find($this->messageId);

        if (! $message || ! $message->schedule || $message->is_deleted
            || $message->hidden_at !== null || $message->ack_requested_at === null) {
            return;
        }

        $pending = $acks->pendingUserIds($message);
        if ($pending->isEmpty()) {
            return;
        }

        $scheduleId = (int) $message->schedule_id;
        $levels = $chat->notifyLevels($scheduleId, $pending);
        $trip = $message->schedule->trip?->title ?? 'ทริปของคุณ';
        // ข้อความระบบยาวหลายบรรทัด — บรรทัดแรกคือหัวเรื่อง พอให้รู้ว่าประกาศเรื่องอะไร
        $headline = Str::limit(trim(strtok((string) $message->body, "\n") ?: ''), 100);
        $preview = $headline !== '' ? $headline : 'ประกาศจากทีมงาน';

        foreach ($pending as $userId) {
            $level = $chat->effectiveLevel($levels[$userId] ?? ChatRoomPreference::LEVEL_ALL, $message->schedule);
            if (! ChatRoomPreference::wantsPush($level, false, true)) {
                continue;
            }

            try {
                $fcm->sendToUser(
                    $userId,
                    $this->nudge ? "⏰ {$trip}" : "📌 {$trip}",
                    $this->nudge
                        ? "ทีมงานรอคุณกดรับทราบ: {$preview}"
                        : "ทีมงานขอให้กดรับทราบ: {$preview}",
                    [
                        'type' => 'chat_message',
                        'route' => 'chat',
                        'schedule_id' => (string) $scheduleId,
                        'message_id' => (string) $message->id,
                        'mention' => '0',
                        'quiet' => '0',
                        'tag' => SendChatPushJob::tagFor($scheduleId),
                    ],
                    [
                        'tag' => SendChatPushJob::tagFor($scheduleId),
                        'thread' => SendChatPushJob::tagFor($scheduleId),
                        'quiet' => false,
                        'android_channel' => SendChatPushJob::ANDROID_CHANNEL,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('SendChatAckPushJob: ส่ง push ไม่สำเร็จ', [
                    'user_id' => $userId,
                    'message_id' => $this->messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
