<?php

namespace App\Services;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * รับเงินก้อนแรกด้วย "สลิป" — ลูกค้าโอนเองแล้วแนบรูปมา
 *
 * ยกออกมาจาก PaymentController::charge() เพราะตอนนี้มีสองประตูที่ต้องให้ผล
 * เหมือนกันเป๊ะ: แอป/เว็บ (API) และหน้าชำระเงินของลิงก์กรอกข้อมูล (/g/{token}/pay)
 * ถ้าปล่อยให้หน้าที่สองเขียนเอง วันที่กติกา "กันกลาง" เปลี่ยน จะมีประตูหนึ่งที่ยัง
 * ยืนยันสลิปยอดไม่ตรงอยู่โดยไม่มีใครรู้
 *
 * ตรรกะว่า "รับเงินแล้วการจองต้องเป็นอย่างไร" ยังอยู่ใน BookingSettlementService
 * ที่นี่เหลือแค่ส่วนที่เป็นของทางสลิปจริง ๆ: ตรวจยอดด้วย OCR แล้วตัดสินใจว่าจะ
 * ยืนยันทันที หรือกันไว้ให้แอดมินตรวจ
 */
class SlipChargeService
{
    public function __construct(
        private readonly BookingSettlementService $settlement,
        private readonly SlipOcrService $slipOcr,
    ) {}

    /**
     * @param  array{
     *     payment_method?: string,
     *     slip?: UploadedFile|null,
     *     transfer_datetime?: mixed,
     *     installment_count?: int|null,
     * }  $opts
     * @return array{status: 'confirmed'|'pending_review', booking: Booking, amount: float}
     *
     * @throws PaymentNotAvailableException เมื่อรอบนี้จ่ายแบบที่เลือกไม่ได้
     */
    public function charge(Booking $booking, string $paymentType, array $opts = []): array
    {
        $paymentMethod = $opts['payment_method'] ?? 'promptpay';
        $installmentCount = $opts['installment_count'] ?? null;

        // ยอดที่ต้องโอนตอนนี้ + ตรวจว่ารอบนี้จ่ายแบบนี้ได้จริงไหม — ถามก่อนเก็บรูป
        // จ่ายแบบนี้ไม่ได้ก็ไม่ต้องมีไฟล์สลิปค้างอยู่ในดิสก์โดยไม่มีใบจองไหนอ้างถึง
        $dueNow = $this->settlement->quote($booking, $paymentType, ['installment_count' => $installmentCount]);

        $slip = $opts['slip'] ?? null;
        $slipPath = $slip instanceof UploadedFile
            ? $slip->store('slips/'.date('Y/m'), MediaDisk::slipDisk())
            : null;

        $paymentRef = match ($paymentType) {
            'installment' => 'PAY-INST-'.strtoupper(uniqid()),
            'split' => 'PAY-SPLIT-'.strtoupper(uniqid()),
            'deposit' => 'PAY-DEP-'.strtoupper(uniqid()),
            default => 'PAY-'.strtoupper(uniqid()),
        };

        $recordOpts = [
            'installment_count' => $installmentCount,
            'payment_method' => $paymentMethod,
            'payment_ref' => $paymentRef,
            'slip_path' => $slipPath,
            'transfer_datetime' => $opts['transfer_datetime'] ?? null,
            'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
        ];

        // ตรวจยอดก่อน — ยอดไม่ตรงให้ค้างรอแอดมิน ไม่ยืนยันที่นั่ง
        $review = $this->needsReview($slipPath, $dueNow);

        DB::transaction(function () use ($booking, $paymentType, $recordOpts, $review, $paymentMethod, $paymentRef, $dueNow) {
            $this->settlement->record($booking, $paymentType, $recordOpts);

            if (! $review['hold']) {
                $this->settlement->confirm($booking, $paymentType, $paymentMethod, $paymentRef, $dueNow);
            }
        });

        if ($review['hold']) {
            $this->holdForReview($booking, $review['raw']);

            return ['status' => 'pending_review', 'booking' => $booking->fresh(), 'amount' => $dueNow];
        }

        if ($slipPath) {
            // งวดผ่อนตรวจสลิปที่ระดับ "งวด" ไม่ใช่ระดับการจอง
            if ($paymentType === 'installment') {
                $firstInstallment = $booking->fresh()->installmentPayments()->where('installment_no', 1)->first();
                if ($firstInstallment) {
                    VerifySlipJob::dispatch('installment', $firstInstallment->id, $slipPath, $dueNow);
                }
            } else {
                VerifySlipJob::dispatch('booking', $booking->id, $slipPath, $dueNow);
            }
        }

        $this->settlement->announce($booking, $paymentType);

        return ['status' => 'confirmed', 'booking' => $booking->fresh(), 'amount' => $dueNow];
    }

    /**
     * "กันกลาง": ตรวจสลิปแบบซิงโครนัสเพื่อตัดสินใจว่าต้องส่งให้แอดมินตรวจก่อนไหม
     * คืน hold=true เฉพาะเมื่อ OCR อ่านสลิปได้จริงแล้วพบว่า "ยอดไม่ตรง" หรือสลิปเป็น
     * รายการที่ล้มเหลว เท่านั้น กรณีอ่านไม่ได้/ไม่ชัดเจน (เช่น ไม่มี API key, timeout)
     * คืน hold=false เพื่อคงพฤติกรรมเดิม — ยืนยันทันทีแล้ว flag ให้แอดมินผ่าน VerifySlipJob
     *
     * @return array{hold: bool, raw: array|null}
     */
    private function needsReview(?string $slipPath, float $expectedAmount): array
    {
        if (! $slipPath) {
            return ['hold' => false, 'raw' => null];
        }

        $result = $this->slipOcr->verify($slipPath, $expectedAmount);
        $hold = in_array($result['reason'] ?? '', ['amount_mismatch', 'slip_status_failed'], true);

        return ['hold' => $hold, 'raw' => $result['raw'] ?? null];
    }

    /**
     * ค้าง booking ไว้ให้แอดมินตรวจสอบ — สถานะยังเป็น pending (ที่นั่งยังถูก hold และ
     * timer ยกเลิกอัตโนมัติจะข้ามให้เพราะ slip_ocr_status ถูกตั้งค่าแล้ว) ไม่ยืนยันการจอง
     * และไม่ส่งอีเมล/SMS "ยืนยันแล้ว" — แจ้งแอดมินให้ตรวจ และแจ้งลูกค้าว่ากำลังตรวจสอบ
     */
    private function holdForReview(Booking $booking, ?array $ocrRaw): void
    {
        $booking->update([
            'slip_ocr_status' => SlipOcrService::STATUS_FAILED,
            'slip_ocr_result' => $ocrRaw,
        ]);

        $this->notifyReviewAdmins($booking->booking_ref, 'amount_mismatch');

        SmartNotification::send(
            $booking->user_id,
            'payment_under_review',
            'กำลังตรวจสอบการชำระเงิน',
            "ได้รับสลิปของเลขการจอง {$booking->booking_ref} แล้ว ทีมงานกำลังตรวจสอบยอดโอนและจะยืนยันการจองให้โดยเร็ว",
            ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
        );
    }

    private function notifyReviewAdmins(string $bookingRef, string $reason): void
    {
        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($bookingRef, $reason) {
                SmartNotification::send(
                    $admin->id,
                    'slip_ocr_failed',
                    'สลิปต้องตรวจสอบ',
                    "ยอดโอนไม่ตรงอัตโนมัติ: {$bookingRef} (สาเหตุ: {$reason}) กรุณาตรวจสอบและอนุมัติด้วยตนเอง",
                    ['booking_ref' => $bookingRef, 'route' => 'admin.bookings'],
                );
            });
        } catch (\Exception $e) {
            Log::warning('notifySlipReviewAdmins failed — '.$e->getMessage());
        }
    }
}
