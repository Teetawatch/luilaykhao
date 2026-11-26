<?php

namespace App\Jobs;

use App\Services\ChatTranslationService;
use App\Support\ChatTranslationBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * แปลข้อความใหม่ (หรือที่เพิ่งถูกแก้) เป็นทุกภาษาที่คนในห้องอ่าน แล้วส่งผล
 * ผ่าน Reverb — ห้องคนไทยล้วนจบตั้งแต่ autoTargets() โดยไม่เรียกโมเดล
 *
 * ไม่ unique: ถูกแก้ระหว่างที่งานแรกกำลังแปล งานที่สองต้องได้แปลเนื้อหาใหม่
 * งานซ้ำของเนื้อหาเดิมได้คำแปลจากตารางฟรี
 */
class AutoTranslateChatMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 5;

    /** $kind = คีย์ของ ChatTranslationService::KINDS (trip|private|support) */
    public function __construct(public int $messageId, public string $kind = 'trip') {}

    public function handle(ChatTranslationService $translations): void
    {
        $class = ChatTranslationService::KINDS[$this->kind] ?? null;
        $message = $class ? $class::find($this->messageId) : null;
        if (! $message) {
            return;
        }

        try {
            $targets = $translations->autoTargets($message);
        } catch (\Throwable $e) {
            // Redis ล่ม ฯลฯ — ไม่แปลข้อความนี้ คนอ่านยังกดแปลเองได้
            Log::warning('Chat auto-translate skipped', ['kind' => $this->kind, 'message_id' => $this->messageId, 'error' => $e->getMessage()]);

            return;
        }

        if ($targets !== []) {
            $translations->translateInto($message, $targets, claim: true);
            // ส่งคำแปลที่มีทั้งหมด — รวมภาษาที่งาน push แปลไปก่อน (งานนี้จึงข้ามไป)
            ChatTranslationBroadcast::send($message);
        }
    }
}
