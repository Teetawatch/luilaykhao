<?php

namespace App\Services\Beam;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ตัวห่อ HTTP ของ Beam Checkout — รู้จักแค่ "ยิง request แล้วแปลผล"
 * ไม่รู้เรื่องการจอง ยอดเงิน หรือ business logic ใดๆ ทั้งสิ้น (นั่นเป็นงานของ
 * BeamPaymentService) เพื่อให้เทสต์ mock ชั้นนี้ชั้นเดียวจบ
 *
 * เอกสาร: https://docs.beamcheckout.com
 * auth เป็น HTTP Basic ด้วย merchantId:apiKey
 */
class BeamClient
{
    /** จำนวนเงินที่ Beam รับเป็น "สตางค์" ไม่ใช่บาท. */
    public const SATANG_PER_BAHT = 100;

    /**
     * ค่าที่ Beam ตอบใน actionRequired — บอกว่า client ต้องทำอะไรต่อ
     */
    public const ACTION_NONE = 'NONE';

    public const ACTION_REDIRECT = 'REDIRECT';

    public const ACTION_ENCODED_IMAGE = 'ENCODED_IMAGE';

    public function enabled(): bool
    {
        return filled(config('payment.beam.merchant_id'))
            && filled(config('payment.beam.api_key'));
    }

    /**
     * สร้าง charge ใหม่
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> body ที่ Beam ตอบกลับ (chargeId, actionRequired, ...)
     *
     * @throws BeamException
     */
    public function createCharge(array $payload): array
    {
        return $this->send('post', '/api/v1/charges', $payload);
    }

    /**
     * อ่านสถานะ charge — ใช้ตอน poll และตอน reconcile ที่ webhook หาย
     *
     * @return array<string, mixed>
     *
     * @throws BeamException
     */
    public function getCharge(string $chargeId): array
    {
        return $this->send('get', '/api/v1/charges/'.urlencode($chargeId));
    }

    /**
     * คืนเงิน (ยังไม่มีที่เรียกในเฟสนี้ — เตรียมไว้ให้ processRefund() มาต่อทีหลัง)
     *
     * @return array<string, mixed>
     *
     * @throws BeamException
     */
    public function createRefund(string $chargeId, int $amountSatang, ?string $reason = null): array
    {
        return $this->send('post', '/api/v1/refunds', array_filter([
            'chargeId' => $chargeId,
            'amount' => $amountSatang,
            'refundReason' => $reason,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     *
     * @throws BeamException
     */
    private function send(string $method, string $path, ?array $payload = null): array
    {
        if (! $this->enabled()) {
            throw new BeamException('ยังไม่ได้ตั้งค่า BEAM_MERCHANT_ID / BEAM_API_KEY');
        }

        try {
            $response = $method === 'get'
                ? $this->request()->get($path)
                : $this->request()->post($path, $payload ?? []);
        } catch (\Throwable $e) {
            // ต่อไม่ติด/timeout — ไม่ใช่ความผิดลูกค้า ให้ฝั่งเรียกตกกลับไปทางโอนเองได้
            Log::error('Beam request failed to send', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new BeamException('ติดต่อระบบชำระเงินไม่ได้: '.$e->getMessage());
        }

        $body = $response->json();

        if (! $response->successful()) {
            Log::error('Beam responded with an error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $body,
            ]);

            throw new BeamException(
                'ระบบชำระเงินตอบกลับผิดพลาด ('.$response->status().')',
                $response->status(),
                is_array($body) ? $body : null,
            );
        }

        if (! is_array($body)) {
            throw new BeamException('ระบบชำระเงินตอบกลับในรูปแบบที่อ่านไม่ได้', $response->status());
        }

        Log::info('Beam request ok', [
            'path' => $path,
            'charge_id' => $body['chargeId'] ?? null,
            'status' => $body['status'] ?? null,
        ]);

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('payment.beam.base_url'), '/'))
            ->withBasicAuth(
                (string) config('payment.beam.merchant_id'),
                (string) config('payment.beam.api_key'),
            )
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(10);
    }
}
