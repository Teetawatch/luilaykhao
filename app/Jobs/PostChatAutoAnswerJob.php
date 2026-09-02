<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Services\ChatAutoAnswerService;
use App\Services\TripChatTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * โพสต์คำตอบของคำถามที่ลูกค้าเพิ่งถามในห้องแชท (ดูกฎทั้งหมดที่
 * ChatAutoAnswerService) — หน่วงไว้ก่อนเสมอ เพื่อเปิดทางให้คนตอบก่อนบอท
 */
class PostChatAutoAnswerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(ChatAutoAnswerService $auto): void
    {
        if (! ChatAutoAnswerService::enabled()) {
            return;
        }

        $question = ChatMessage::with('schedule.trip')->find($this->messageId);

        // ถามแล้วลบทิ้ง = ไม่ได้อยากรู้แล้ว
        if (! $question || $question->is_deleted) {
            return;
        }

        $schedule = $question->schedule;

        if (! $schedule || ! $auto->roundIsLive($schedule)) {
            return;
        }

        $topic = $auto->topicFor($question->body);

        // ข้อความถูกแก้หลังส่ง จนไม่ใช่คำถามเดิมอีกแล้ว
        if ($topic === null) {
            return;
        }

        $answer = $auto->answerFor($schedule, $topic);

        if ($answer === null) {
            return;
        }

        $key = $auto->dedupeKey(
            $answer['kind'],
            CarbonImmutable::now(TripChatTimelineService::TIMEZONE),
        );

        if ($auto->alreadyHandled($question, $answer['kind'], $key)) {
            return;
        }

        try {
            $auto->post($schedule, $answer['body'], $key);
        } catch (QueryException) {
            // สองคนถามพร้อมกัน อีกงานหนึ่งชิงโพสต์ไปก่อน — unique key กันไว้ให้แล้ว
            return;
        }

        Log::info('ChatAutoAnswer: ตอบคำถามในห้องให้อัตโนมัติ', [
            'schedule_id' => $schedule->id,
            'question_id' => $question->id,
            'topic' => $topic,
            'kind' => $answer['kind'],
        ]);
    }
}
