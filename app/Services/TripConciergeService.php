<?php

namespace App\Services;

use App\Models\Trip;
use App\Support\ThaiDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI ผู้ช่วยวางทริป — ตอบคำถามแบบภาษาคน ("งบ 3000 ไปเหนือ 2 วัน มือใหม่ไหวไหม")
 * แล้วชี้ไปที่ทริปจริงที่เปิดจองอยู่
 *
 * หัวใจอยู่ที่ "ตอบจากแคตตาล็อกเท่านั้น": เราส่งรายการทริปที่เปิดจองอยู่จริงไปกับ
 * system prompt แล้วบังคับให้โมเดลอ้างอิงได้เฉพาะ slug ในรายการนั้น ผลลัพธ์ที่ได้
 * จึงเป็นคำแนะนำที่กดจองต่อได้ ไม่ใช่ทริปที่โมเดลแต่งขึ้นเอง
 */
class TripConciergeService
{
    /** อายุแคชแคตตาล็อก — ทริป/รอบไม่ได้เปลี่ยนรายนาที. */
    private const CATALOG_CACHE_MINUTES = 15;

    /** จำนวนข้อความย้อนหลังที่ส่งกลับไปเป็นบริบท (นับทั้งฝั่งผู้ใช้และผู้ช่วย). */
    private const MAX_HISTORY = 10;

    /**
     * ตอบคำถามหนึ่งข้อ
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, trips: array<int, array<string, mixed>>}
     *
     * @throws \Exception เมื่อยังไม่ได้ตั้งค่า API key หรือเรียกโมเดลไม่สำเร็จ
     */
    public function ask(string $question, array $history = []): array
    {
        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            throw new \Exception('ระบบผู้ช่วยวางทริปยังไม่พร้อมใช้งาน กรุณาติดต่อทีมงาน');
        }

        $catalog = $this->catalog();

        if ($catalog->isEmpty()) {
            throw new \Exception('ตอนนี้ยังไม่มีทริปที่เปิดจอง ลองกลับมาใหม่อีกครั้งนะครับ');
        }

        $answer = $this->askClaude($apiKey, $catalog, $question, $history);

        // กันโมเดลอ้าง slug ที่ไม่มีจริง — แสดงเฉพาะทริปที่ match แคตตาล็อกเท่านั้น
        $bySlug = $catalog->keyBy('slug');
        $trips = collect($answer['trip_slugs'] ?? [])
            ->map(fn ($slug) => $bySlug->get($slug))
            ->filter()
            ->take(4)
            ->values()
            ->all();

        return [
            'reply' => trim($answer['reply'] ?? ''),
            'trips' => $trips,
        ];
    }

    /**
     * ทริปที่เปิดจองอยู่จริง พร้อมข้อมูลเท่าที่ใช้ตัดสินใจ — ไม่ส่งคำบรรยายยาว ๆ
     * เข้าไปเพราะกินโทเคนโดยไม่ช่วยให้แนะนำได้ตรงขึ้น
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function catalog()
    {
        return Cache::remember('concierge-catalog', now()->addMinutes(self::CATALOG_CACHE_MINUTES), function () {
            return Trip::where('status', 'active')
                ->with(['schedules' => function ($q) {
                    $q->where('status', 'open')
                        ->where('departure_date', '>=', now('Asia/Bangkok')->startOfDay())
                        ->orderBy('departure_date');
                }])
                ->get()
                ->filter(fn (Trip $trip) => $trip->schedules->isNotEmpty())
                ->map(function (Trip $trip) {
                    $next = $trip->schedules->first();

                    return [
                        'slug' => $trip->slug,
                        'title' => $trip->title,
                        'location' => $trip->location,
                        'region' => $trip->region,
                        'type' => $trip->type,
                        'difficulty' => $trip->difficulty,
                        'duration_days' => (int) $trip->duration_days,
                        'distance_km' => $trip->distance_km ? (float) $trip->distance_km : null,
                        'elevation_gain_m' => $trip->elevation_gain_m ? (int) $trip->elevation_gain_m : null,
                        'is_women_only' => (bool) $trip->is_women_only,
                        'price_from' => (float) ($trip->schedules
                            ->map(fn ($schedule) => $schedule->effective_price)
                            ->min() ?? $trip->price_per_person),
                        'next_departure' => $next?->departure_date?->toDateString(),
                        'next_departure_label' => $next?->departure_date
                            ? ThaiDate::short($next->departure_date)
                            : null,
                        'open_rounds' => $trip->schedules->count(),
                        'seats_left' => (int) $trip->schedules
                            ->sum(fn ($schedule) => max(0, $schedule->total_seats - $schedule->booked_seats)),
                    ];
                })
                ->values();
        });
    }

    /**
     * เรียกโมเดลหนึ่งครั้ง แล้วคืนผลที่ decode แล้ว
     *
     * ใช้ structured output บังคับรูปแบบคำตอบ เพื่อให้หน้าเว็บแยก "ข้อความ" กับ
     * "ทริปที่แนะนำ" ออกจากกันได้โดยไม่ต้องเดาจากข้อความเปล่า
     *
     * @param  Collection<int, array<string, mixed>>  $catalog
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply?: string, trip_slugs?: array<int, string>}
     */
    private function askClaude(string $apiKey, $catalog, string $question, array $history): array
    {
        $messages = collect($history)
            ->filter(fn ($turn) => in_array($turn['role'] ?? null, ['user', 'assistant'], true))
            ->map(fn ($turn) => ['role' => $turn['role'], 'content' => (string) $turn['content']])
            ->take(-self::MAX_HISTORY)
            ->values()
            ->all();

        $messages[] = ['role' => 'user', 'content' => $question];

        $model = config('services.anthropic.concierge_model');

        $payload = [
            'model' => $model,
            'max_tokens' => 2048,
            // แคตตาล็อกเปลี่ยนไม่บ่อย จึงแคช prefix ไว้ให้คำถามถัด ๆ ไปถูกลง
            'system' => [
                [
                    'type' => 'text',
                    'text' => $this->systemPrompt($catalog),
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            // บังคับรูปแบบคำตอบ เพื่อให้หน้าเว็บแยก "ข้อความ" กับ "ทริปที่แนะนำ"
            // ออกจากกันได้โดยไม่ต้องเดาจากข้อความเปล่า
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'reply' => [
                                'type' => 'string',
                                'description' => 'คำตอบภาษาไทยแบบเป็นกันเอง 2-4 ประโยค',
                            ],
                            'trip_slugs' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'slug ของทริปที่แนะนำ เรียงจากเหมาะที่สุด สูงสุด 4 รายการ',
                            ],
                        ],
                        'required' => ['reply', 'trip_slugs'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'messages' => $messages,
        ];

        // งานนี้คือจับคู่คำถามกับรายการที่ให้ไว้ ไม่ต้องคิดลึก จึงไม่เปิด thinking เลย
        // (ประหยัดโทเคนและตอบไวขึ้น) เหลือแค่บอก effort ต่ำในรุ่นที่รองรับ — รุ่นที่
        // ไม่รองรับจะตอบ 400 ถ้าส่งไป จึงต้องเช็คก่อน
        if ($this->supportsEffort($model)) {
            $payload['output_config']['effort'] = 'low';
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', $payload);
        } catch (\Throwable $e) {
            Log::error('TripConciergeService: request failed', ['message' => $e->getMessage()]);

            throw new \Exception('ตอนนี้ผู้ช่วยตอบไม่ได้ ลองใหม่อีกครั้งในสักครู่');
        }

        if (! $response->successful()) {
            Log::error('TripConciergeService: API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \Exception('ตอนนี้ผู้ช่วยตอบไม่ได้ ลองใหม่อีกครั้งในสักครู่');
        }

        // safety classifier ปฏิเสธคำถาม — ต้องเช็คก่อนอ่าน content เพราะ content ว่าง
        if ($response->json('stop_reason') === 'refusal') {
            throw new \Exception('ขอโทษครับ คำถามนี้ผู้ช่วยตอบให้ไม่ได้ ลองถามเรื่องทริปแทนนะครับ');
        }

        $text = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? '';

        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            Log::warning('TripConciergeService: unparseable response', ['text' => $text]);

            throw new \Exception('ตอนนี้ผู้ช่วยตอบไม่ได้ ลองใหม่อีกครั้งในสักครู่');
        }

        return $parsed;
    }

    /**
     * รุ่นที่รับพารามิเตอร์ effort ได้ — Haiku 4.5 และรุ่นเก่ากว่านั้นจะตอบ 400
     * ถ้าส่งไป จึงต้องคัดออกก่อน ไม่ใช่ส่งเผื่อไว้
     *
     * เก็บเป็น prefix เพราะ config อาจใส่ชื่อเต็มพร้อมวันที่ (เช่น
     * claude-haiku-4-5-20251001) แบบเดียวกับที่ SlipOcrService ใช้
     */
    private function supportsEffort(string $model): bool
    {
        foreach (['claude-opus-4-5', 'claude-opus-4-6', 'claude-opus-4-7', 'claude-opus-4-8',
            'claude-sonnet-4-6', 'claude-sonnet-5', 'claude-fable-5'] as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $catalog
     */
    private function systemPrompt($catalog): string
    {
        $today = now('Asia/Bangkok')->toDateString();
        $json = $catalog->toJson(JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
        คุณคือผู้ช่วยแนะนำทริปของ "ลุยเลเขา" แพลตฟอร์มจองทริปเดินป่าและกิจกรรมกลางแจ้งในไทย
        วันนี้คือ {$today} (เวลาประเทศไทย)

        นี่คือทริปทั้งหมดที่เปิดจองอยู่ตอนนี้ ในรูปแบบ JSON:
        {$json}

        คำอธิบายฟิลด์:
        - difficulty: easy = ง่าย, medium = ปานกลาง, hard = ยาก
        - price_from: ราคาต่อคนที่ถูกที่สุดในรอบที่ยังเปิดจอง (บาท)
        - seats_left: ที่นั่งว่างรวมทุกรอบที่เปิดอยู่
        - distance_km / elevation_gain_m: ระยะทางเดินและความสูงที่ต้องไต่ (null = ยังไม่มีข้อมูล)

        กฎการตอบ:
        - แนะนำได้เฉพาะทริปที่อยู่ในรายการข้างบนเท่านั้น ใส่ slug ลงใน trip_slugs
        - ห้ามแต่งชื่อทริป ราคา วันเดินทาง หรือรายละเอียดที่ไม่มีในข้อมูล
        - ถ้าไม่มีทริปไหนตรงกับที่ผู้ใช้ถาม ให้บอกตรง ๆ ว่าไม่มี แล้วเสนอทริปที่ใกล้เคียงที่สุด
          พร้อมบอกว่าต่างจากที่ขอตรงไหน — trip_slugs ส่งได้ตามปกติ
        - ถ้าผู้ใช้ถามเรื่องที่ไม่เกี่ยวกับทริป ให้ตอบสั้น ๆ ว่าช่วยเรื่องทริปได้ แล้ว trip_slugs เป็น []
        - reply เขียนเป็นภาษาไทยแบบเป็นกันเอง 2-4 ประโยค บอกเหตุผลที่แนะนำทริปนั้น
          (เช่น ระดับความยากเหมาะกับมือใหม่ หรือราคาอยู่ในงบ) ไม่ต้องลิสต์ทริปซ้ำในข้อความ
          เพราะหน้าเว็บจะแสดงการ์ดทริปให้อยู่แล้ว
        - อย่าสัญญาสิ่งที่ระบบทำไม่ได้ เช่น จองให้ เลื่อนวัน หรือต่อรองราคา
        PROMPT;
    }
}
