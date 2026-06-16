<?php

namespace App\Services;

use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
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
        // Read via the private slip disk so OCR works whether the slip lives
        // locally or on R2 (a remote disk has no local ->path()).
        $disk = Storage::disk(MediaDisk::slipDisk());

        if (! $disk->exists($slipPath)) {
            Log::warning("SlipOcrService: slip file not found at {$slipPath}");

            return ['status' => self::STATUS_FAILED, 'reason' => 'file_not_found', 'raw' => null];
        }

        $parsed = $this->runOcr(base64_encode($disk->get($slipPath)), $this->detectMimeType($slipPath));

        if (! is_array($parsed)) {
            return ['status' => self::STATUS_FAILED, 'reason' => 'ocr_failed', 'raw' => null];
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
    }

    /**
     * Lightweight, synchronous read of a freshly uploaded slip — used to
     * pre-fill the transfer date/time on the payment page before submission.
     * Returns normalized parts (Gregorian date), or null when OCR fails.
     *
     * @return array{status: string, amount: float|null, date: string|null, time: string|null, datetime: string|null, bank: string|null}|null
     */
    public function scan(UploadedFile $file): ?array
    {
        $imageData = base64_encode((string) file_get_contents($file->getRealPath()));
        $mimeType = $this->detectMimeType('slip.'.strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'jpg')));

        $parsed = $this->runOcr($imageData, $mimeType);

        if (! is_array($parsed)) {
            return null;
        }

        [$date, $time] = $this->splitDatetime($parsed['datetime'] ?? null);

        return [
            'status' => $parsed['status'] ?? 'unknown',
            'amount' => isset($parsed['amount']) ? (float) $parsed['amount'] : null,
            'date' => $date,
            'time' => $time,
            'datetime' => $parsed['datetime'] ?? null,
            'bank' => $parsed['bank'] ?? null,
        ];
    }

    /**
     * Call Claude vision once and return the decoded JSON fields, or null on
     * any failure (missing key, API error, unparseable response).
     */
    private function runOcr(string $base64Image, string $mimeType): ?array
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            Log::warning('SlipOcrService: ANTHROPIC_API_KEY not set, skipping OCR');

            return null;
        }

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
        - datetime: ใช้ปฏิทินสากล (ค.ศ.) เสมอ ถ้าสลิปเป็นปี พ.ศ. ให้ลบ 543 ก่อน และแปลงชื่อเดือนภาษาไทย (เช่น ม.ค., ก.พ.) เป็นตัวเลข ใช้เวลาตามที่แสดงในสลิป (เวลาประเทศไทย)
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
                                        'data' => $base64Image,
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

                return null;
            }

            // Re-attach the prefill "{" so the response is a complete JSON object.
            $content = '{'.$response->json('content.0.text', '');
            $parsed = self::extractJson($content);

            if (! is_array($parsed)) {
                Log::warning('SlipOcrService: could not parse JSON response', ['content' => $content]);

                return null;
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('SlipOcrService: exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Split an OCR "datetime" string into a Gregorian date (Y-m-d) and time
     * (H:i), tolerating Buddhist-era years and partial/garbled output.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function splitDatetime(?string $datetime): array
    {
        if (! is_string($datetime) || trim($datetime) === '') {
            return [null, null];
        }

        if (! preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?/', $datetime, $m)) {
            return [null, null];
        }

        $year = (int) $m[1];
        if ($year > 2400) {
            $year -= 543; // Buddhist Era → Gregorian, in case the model echoes พ.ศ.
        }
        $month = (int) $m[2];
        $day = (int) $m[3];

        if (! checkdate($month, $day, $year)) {
            return [null, null];
        }

        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

        $time = null;
        if (isset($m[4], $m[5])) {
            $hour = (int) $m[4];
            $minute = (int) $m[5];
            if ($hour <= 23 && $minute <= 59) {
                $time = sprintf('%02d:%02d', $hour, $minute);
            }
        }

        return [$date, $time];
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
