<?php

namespace App\Observers;

use App\Jobs\AnnounceChatMemberJoinedJob;
use App\Jobs\SendTripBriefsJob;
use App\Jobs\SyncTripActivityJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Services\CustomerIntakeService;
use App\Services\GiftVoucherService;
use App\Services\LoyaltyService;
use Carbon\Carbon;

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
        private GiftVoucherService $giftVoucherService,
    ) {}

    public function created(Booking $booking): void
    {
        // จองมือฝั่งแอดมินสร้างใบจองเป็น confirmed มาตั้งแต่แถวแรก จึงไม่มี
        // การเปลี่ยนสถานะให้จับใน updated()
        if (in_array($booking->status, self::EARNING_STATUSES, true)) {
            $this->loyaltyService->awardForBooking($booking);
            $this->announceInChat($booking);
            $this->maybeSendTripBrief($booking);
        }
    }

    public function updated(Booking $booking): void
    {
        // เช็คอินคือเหตุการณ์ที่ลูกค้ากำลังยืนดูหน้าจอล็อกอยู่ตรงนั้น — การ์ดวันเดินทาง
        // ต้องพลิกเป็น "ขึ้นรถเรียบร้อยแล้ว" เดี๋ยวนั้น ไม่ใช่รอรอบซิงก์นาทีถัดไป
        // (ผ่าน observer เพื่อให้ครอบคลุมทุกทางที่เช็คอินได้: แอปคนขับ, แอดมิน, แก้ใบจอง)
        if ($booking->wasChanged('checked_in')) {
            $this->syncPassengerCheckIn($booking);
        }

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

            // ยอดที่หักจากบัตรของขวัญไว้ตอนจอง ยังไม่ได้แลกเป็นอะไรเลย — คืนเต็มจำนวน
            // (ใบที่ยืนยันแล้วคืนตอนทีมงานบันทึกคืนเงิน ตามนโยบายยกเลิก)
            if ((float) $booking->voucher_amount > 0) {
                $this->giftVoucherService->restoreForBooking(
                    $booking,
                    (float) $booking->voucher_amount,
                    'การจอง '.$booking->booking_ref.' ยกเลิกก่อนชำระเงิน',
                );
            }
        }

        // ยกเลิกแล้วต้องเก็บการ์ดออกจากหน้าจอล็อกด้วย ไม่ใช่ค้างนับถอยหลังไปยัง
        // ทริปที่ไม่มีอยู่แล้ว
        if (in_array($booking->status, self::REVERSING_STATUSES, true)) {
            SyncTripActivityJob::dispatch($booking->id);
        }

        if (in_array($booking->status, self::EARNING_STATUSES, true)) {
            $this->loyaltyService->awardForBooking($booking);
            $this->announceInChat($booking);
            $this->maybeSendTripBrief($booking);

            return;
        }

        // ทริปที่ยกเลิกไม่ได้ไปจริง จึงไม่ควรค้างอยู่ในจำนวนทริปสะสมที่ใช้ตัดสินระดับ
        if (in_array($booking->status, self::REVERSING_STATUSES, true)) {
            $this->loyaltyService->reverseForBooking($booking);
        }
    }

    /**
     * ให้เช็คอินรายคนตามระดับใบจองเสมอ
     *
     * ทางที่เขียน bookings.checked_in ตรง ๆ (แอปสตาฟรุ่นก่อนเช็คอินรายคน, แอดมิน
     * แก้ใบจอง/กดเช็คอินจากหลังบ้าน) ไม่รู้จักรายคน — ใบที่ถูกพลิกเป็นเช็คอินโดยยัง
     * ไม่มีใครขึ้นรถเลย แปลว่าเช็คอินยกใบ จึงนับทุกคนที่ยังรออยู่ ส่วนการถอนเช็คอิน
     * ทั้งใบก็ถอนทุกคน ทางใหม่ (PassengerCheckInService) เขียนรายคนก่อนพลิกใบ
     * จึงไม่ตกเงื่อนไขนี้
     */
    private function syncPassengerCheckIn(Booking $booking): void
    {
        $passengers = BookingPassenger::where('booking_id', $booking->id);

        if (! $booking->checked_in) {
            $passengers->whereNotNull('checked_in_at')->update(['checked_in_at' => null]);

            return;
        }

        if ($passengers->clone()->whereNotNull('checked_in_at')->exists()) {
            return;
        }

        $at = $booking->checked_in_at ?? now();
        $marked = $passengers->clone()->whereNull('not_going_at')->update(['checked_in_at' => $at]);

        // ทุกคนเคยแจ้งไม่ไป แต่สตาฟเช็คอินยกใบ = มีคนมาจริง ข้อมูลที่แจ้งไว้ไม่จริงแล้ว
        if ($marked === 0) {
            $passengers->update(['checked_in_at' => $at, 'not_going_at' => null]);
        }
    }

    /**
     * "จองวันนี้ ไปพรุ่งนี้" ต้องได้ใบเดินทางเดี๋ยวนี้ ไม่ใช่รอรอบส่งประจำวัน
     *
     * งานประจำวิ่ง 18:05 ครั้งเดียว ใบจองที่เข้ามาหลังจากนั้นสำหรับรอบที่ออก
     * พรุ่งนี้ตีสี่ จะต้องรอถึง 18:05 ของพรุ่งนี้ ซึ่งคือหลังรถออกไปแล้วสิบกว่า
     * ชั่วโมง ลูกค้าที่จองกระชั้นคือกลุ่มที่ต้องการใบเดินทางมากที่สุดด้วยซ้ำ
     * เพราะไม่มีเวลาถามอะไรใครแล้ว
     *
     * เฉพาะรอบที่ใกล้ถึงจริง ๆ เท่านั้น จองล่วงหน้าเป็นเดือนยังไม่มีอะไรให้บอก
     * (ยังไม่รู้ด้วยซ้ำว่ารถคันไหน ใครเป็นสตาฟ) ปล่อยให้งานประจำวันจัดการตามคิว
     */
    private function maybeSendTripBrief(Booking $booking): void
    {
        if ($booking->brief_sent_at !== null) {
            return;
        }

        $departureDate = $booking->schedule?->departure_date;

        if (! $departureDate) {
            return;
        }

        $daysAway = Carbon::now('Asia/Bangkok')->startOfDay()->diffInDays(
            Carbon::parse($departureDate->toDateString(), 'Asia/Bangkok')->startOfDay(),
            false,
        );

        if ($daysAway < 0 || $daysAway > SendTripBriefsJob::LEAD_DAYS) {
            return;
        }

        SendTripBriefsJob::dispatch($booking->id);
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
