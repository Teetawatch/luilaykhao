<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\TripActivityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ดันการ์ดวันเดินทางบนหน้าจอล็อกให้ตรงกับความจริง "เดี๋ยวนี้"
 *
 * รอบซิงก์ปกติเดินทุกนาที ซึ่งเร็วพอสำหรับ ETA ที่ค่อย ๆ ลด แต่ช้าไปสำหรับ
 * เหตุการณ์ที่คนกำลังยืนดูอยู่ตรงนั้น — สตาฟสแกน QR แล้วการ์ดต้องพลิกเป็น
 * "ขึ้นรถเรียบร้อยแล้ว" ทันที ไม่ใช่อีก 50 วินาที
 */
class SyncTripActivityJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $bookingId) {}

    public function handle(TripActivityService $activities): void
    {
        $booking = Booking::with(['schedule.trip', 'schedule.vehicle', 'pickupPoint'])
            ->find($this->bookingId);

        if (! $booking) {
            return;
        }

        $activities->sync($booking);
    }
}
