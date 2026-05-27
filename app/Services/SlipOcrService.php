<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SlipOcrService
{
    // OCR status constants
    public const STATUS_PENDING   = 'pending';
    public const STATUS_VERIFIED  = 'verified';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_APPROVED  = 'manually_approved';
    public const STATUS_REJECTED  = 'rejected';

    // Amount tolerance: ±2 baht (to handle rounding)
    private const AMOUNT_TOLERANCE = 2.0;

    public function verify(string $slipPath, float $expectedAmount): array
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            Log::warning('SlipOcrService: ANTHROPIC_API_KEY not set, skipping OCR');
            return ['status' => self::STATUS_FAILED, 'reason' => 'api_key_missing', 'raw' => null];
        }

        $absolutePath = Storage::disk('public')->path($slipPath);

        if (! file_exists($absolutePath)) {
            Log::warning("SlipOcrService: slip file not found at {$absolutePath}");
            return ['status' => self::STATUS_FAILED, 'reason' => 'file_not_found', 'raw' => null];
        }

        $imageData = base64_encode(file_get_contents($absolutePath));
        $mimeType  = $this->detectMimeType($absolutePath);

        $prompt = <<<PROMPT
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
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 256,
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => [
                                [
                                    'type'   => 'image',
                                    'source' => [
                                        'type'       => 'base64',
                                        'media_type' => $mimeType,
                                        'data'       => $imageData,
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $prompt,
                                ],
                            ],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('SlipOcrService: API error', ['status' => $response->status(), 'body' => $response->body()]);
                return ['status' => self::STATUS_FAILED, 'reason' => 'api_error', 'raw' => null];
            }

            $content = $response->json('content.0.text', '');
            $parsed  = json_decode(trim($content), true);

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

    private function detectMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/jpeg',
        };
    }
}
