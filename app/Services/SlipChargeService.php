<?php

namespace App\Services;

use App\Jobs\NotifySlipReviewJob;
use App\Models\Booking;
use App\Support\MediaDisk;
use App\Support\SlipStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * รับเงินก้อนแรกด้วย "สลิป" — ลูกค้าโอนเองแล้วแนบรูปมา
 *
 * ยกออกมาจาก PaymentController::charge() เพราะตอนนี้มีสองประตูที่ต้องให้ผล
 * เหมือนกันเป๊ะ: แอป/เว็บ (API) และหน้าชำระเงินของลิงก์กรอกข้อมูล (/g/{token}/pay)
 * ถ้าปล่อยให้หน้าที่สองเขียนเอง วันที่กติกา "กันกลาง" เปลี่ยน จะมีประตูหนึ่งที่ยัง
 * ยืนยันสลิปยอดไม่ตรงอยู่โดยไม่มีใครรู้
 *
 * ตรรกะว่า "รับเงินแล้วการจองต้องเป็นอย่างไร" ยังอยู่ใน BookingSettlementService
 * ที่นี่เหลือแค่ส่วนที่เป็นของทางสลิปจริง ๆ: เก็บรูป ยืนยันที่นั่ง แล้วแจ้งทีมงาน
 * ให้ตรวจยอดตามหลัง (ไม่มี OCR แล้วตั้งแต่ 2026-11-26)
 */
class SlipChargeService
{
    public function __construct(
        private readonly BookingSettlementService $settlement,
    ) {}

    /**
     * @param  array{
     *     payment_method?: string,
     *     slip?: UploadedFile|null,
     *     transfer_datetime?: mixed,
     *     installment_count?: int|null,
     * }  $opts
     * @return array{status: 'confirmed', booking: Booking, amount: float}
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
            'slip_ocr_status' => $slipPath ? SlipStatus::PENDING : null,
        ];

        DB::transaction(function () use ($booking, $paymentType, $recordOpts, $paymentMethod, $paymentRef, $dueNow) {
            $this->settlement->record($booking, $paymentType, $recordOpts);
            $this->settlement->confirm($booking, $paymentType, $paymentMethod, $paymentRef, $dueNow);
        });

        // ไม่มี OCR แล้ว — ยืนยันที่นั่งทันที (แบบเดียวกับตอน OCR อ่านสลิปไม่ออก) แล้ว
        // ให้ทีมงานตรวจยอดตามหลัง ผ่อนจ่ายตรวจที่ระดับงวดแรก
        if ($slipPath) {
            NotifySlipReviewJob::dispatch(
                $paymentType === 'installment' ? "งวดที่ 1 ของ {$booking->booking_ref}" : $booking->booking_ref,
                $booking->booking_ref,
            );
        }

        $this->settlement->announce($booking, $paymentType);

        return ['status' => 'confirmed', 'booking' => $booking->fresh(), 'amount' => $dueNow];
    }
}
