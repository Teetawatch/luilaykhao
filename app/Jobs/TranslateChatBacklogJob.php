<?php

namespace App\Jobs;

use App\Services\ChatTranslationService;
use App\Support\ChatTranslationBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * แปลข้อความเก่าที่ผู้ชมกำลังเห็นเป็นภาษาของเขา — คนที่เพิ่งเข้าห้อง (ตอนข้อความ
 * พวกนี้ถูกส่ง ห้องยังไม่มีคนอ่านภาษานี้) หรือเพิ่งเปลี่ยนภาษา ส่งผลทีละข้อความ
 * ทาง Reverb ข้อความล่าสุดก่อน เพราะเป็นส่วนที่อยู่บนจอ
 */
class TranslateChatBacklogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(
        public string $lang,
        public array $messageIds,
        public string $kind = 'trip',
    ) {}

    public function handle(ChatTranslationService $translations): void
    {
        $class = ChatTranslationService::KINDS[$this->kind] ?? null;
        if (! $class) {
            return;
        }

        // id มาจาก backlogFrom() ที่ตรวจสิทธิ์และจำกัดห้องไว้แล้ว
        $messages = $class::whereIn('id', $this->messageIds)
            ->orderByDesc('id')
            ->get();

        foreach ($messages as $message) {
            if (! $translations->isTranslatable($message)) {
                continue;
            }

            if ($translations->translateInto($message, [$this->lang]) > 0) {
                ChatTranslationBroadcast::send($message);
            }
        }
    }
}
