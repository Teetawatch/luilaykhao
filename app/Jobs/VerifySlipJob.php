<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SmartNotification;
use App\Models\User;
use App\Services\SlipOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifySlipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        private readonly string $type,         // 'booking' | 'balance' | 'installment'
        private readonly int    $modelId,      // Booking.id or InstallmentPayment.id
        private readonly string $slipPath,
        private readonly float  $expectedAmount,
    ) {}

    public function handle(SlipOcrService $ocrService): void
    {
        $result = $ocrService->verify($this->slipPath, $this->expectedAmount);

        Log::info('VerifySlipJob result', [
            'type'           => $this->type,
            'model_id'       => $this->modelId,
            'status'         => $result['status'],
            'reason'         => $result['reason'] ?? null,
        ]);

        match ($this->type) {
            'booking'     => $this->saveToBooking($result, 'slip_ocr_status', 'slip_ocr_result'),
            'balance'     => $this->saveToBooking($result, 'balance_slip_ocr_status', 'balance_slip_ocr_result'),
            'installment' => $this->saveToInstallment($result),
        };
    }

    private function saveToBooking(array $result, string $statusCol, string $resultCol): void
    {
        $booking = Booking::find($this->modelId);
        if (! $booking) return;

        $booking->update([
            $statusCol => $result['status'],
            $resultCol => $result['raw'],
        ]);

        if ($result['status'] === SlipOcrService::STATUS_FAILED) {
            $this->notifyAdmins($booking->booking_ref, $result['reason'] ?? 'unknown');
        }
    }

    private function saveToInstallment(array $result): void
    {
        $installment = InstallmentPayment::with('booking')->find($this->modelId);
        if (! $installment) return;

        $installment->update([
            'slip_ocr_status' => $result['status'],
            'slip_ocr_result' => $result['raw'],
        ]);

        if ($result['status'] === SlipOcrService::STATUS_FAILED) {
            $bookingRef = $installment->booking?->booking_ref ?? "installment#{$this->modelId}";
            $this->notifyAdmins($bookingRef, $result['reason'] ?? 'unknown', $installment->installment_no);
        }
    }

    private function notifyAdmins(string $bookingRef, string $reason, ?int $installmentNo = null): void
    {
        $label = $installmentNo ? "งวดที่ {$installmentNo} ของ " : '';
        $title = 'สลิปต้องตรวจสอบ';
        $body  = "OCR ไม่ผ่านอัตโนมัติ: {$label}{$bookingRef} (สาเหตุ: {$reason}) กรุณาตรวจสอบและอนุมัติด้วยตนเอง";

        try {
            User::role(['admin', 'operator'])->each(function (User $admin) use ($title, $body, $bookingRef) {
                SmartNotification::send(
                    $admin->id,
                    'slip_ocr_failed',
                    $title,
                    $body,
                    ['booking_ref' => $bookingRef, 'route' => 'admin.bookings'],
                );
            });
        } catch (\Exception $e) {
            Log::warning('VerifySlipJob: could not notify admins — ' . $e->getMessage());
        }
    }
}
