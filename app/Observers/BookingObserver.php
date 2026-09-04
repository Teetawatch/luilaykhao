<?php

namespace App\Observers;

use App\Jobs\AnnounceChatMemberJoinedJob;
use App\Jobs\SyncTripActivityJob;
use App\Models\Booking;
use App\Services\CustomerIntakeService;
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
    private const EARNING_STATUSES = LoyaltyService::EARNING_STATUSES;

    /** สถานะที่แปลว่าไม่ได้ไป — ต้องถอนทริปและแต้มคืน. */
    private const REVERSING_STATUSES = ['cancelled', 'refunded'];

    public function __construct(
        private LoyaltyService $loyaltyService,
        private CustomerIntakeService $intakeService,
    ) {}

    public function created(Booking $booking): void
    {
        // จองมือฝั่งแอดมินสร้างใบจองเป็น confirmed มาตั้งแต่แถวแรก จึงไม่มี
        // การเปลี่ยนสถานะให้จับใน updated()
        if (in_array($booking->status, self::EARNING_STATUSES, true)) {
            $this->loyaltyService->awardForBooking($booking);
            $this->announceInChat($booking);
        }
    }

    public function updated(Booking $booking): void
    {
        // เช็คอินคือเหตุการณ์ที่ลูกค้ากำลังยืนดูหน้าจอล็อกอยู่ตรงนั้น — การ์ดวันเดินทาง
        // ต้องพลิกเป็น "ขึ้นรถเรียบร้อยแล้ว" เดี๋ยวนั้น ไม่ใช่รอรอบซิงก์นาทีถัดไป
        // (ผ่าน observer เพื่อให้ครอบคลุมทุกทางที่เช็คอินได้: แอปคนขับ, แอดมิน, แก้ใบจอง)
        if ($booking->wasChanged('checked_in') && $booking->checked_in) {
            SyncTripActivityJob::dispatch($booking->id);
        }

        // ใบจองเปลี่ยนมือ (แอดมินโอนใบจอง / ผู้รับกดรับของขวัญ) — แต้มและจำนวน
        // ทริปสะสมผูกกับใบจอง ไม่ใช่บัญชีที่กดจองครั้งแรก จึงต้องย้ายตามไปด้วย
        // เช็คก่อนสถานะ เพราะการโอนใบจองไม่แตะ status เลย
        if ($booking->wasChanged('user_id')) {
            $this->loyaltyService->transferForBooking($booking);
        }

        if (! $booking->wasChanged('status')) {
            return;
        }

        // ตายไปทั้งที่ยังไม่เคยยืนยัน = ลูกค้าไม่ได้จ่าย (สแกนไม่ทันบ้าง เปลี่ยนใจบ้าง)
        // ข้อมูลที่ทีมงานดึงจากลิงก์มาเปิดใบนี้จึงยังไม่ได้ถูกใช้จริง ต้องกลับไปรอ
        // ให้ดึงไปจองใหม่ได้ ไม่ใช่ค้างอยู่ในหมวด "จองแล้ว" ตลอดไป
        if (in_array($booking->status, self::REVERSING_STATUSES, true)
            && $booking->getOriginal('status') === 'pending') {
            $this->intakeService->reopenForFailedBooking($booking);
        }

        // ยกเลิกแล้วต้องเก็บการ์ดออกจากหน้าจอล็อกด้วย ไม่ใช่ค้างนับถอยหลังไปยัง
        // ทริปที่ไม่มีอยู่แล้ว
        if (in_array($booking->status, self::REVERSING_STATUSES, true)) {
            SyncTripActivityJob::dispatch($booking->id);
        }

        if (in_array($booking->status, self::EARNING_STATUSES, true)) {
            $this->loyaltyService->awardForBooking($booking);
            $this->announceInChat($booking);

            return;
        }

        // ทริปที่ยกเลิกไม่ได้ไปจริง จึงไม่ควรค้างอยู่ในจำนวนทริปสะสมที่ใช้ตัดสินระดับ
        if (in_array($booking->status, self::REVERSING_STATUSES, true)) {
            $this->loyaltyService->reverseForBooking($booking);
        }
    }

    /**
     * ประกาศในห้องแชทของรอบว่ามีเพื่อนร่วมทริปคนใหม่ — ทำหลัง commit เสมอ และ
     * กันซ้ำด้วย system_key ในฝั่งบริการ จึงเรียกจากทุกทางที่ยืนยันการจองได้
     */
    private function announceInChat(Booking $booking): void
    {
        AnnounceChatMemberJoinedJob::dispatch($booking->id);
    }
}
