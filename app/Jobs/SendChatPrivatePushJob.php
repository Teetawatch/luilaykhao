<?php

namespace App\Jobs;

use App\Models\ChatPrivateMessage;
use App\Models\User;
use App\Services\ChatPrivateService;
use App\Services\ChatPushTranslator;
use App\Support\ChatTranslationBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * push ของแชทส่วนตัวกับทีมงานถึงคนที่อ่านภาษาอื่น — รอคำแปลของข้อความก่อน
 * (คนที่ไม่ต้องแปลได้ push ไปแล้วจาก ChatPrivateService::notify) แปลไม่ทันก็ส่งต้นฉบับ
 */
class SendChatPrivatePushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, int>  $userIds
     */
    public function __construct(
        public int $messageId,
        public int $senderId,
        public string $lang,
        public array $userIds,
    ) {}

    public function handle(ChatPrivateService $private, ChatPushTranslator $text): void
    {
        $message = ChatPrivateMessage::with(['thread.schedule.trip', 'thread.user'])->find($this->messageId);
        $sender = User::find($this->senderId);

        if (! $message || $message->is_deleted || ! $message->thread?->schedule || ! $message->thread->user || ! $sender) {
            return;
        }

        $translated = $text->translatedBody($message, $this->lang);
        if ($translated !== null) {
            ChatTranslationBroadcast::send($message);
        }

        $private->pushGroup(
            $message,
            $message->thread->schedule,
            $message->thread->user,
            $sender,
            $this->lang,
            $this->userIds,
            $translated,
        );
    }
}
