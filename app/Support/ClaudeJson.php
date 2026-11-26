<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * เรียก Claude แบบ "ขอคำตอบเป็น JSON ตามสคีมา" — รวมไว้ที่เดียวเพราะทั้งผู้ช่วย
 * วางทริปและผู้ช่วยส่วนตัวเรียกในรูปแบบเดียวกันหมด ต่างกันแค่ system prompt
 * กับสคีมา
 *
 * ทุก error ถูกแปลงเป็น \Exception ข้อความไทยที่เอาไปโชว์ลูกค้าได้เลย
 * รายละเอียดจริงลง log ไม่ส่งออกไปหน้าบ้าน
 */
class ClaudeJson
{
    /**
     * ถามหนึ่งครั้ง แล้วคืน JSON ที่ decode แล้ว
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema  JSON Schema ของคำตอบ
     * @return array<string, mixed>
     *
     * @throws \Exception เมื่อเรียกไม่สำเร็จ หรือโมเดลปฏิเสธคำถาม
     */
    public static function ask(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        array $schema,
        string $effort = 'low',
        int $maxTokens = 2048,
        string $context = 'ClaudeJson',
        ?callable $onUsage = null,
    ): array {
        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            // system prompt ยาวและเปลี่ยนไม่บ่อย จึงแคช prefix ไว้ให้คำถามถัด ๆ ไปถูกลง
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $schema,
                ],
            ],
            'messages' => $messages,
        ];

        // รุ่นที่ไม่รองรับ effort จะตอบ 400 ถ้าส่งไป จึงต้องเช็คก่อน ไม่ใช่ส่งเผื่อ
        if (self::supportsEffort($model)) {
            $payload['output_config']['effort'] = $effort;
        }

        try {
            // 429 / 529 (overloaded) / 5xx / เน็ตหลุด มักหายในครู่เดียว — ลองซ้ำอีกครั้ง
            // ก่อนยอมแพ้ (Anthropic ไม่คิดเงินคำขอที่ตอบ error) 400 ไม่ลองซ้ำ
            $response = Http::timeout(60)
                ->retry(2, 800, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())),
                    throw: false)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', $payload);
        } catch (\Throwable $e) {
            Log::error("{$context}: request failed", ['message' => $e->getMessage()]);

            throw new \Exception('ตอนนี้ผู้ช่วยตอบไม่ได้ ลองใหม่อีกครั้งในสักครู่');
        }

        if (! $response->successful()) {
            Log::error("{$context}: API error", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \Exception('ตอนนี้ผู้ช่วยตอบไม่ได้ ลองใหม่อีกครั้งในสักครู่');
        }

        // คำตอบที่สำเร็จคิดเงินเสมอ (รวมที่ถูกปฏิเสธ/อ่าน JSON ไม่ออก) — ให้ผู้เรียก
        // ที่ต้องคุมงบ (ChatTranslationMeter) นับก่อนจะ throw ด้านล่าง
        if ($onUsage !== null && is_array($response->json('usage'))) {
            try {
                $onUsage($response->json('usage'), (string) ($response->json('model') ?: $model));
            } catch (\Throwable $e) {
                Log::warning("{$context}: usage callback failed", ['message' => $e->getMessage()]);
            }
        }

        // safety classifier ปฏิเสธคำถาม — ต้องเช็คก่อนอ่าน content เพราะ content ว่าง
        if ($response->json('stop_reason') === 'refusal') {
            Log::warning("{$context}: refused", ['stop_details' => $response->json('stop_details')]);

            throw new \Exception('ขอโทษครับ คำถามนี้ผู้ช่วยตอบให้ไม่ได้ ลองถามเรื่องทริปแทนนะครับ');
        }

        $text = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? '';

        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            // stop_reason = max_tokens แปลว่าคิด/ตอบยาวจนชนเพดานก่อนปิด JSON
            Log::warning("{$context}: unparseable response", ['stop_reason' => $response->json('stop_reason'), 'text' => $text]);

            throw new \Exception('ตอนนี้ผู้ช่วยตอบไม่ได้ ลองใหม่อีกครั้งในสักครู่');
        }

        return $parsed;
    }

    /**
     * รุ่นที่รับพารามิเตอร์ effort ได้ — Haiku 4.5 และรุ่นเก่ากว่านั้นจะตอบ 400
     * ถ้าส่งไป จึงต้องคัดออกก่อน
     *
     * เทียบเป็น prefix เพราะ config อาจใส่ชื่อเต็มพร้อมวันที่ (เช่น
     * claude-haiku-4-5-20251001)
     */
    public static function supportsEffort(string $model): bool
    {
        foreach ([
            'claude-opus-5', 'claude-opus-4-5', 'claude-opus-4-6', 'claude-opus-4-7', 'claude-opus-4-8',
            'claude-sonnet-4-6', 'claude-sonnet-5', 'claude-fable-5',
            // Haiku 5.x คิดก่อนตอบเป็นค่าเริ่มต้น (effort medium) — ต้องส่ง effort
            // ไปเอง ไม่งั้นงานสั้น ๆ อย่างแปลข้อความเสียค่า thinking โดยไม่จำเป็น
            'claude-haiku-5',
        ] as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
