<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\PermitSignatureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * แจ้งคนจองว่าทริปนี้ต้องเซ็นแบบฟอร์มอุทยาน — ยิงจาก BookingObserver ตอนยืนยัน
 * การจอง (ทุกทาง: จ่ายเอง อนุมัติสลิป แอดมินคีย์) กติกาทั้งหมดอยู่ที่
 * PermitSignatureService::sendFirstRequest() ซึ่งกันส่งซ้ำเอง
 */
class RequestPermitSignatureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $bookingId)
    {
        $this->afterCommit();
    }

    public function handle(PermitSignatureService $signatures): void
    {
        $booking = Booking::find($this->bookingId);

        if ($booking) {
            $signatures->sendFirstRequest($booking);
        }
    }
}
