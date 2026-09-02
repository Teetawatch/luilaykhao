<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Support\SiteSettings;
use Carbon\CarbonImmutable;

/**
 * ตอบคำถามที่ห้องแชทถามซ้ำที่สุดให้เองเมื่อลูกค้าพิมพ์ถาม
 *
 * ต่างจากแถบคำตอบในแอป (QuickAskMatcher ฝั่ง Dart) ตรงที่อันนี้ "โพสต์ลงห้อง"
 * ให้ทุกคนเห็น คำถามหนึ่งข้อจึงตอบทั้งห้องทีเดียว ไม่ใช่ตอบทีละคนที่ถาม
 *
 * เพราะมันพูดต่อหน้าคนอื่น ทุกกฎในนี้จึงเอียงไป "เงียบไว้ก่อน":
 * - ตอบเฉพาะคำถามของลูกค้า สตาฟถามกันเองไม่ต้องมีบอทมาแทรก
 * - หน่วง QUIET_MINUTES ก่อน แล้วเงียบทันทีถ้าทีมงานตอบไปแล้วในระหว่างนั้น
 *   — คนตอบดีกว่าบอทตอบเสมอ บอทมีไว้รับตอนไม่มีใครว่าง
 * - ข้อมูลที่ยังไม่รู้ไม่ตอบ ปล่อยให้คนตอบ ("ทีมงานจะยืนยันให้ก่อนเดินทาง"
 *   จากปากบอทอ่านเหมือนปัดคำถามทิ้ง)
 * - ตอบได้วันละครั้งต่อห้องต่อชนิดคำตอบ (กันด้วย system_key ที่ unique
 *   ระดับฐานข้อมูลอยู่แล้ว) สิบคนถามเรื่องเดียวกันจึงได้ข้อความเดียว
 *
 * ⚠️ รายการคำต้องตรงกับ `luilaykhao-app/lib/services/quick_ask_matcher.dart`
 * แก้ที่นี่แล้วต้องไปแก้ที่นั่นด้วย ไม่งั้นแถบในแอปกับบอทในห้องจะจับคนละอย่าง
 */
class ChatAutoAnswerService
{
    /** รอให้คนตอบก่อนกี่นาที ก่อนที่บอทจะยื่นคำตอบให้ */
    public const QUIET_MINUTES = 3;

    /** คำตอบชนิด "กำหนดการ" กับชนิด "ข้อมูลการเดินทาง" — คนละคีย์กันวันละครั้ง */
    public const KIND_ITINERARY = 'itinerary';

    public const KIND_FACTS = 'facts';

    /** @var array<string, array<int, string>> */
    private const STRONG = [
        'itinerary' => [
            'กำหนดการ', 'ตารางเวลา', 'ตารางทริป', 'ตารางเดินทาง',
            'แผนการเดินทาง', 'โปรแกรมทริป', 'โปรแกรมเดินทาง', 'itinerary',
        ],
        'place' => [
            'จุดรับ', 'จุดนัด', 'จุดขึ้นรถ', 'ขึ้นรถที่ไหน', 'รอที่ไหน',
            'นัดเจอที่ไหน', 'เจอกันที่ไหน', 'ไปขึ้นรถตรงไหน',
        ],
        'time' => [
            'ขึ้นรถกี่โมง', 'รถออกกี่โมง', 'ออกรถกี่โมง', 'ออกเดินทางกี่โมง',
            'นัดกี่โมง', 'เจอกันกี่โมง', 'ถึงจุดรับกี่โมง',
        ],
        'vehicle' => [
            'ทะเบียนรถ', 'ทะเบียนอะไร', 'รถคันไหน', 'รถทะเบียน', 'รถสีอะไร',
            'นั่งรถอะไร',
        ],
        'contact' => [
            'เบอร์โทร', 'เบอร์ติดต่อ', 'เบอร์คนขับ', 'เบอร์สตาฟ', 'ติดต่อใคร',
            'โทรหาใคร', 'โทรเบอร์ไหน',
        ],
    ];

    /** @var array<string, array<int, string>> */
    private const WEAK = [
        'itinerary' => [
            'ตาราง', 'โปรแกรม', 'แพลน', 'ทำอะไรบ้าง', 'ไปไหนบ้าง',
            'เที่ยวไหนบ้าง', 'ไปที่ไหนบ้าง',
        ],
        'time' => ['กี่โมง'],
        'vehicle' => ['ทะเบียน'],
        'contact' => ['เบอร์'],
    ];

    /** @var array<int, string> */
    private const QUESTION_MARKS = [
        '?', '？', 'ไหม', 'มั้ย', 'มัย', 'หรอ', 'เหรอ', 'อะไร', 'ยังไง',
        'อย่างไร', 'บ้าง', 'กี่', 'ที่ไหน', 'ตรงไหน', 'เมื่อไหร่', 'เมื่อไร',
        'ขอทราบ', 'สอบถาม', 'ขอถาม', 'ใครทราบ',
    ];

    /** @var array<int, string> */
    private const ORDER = ['itinerary', 'place', 'time', 'vehicle', 'contact'];

    public function __construct(
        private TripFactsService $facts,
        private ChatService $chat,
    ) {}

    public static function enabled(): bool
    {
        return SiteSettings::bool('chat_auto_answer_enabled');
    }

    /**
     * ข้อความนี้ถามเรื่องอะไร — null คือไม่ใช่คำถามที่บอทตอบได้ ปล่อยผ่าน
     */
    public function topicFor(?string $body): ?string
    {
        $text = $this->normalize((string) $body);

        if ($text === '') {
            return null;
        }

        foreach (self::ORDER as $topic) {
            if ($this->containsAny($text, self::STRONG[$topic] ?? [])) {
                return $topic;
            }
        }

        if (! $this->looksLikeQuestion($text)) {
            return null;
        }

        foreach (self::ORDER as $topic) {
            if ($this->containsAny($text, self::WEAK[$topic] ?? [])) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * คำตอบที่จะโพสต์ลงห้อง — null เมื่อยังไม่มีข้อมูลจริงพอจะตอบ
     *
     * คำถามเรื่องจุดรับ/เวลา/รถ/เบอร์ ตอบด้วย "สรุปข้อมูลการเดินทาง" ชุดเดียว
     * เพราะเป็นข้อความสำหรับทั้งห้อง จุดรับของแต่ละคนไม่เหมือนกันจึงต้องยกมา
     * ทั้งชุดอยู่แล้ว และการตอบครบรวดเดียวยังดักคำถามอีกสามข้อที่จะตามมาด้วย
     *
     * @return array{kind: string, body: string}|null
     */
    public function answerFor(TripSchedule $schedule, string $topic): ?array
    {
        if ($topic === 'itinerary') {
            $body = $this->facts->itinerarySummaryText($schedule);

            return $body === null
                ? null
                : ['kind' => self::KIND_ITINERARY, 'body' => $body];
        }

        if (! $this->hasRealAnswerFor($schedule, $topic)) {
            return null;
        }

        return [
            'kind' => self::KIND_FACTS,
            'body' => $this->facts->roundSummaryText($schedule),
        ];
    }

    /**
     * รู้คำตอบของข้อที่ถามจริงหรือยัง — ยังไม่รู้แล้วตอบไปก็เท่ากับบอทตอบว่า
     * "ยังไม่รู้" ซึ่งคนถามพิมพ์ถามเพราะอยากรู้ ไม่ได้อยากได้คำปลอบ
     */
    private function hasRealAnswerFor(TripSchedule $schedule, string $topic): bool
    {
        $schedule->loadMissing(['vehicle.driver', 'pickupPoints']);

        return match ($topic) {
            'place', 'time' => $schedule->pickupPoints->isNotEmpty(),
            'vehicle' => trim((string) ($schedule->vehicle?->license_plate ?? '')) !== '',
            'contact' => $this->facts->driver($schedule) !== null
                || collect($this->facts->staff($schedule))
                    ->contains(fn (array $s) => trim((string) ($s['phone'] ?? '')) !== ''),
            default => false,
        };
    }

    /**
     * คีย์กันซ้ำ — วันละครั้งต่อห้องต่อชนิดคำตอบ (unique กับ schedule_id ใน DB)
     */
    public function dedupeKey(string $kind, CarbonImmutable $now): string
    {
        return 'auto_answer_'.$kind.'_'.$now->format('Ymd');
    }

    /**
     * ตอบไปแล้วหรือมีคนตอบแล้ว — เงียบไว้ดีกว่าพูดซ้ำ
     */
    public function alreadyHandled(
        ChatMessage $question,
        string $kind,
        string $key,
    ): bool {
        $room = ChatMessage::where('schedule_id', $question->schedule_id);

        if ((clone $room)->where('system_key', $key)->exists()) {
            return true;
        }

        // ทีมงานตอบไปแล้วหลังคำถามนี้ — คนตอบดีกว่าบอทตอบ
        $answeredByHuman = (clone $room)
            ->whereIn('sender_role', ['staff', 'admin'])
            ->where('id', '>', $question->id)
            ->exists();

        if ($answeredByHuman) {
            return true;
        }

        // กำหนดการเพิ่งลงห้องไปเอง (ข้อความ D-2 หรือฉบับแก้ไข) ไม่ต้องซ้ำ
        return $kind === self::KIND_ITINERARY
            && (clone $room)
                ->where('sender_role', 'system')
                ->where('body', 'like', TripFactsService::ITINERARY_MARK.'%')
                ->where('created_at', '>=', now()->subDay())
                ->exists();
    }

    /**
     * รอบนี้ยังอยู่ในช่วงที่ตอบแล้วมีประโยชน์ไหม
     */
    public function roundIsLive(TripSchedule $schedule): bool
    {
        if ($schedule->status === 'cancelled') {
            return false;
        }

        $end = $schedule->return_date ?? $schedule->departure_date;

        return $end === null || CarbonImmutable::now(TripChatTimelineService::TIMEZONE)->lte(
            CarbonImmutable::parse($end->toDateString(), TripChatTimelineService::TIMEZONE)->endOfDay(),
        );
    }

    public function post(TripSchedule $schedule, string $body, string $key): ChatMessage
    {
        return $this->chat->postSystem($schedule, $body, $key);
    }

    /** ตัดช่องว่างทิ้ง เพราะคนไทยเว้นวรรคกลางคำได้ตามใจ */
    private function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtolower(trim($text)));
    }

    private function looksLikeQuestion(string $text): bool
    {
        return $this->containsAny($text, self::QUESTION_MARKS);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_strpos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
