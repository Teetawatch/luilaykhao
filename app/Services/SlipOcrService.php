<?php

namespace App\Services;

use App\Support\MediaDisk;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SlipOcrService
{
    // OCR status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_APPROVED = 'manually_approved';

    public const STATUS_REJECTED = 'rejected';

    // Amount tolerance: ±2 baht (to handle rounding)
    private const AMOUNT_TOLERANCE = 2.0;

    public function verify(string $slipPath, float $expectedAmount): array
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            Log::warning('SlipOcrService: ANTHROPIC_API_KEY not set, skipping OCR');

            return ['status' => self::STATUS_FAILED, 'reason' => 'api_key_missing', 'raw' => null];
        }

        // Read via the private slip disk so OCR works whether the slip lives
        // locally or on R2 (a remote disk has no local ->path()).
        $disk = Storage::disk(MediaDisk::slipDisk());

        if (! $disk->exists($slipPath)) {
            Log::warning("SlipOcrService: slip file not found at {$slipPath}");

            return ['status' => self::STATUS_FAILED, 'reason' => 'file_not_found', 'raw' => null];
        }

        $imageData = base64_encode($disk->get($slipPath));
        $mimeType = $this->detectMimeType($slipPath);

        $prompt = <<<'PROMPT'
        คุณคือระบบตรวจสอบสลิปโอนเงินไทย

        จากรูปสลิปโอนเงินนี้ กรุณาดึงข้อมูลต่อไปนี้และตอบกลับเป็น JSON เท่านั้น (ไม่มีข้อความอื่น):
        {
          "status": "success" หรือ "failed" หรือ "unknown",
          "amount": ตัวเลขยอดเงิน (ทศนิยม 2 ตำแหน่ง) หรือ null ถ้าไม่ชัดเจน,
          "datetime": "YYYY-MM-DD HH:mm:ss" หรือ null,
          "bank": ชื่อธนาคารหรือ null,
          "transaction_id": รหัสอ้างอิงหรือ null
        }

        กฎ:
        - ถ้า slip แสดงคำว่า "สำเร็จ", "สมบูรณ์", "Successful", "Completed" → status = "success"
        - ถ้า slip แสดงคำว่า "ล้มเหลว", "ยกเลิก", "Failed", "Error" → status = "failed"
        - ถ้าไม่ชัดเจน → status = "unknown"
        - ตอบ JSON เท่านั้น ไม่มี markdown code block
        PROMPT;

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 256,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'image',
                                    'source' => [
                                        'type' => 'base64',
                                        'media_type' => $mimeType,
                                        'data' => $imageData,
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $prompt,
                                ],
                            ],
                        ],
                        // Prefill the assistant turn with "{" so the response
                        // is guaranteed to continue as a raw JSON object
                        // (no markdown fences, no prose preamble).
                        [
                            'role' => 'assistant',
                            'content' => '{',
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('SlipOcrService: API error', ['status' => $response->status(), 'body' => $response->body()]);

                return ['status' => self::STATUS_FAILED, 'reason' => 'api_error', 'raw' => null];
            }

            // Re-attach the prefill "{" so the response is a complete JSON object.
            $content = '{'.$response->json('content.0.text', '');
            $parsed = self::extractJson($content);

            if (! is_array($parsed)) {
                Log::warning('SlipOcrService: could not parse JSON response', ['content' => $content]);

                return ['status' => self::STATUS_FAILED, 'reason' => 'parse_error', 'raw' => $content];
            }

            $ocrStatus = $parsed['status'] ?? 'unknown';
            $ocrAmount = isset($parsed['amount']) ? (float) $parsed['amount'] : null;

            // Verify: slip must be "success" AND amount must be within tolerance
            if ($ocrStatus === 'success' && $ocrAmount !== null) {
                $diff = abs($ocrAmount - $expectedAmount);
                if ($diff <= self::AMOUNT_TOLERANCE) {
                    return ['status' => self::STATUS_VERIFIED, 'reason' => 'auto_verified', 'raw' => $parsed];
                }

                Log::info("SlipOcrService: amount mismatch — expected {$expectedAmount}, got {$ocrAmount}");

                return ['status' => self::STATUS_FAILED, 'reason' => 'amount_mismatch', 'raw' => $parsed];
            }

            return ['status' => self::STATUS_FAILED, 'reason' => "slip_status_{$ocrStatus}", 'raw' => $parsed];

        } catch (\Exception $e) {
            Log::error('SlipOcrService: exception', ['message' => $e->getMessage()]);

            return ['status' => self::STATUS_FAILED, 'reason' => 'exception', 'raw' => null];
        }
    }

    /**
     * Extract a JSON object from a model response that may be wrapped in
     * markdown fences, prefixed/suffixed with prose, or contain trailing
     * commas. Returns the decoded array or null when nothing parses.
     */
    public static function extractJson(string $content): ?array
    {
        $text = trim($content);

        if ($text === '') {
            return null;
        }

        // Strip ```json … ``` or ``` … ``` fences.
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/is', $text, $m)) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to the first balanced {...} block in the response.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = substr($text, $start, $end - $start + 1);

        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Last resort: drop trailing commas before } or ] (a common LLM slip).
        $cleaned = preg_replace('/,\s*([}\]])/', '$1', $candidate);
        $decoded = json_decode($cleaned, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function detectMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
