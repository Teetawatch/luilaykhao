<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Services\ChatService;
use App\Services\TripChatTimelineService;
use App\Services\TripFactsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * แจ้งห้องแชทเมื่อแอดมินแก้กำหนดการ "หลังจาก" ห้องได้ข้อความกำหนดการไปแล้ว
 *
 * ข้อความ itinerary_2d ถูกกันซ้ำด้วย system_key จึงโพสต์ครั้งเดียวตลอดกาล —
 * ถ้าแอดมินเลื่อนเวลาออกรถทีหลัง ข้อความเดิมที่ค้างอยู่ในห้องจะกลายเป็นข้อมูล
 * ผิดที่ลูกค้าเชื่อไปแล้ว งานนี้จึงโพสต์ฉบับล่าสุดทับความเข้าใจเดิม
 *
 * กันสแปมสองชั้น เพราะแอดมินหนึ่งคนแก้กำหนดการทีเป็นสิบแถวรวด
 * 1. หน่วง DEBOUNCE_MINUTES ก่อนทำงาน — การแก้รัวในช่วงนั้นยุบเหลือข้อความเดียว
 * 2. เทียบเนื้อความกับกำหนดการฉบับล่าสุดที่เคยลงห้อง เหมือนเดิม = ไม่โพสต์
 *    (ครอบกรณีแก้แล้วแก้กลับ และกรณีที่งานถูกยิงซ้ำด้วย)
 */
class AnnounceItineraryChangeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** รอให้แอดมินแก้จนจบก่อนค่อยสรุปลงห้องทีเดียว */
    public const DEBOUNCE_MINUTES = 10;

    public function __construct(public int $scheduleId) {}

    public function handle(ChatService $chat, TripFactsService $facts): void
    {
        $schedule = TripSchedule::with(['trip', 'itineraryItems'])->find($this->scheduleId);

        if (! $schedule || $schedule->status === 'cancelled') {
            return;
        }

        // ห้องที่ยังไม่เคยได้ข้อความกำหนดการ ไม่มีอะไรให้แก้ความเข้าใจ —
        // ข้อความ D-2 ที่จะลงทีหลังพกฉบับล่าสุดไปเองอยู่แล้ว
        $announced = ChatMessage::where('schedule_id', $schedule->id)
            ->where('system_key', 'itinerary_2d')
            ->exists();

        if (! $announced || $this->tripIsOver($schedule)) {
            return;
        }

        $body = $facts->itinerarySummaryText($schedule, updated: true);

        if ($body === null || $this->sameAsLastPosted($schedule, $body)) {
            return;
        }

        $chat->postSystem($schedule, $body);

        Log::info('AnnounceItineraryChange: แจ้งกำหนดการที่ปรับแล้วเข้าห้อง', [
            'schedule_id' => $schedule->id,
        ]);
    }

    /**
     * จบทริปแล้วไม่ต้องแจ้ง — แอดมินมักมาจัดกำหนดการย้อนหลังเพื่อใช้ซ้ำรอบหน้า
     */
    private function tripIsOver(TripSchedule $schedule): bool
    {
        $end = $schedule->return_date ?? $schedule->departure_date;

        if ($end === null) {
            return false;
        }

        return CarbonImmutable::now(TripChatTimelineService::TIMEZONE)->gt(
            CarbonImmutable::parse($end->toDateString(), TripChatTimelineService::TIMEZONE)->endOfDay(),
        );
    }

    /**
     * กำหนดการฉบับล่าสุดในห้องเหมือนกับที่กำลังจะโพสต์ไหม
     *
     * เทียบทุกอย่าง "ยกเว้นบรรทัดหัว" เพราะฉบับ D-2 กับฉบับแก้ไขต่างกันแค่หัว
     * ข้อความ — เนื้อในเหมือนกันเมื่อไหร่แปลว่ากำหนดการไม่ได้เปลี่ยนจริง
     */
    private function sameAsLastPosted(TripSchedule $schedule, string $body): bool
    {
        $last = ChatMessage::where('schedule_id', $schedule->id)
            ->where('sender_role', 'system')
            ->where('body', 'like', TripFactsService::ITINERARY_MARK.'%')
            ->latest('id')
            ->value('body');

        return $last !== null && $this->withoutHeading($last) === $this->withoutHeading($body);
    }

    private function withoutHeading(string $body): string
    {
        $break = strpos($body, "\n");

        return $break === false ? '' : substr($body, $break + 1);
    }
}
