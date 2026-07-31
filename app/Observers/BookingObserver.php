<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\LoyaltyService;

/**
 * บันทึกการจองเข้าบัญชีสมาชิกทุกครั้งที่มัน "กลายเป็นการจองที่ยืนยันแล้ว"
 *
 * เดิมการให้แต้มแขวนอยู่กับ BookingService::confirmBooking() ทางเดียว ซึ่ง
 * ครอบคลุมแค่ตอนลูกค้าจ่ายเองกับตอนแอดมินอนุมัติสลิป ส่วนอีกสามทางที่แอดมิน
 * ใช้จริง (กดเปลี่ยนสถานะเป็นยืนยัน, แก้ใบจองแล้วเปลี่ยนสถานะ, คีย์จองมือแบบ
 * ยืนยันทันที) เขียน status ลงตารางตรง ๆ ลูกค้ากลุ่มขาประจำที่โทรมาจองจึงไม่
 * เคยได้แต้มและไม่เคยได้ระดับสมาชิกเลย
 *
 * ผูกกับ event ของโมเดลแทน เพื่อให้ทุกทาง — รวมทั้งทางที่ยังไม่ได้เขียน — ผ่าน
 * จุดเดียวกัน การให้แต้มเป็น idempotent อยู่แล้ว เรียกซ้ำจึงไม่บวกเบิ้ล
 *
 * ข้อจำกัดที่ต้องรู้: mass update (`Booking::where(...)->update(...)`) ไม่ยิง
 * event ของโมเดล ถ้าจะเพิ่มโค้ดแบบนั้นต้องเรียก LoyaltyService เองด้วย
 */
class BookingObserver
{
    /** สถานะที่ถือว่า "ได้เดินทางกับเราแล้ว" — นับทริปและให้แต้ม. */
    private const EARNING_STATUSES = ['confirmed', 'completed'];

    /** สถานะที่แปลว่าไม่ได้ไป — ต้องถอนทริปและแต้มคืน. */
    private const REVERSING_STATUSES = ['cancelled', 'refunded'];

    public function __construct(private LoyaltyService $loyaltyService) {}

    public function created(Booking $booking): void
    {
        // จองมือฝั่งแอดมินสร้างใบจองเป็น confirmed มาตั้งแต่แถวแรก จึงไม่มี
        // การเปลี่ยนสถานะให้จับใน updated()
        if (in_array($booking->status, self::EARNING_STATUSES, true)) {
            $this->loyaltyService->awardForBooking($booking);
        }
    }

    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        if (in_array($booking->status, self::EARNING_STATUSES, true)) {
            $this->loyaltyService->awardForBooking($booking);

            return;
        }

        // ทริปที่ยกเลิกไม่ได้ไปจริง จึงไม่ควรค้างอยู่ในจำนวนทริปสะสมที่ใช้ตัดสินระดับ
        if (in_array($booking->status, self::REVERSING_STATUSES, true)) {
            $this->loyaltyService->reverseForBooking($booking);
        }
    }
}
