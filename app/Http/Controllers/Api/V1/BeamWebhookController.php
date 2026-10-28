<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\BeamPaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Callback จาก Beam — "charge ใบนี้จ่ายแล้ว/ล้มเหลวแล้ว" และ "หน้าชำระเงินใบนี้ถูกจ่ายแล้ว"
 *
 * ไม่มี Sanctum เพราะเกตเวย์เซ็นด้วย token ของเราไม่ได้ ความปลอดภัยจึงอยู่ที่ลายเซ็น
 * HMAC ทั้งหมด: Beam ส่ง base64(HMAC-SHA256(key, raw body)) มาใน X-Beam-Signature
 * โดยที่ key เองก็เป็น base64 ต้อง decode ก่อนใช้ (ผิดข้อนี้แล้วทุกใบจะถูกปฏิเสธ)
 *
 * แยก endpoint จาก PaymentController::webhook() เดิมโดยตั้งใจ — อันนั้นเป็นสเปกของ
 * เราเอง (hex, X-Payment-Signature) คนละรูปแบบกันคนละตัวอักษร
 *
 * ตอบ 2xx ทุกครั้งที่ลายเซ็นผ่าน แม้จะไม่รู้จัก event หรือหา payment ไม่เจอ ไม่งั้น
 * Beam จะ retry ซ้ำอีก 10 ครั้งโดยเปล่าประโยชน์
 */
class BeamWebhookController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, BeamPaymentService $beamPayments): JsonResponse
    {
        $secret = (string) config('payment.beam.webhook_secret');

        if ($secret === '') {
            Log::warning('Beam webhook hit but no BEAM_WEBHOOK_SECRET is configured — rejecting.');

            return $this->error('Webhook not configured', 503);
        }

        if (! $this->signatureIsValid($request, $secret)) {
            Log::warning('Beam webhook rejected: invalid signature', ['ip' => $request->ip()]);

            return $this->error('Invalid signature', 401);
        }

        $event = (string) $request->header('X-Beam-Event', '');
        $payload = $request->all();

        // payment_link.* ส่งตัวลิงก์มา (referenceId อยู่ใน order) ส่วน charge.* ส่ง charge
        $referenceId = $event === 'payment_link.paid'
            ? data_get($payload, 'order.referenceId')
            : ($payload['referenceId'] ?? null);

        Log::info('Beam webhook accepted', [
            'event' => $event,
            'reference_id' => $referenceId,
            'charge_id' => $payload['chargeId'] ?? null,
            'payment_link_id' => $payload['paymentLinkId'] ?? null,
        ]);

        if (! in_array($event, ['charge.succeeded', 'charge.failed', 'payment_link.paid'], true)) {
            // refund.* / transaction.* ยังไม่มีคนรับ — รับทราบไว้ก่อน ไม่ให้ Beam retry
            return $this->success(null, 'Acknowledged');
        }

        $payment = $this->findPayment($event, $payload, $referenceId);

        if (! $payment) {
            Log::warning('Beam webhook references an unknown payment', [
                'reference_id' => $referenceId,
                'source_id' => $payload['sourceId'] ?? null,
            ]);

            return $this->success(null, 'Acknowledged');
        }

        if ($event === 'charge.failed') {
            $beamPayments->markFailed($payment, $payload);
        } else {
            // charge.succeeded หรือ payment_link.paid — มาถึงทั้งคู่ก็ settle ครั้งเดียว
            $beamPayments->settle($payment, $payload);
        }

        return $this->success(null, 'Processed');
    }

    /**
     * หาแถวที่ webhook นี้พูดถึง
     *
     * ใบที่ออกเป็นหน้าชำระเงิน (Payment Link) — charge ที่ Beam สร้างให้บนหน้านั้นอาจไม่ได้
     * พก referenceId ของเรามา จึงหาด้วย sourceId (= id ของลิงก์) เป็นทางสำรอง
     *
     * @param  array<string, mixed>  $payload
     */
    private function findPayment(string $event, array $payload, mixed $referenceId): ?Payment
    {
        if (is_string($referenceId) && $referenceId !== '') {
            $payment = Payment::where('reference_id', $referenceId)->first();

            if ($payment) {
                return $payment;
            }
        }

        $linkId = $event === 'payment_link.paid'
            ? ($payload['paymentLinkId'] ?? null)
            : (strtoupper((string) ($payload['source'] ?? '')) === 'PAYMENT_LINK' ? ($payload['sourceId'] ?? null) : null);

        if (! is_string($linkId) || $linkId === '') {
            return null;
        }

        return Payment::where('provider_link_id', $linkId)->first();
    }

    /**
     * base64(HMAC-SHA256(base64_decode(secret), raw body)) === X-Beam-Signature
     */
    private function signatureIsValid(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Beam-Signature', '');

        if ($signature === '') {
            return false;
        }

        $key = base64_decode($secret, true);

        if ($key === false || $key === '') {
            Log::error('BEAM_WEBHOOK_SECRET is not valid base64 — every webhook will be rejected.');

            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $key, true));

        return hash_equals($expected, $signature);
    }
}
