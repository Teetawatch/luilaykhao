<?php

namespace App\Jobs;

use App\Models\SmartNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * มีสลิปโอนเงินใหม่รอทีมงานตรวจยอด — แจ้งแอดมิน/โอเปอเรเตอร์ทุกคน
 *
 * แทน VerifySlipJob เดิม (OCR) ซึ่งแจ้งเฉพาะสลิปที่อ่านแล้วไม่ผ่าน ตอนนี้ไม่มี
 * อะไรตรวจให้อัตโนมัติ ทุกสลิปจึงต้องมีคนดู type ยังเป็น slip_ocr_failed เพื่อให้
 * แอปของแอดมินเปิดหน้าการจองได้เหมือนเดิม
 */
class NotifySlipReviewJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  string  $label  เช่น "LLK-20261126-AB12" หรือ "งวดที่ 2 ของ LLK-…"
     */
    public function __construct(
        public string $label,
        public ?string $bookingRef = null,
    ) {}

    public function handle(): void
    {
        try {
            // whereHas แทน role() — role() โยน exception ถ้ายังไม่ได้สร้าง role ใดไว้
            User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'operator']))->each(function (User $admin) {
                SmartNotification::send(
                    $admin->id,
                    'slip_ocr_failed',
                    'สลิปรอตรวจสอบ',
                    "แนบสลิปแล้ว: {$this->label} กรุณาตรวจยอดโอนและอนุมัติ",
                    ['booking_ref' => $this->bookingRef ?? $this->label, 'route' => 'admin.bookings'],
                );
            });
        } catch (\Throwable $e) {
            Log::warning('NotifySlipReviewJob failed — '.$e->getMessage());
        }
    }
}
