<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Beam\BeamException;
use App\Services\BeamPaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ตาข่ายรับ webhook ที่หายไป
 *
 * Beam retry 10 ครั้งก็จริง แต่ถ้าเซิร์ฟเวอร์เราล่มยาว หรือ URL ผิดอยู่ช่วงหนึ่ง
 * เงินจะเข้าไปแล้วโดยที่การจองยัง pending และหมดเวลาถูกยกเลิกทิ้ง — ลูกค้าเสียเงิน
 * แล้วไม่ได้ที่นั่ง เป็นความเสียหายที่ยอมให้เกิดไม่ได้ จึงต้องมีฝั่งเราไล่ถามเองด้วย
 *
 * ไล่เฉพาะแถวที่ค้างเกิน GRACE_MINUTES เพื่อไม่ไปแย่งกับ webhook ที่กำลังจะมาถึง
 */
class ReconcileBeamChargesJob implements ShouldQueue
{
    use Queueable;

    /** รอนานแค่ไหนก่อนจะเริ่มสงสัยว่า webhook ไม่มาแล้ว. */
    public const GRACE_MINUTES = 10;

    /** ไล่ย้อนหลังไม่เกินกี่ชั่วโมง — เก่ากว่านี้ถือว่าจบไปแล้ว ไม่ต้องถามซ้ำทุกนาที. */
    public const LOOKBACK_HOURS = 48;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(BeamPaymentService $beamPayments): void
    {
        if (! $beamPayments->enabled()) {
            return;
        }

        $stale = Payment::where('status', Payment::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->where('created_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->orderBy('id')
            ->limit(200)
            ->get();

        $settled = 0;

        foreach ($stale as $payment) {
            try {
                if ($beamPayments->syncFromProvider($payment) === Payment::STATUS_SUCCEEDED) {
                    $settled++;
                    Log::warning('Beam reconcile settled a payment the webhook never delivered', [
                        'payment_id' => $payment->id,
                        'booking_id' => $payment->booking_id,
                    ]);
                }
            } catch (BeamException $e) {
                // ถามไม่ได้รอบนี้ก็ไม่เป็นไร รอบหน้าอีก 5 นาทีค่อยถามใหม่
                Log::warning('Beam reconcile could not read a charge', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($stale->isNotEmpty()) {
            Log::info('ReconcileBeamChargesJob completed', [
                'checked' => $stale->count(),
                'settled' => $settled,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ReconcileBeamChargesJob failed', ['error' => $exception->getMessage()]);
    }
}
