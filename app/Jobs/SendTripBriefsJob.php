<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\MailService;
use App\Services\SmsService;
use App\Services\TripBriefService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ส่ง "ใบเดินทาง" ให้ลูกค้าก่อนถึงวันเดินทาง
 *
 * ปัญหาที่งานนี้แก้: ลูกค้าที่ทีมงานเปิดใบจองแทนให้ทางโทรศัพท์/LINE จำนวนมาก
 * ไม่ได้โหลดแอป ข้อมูลรถ จุดขึ้นรถ กำหนดการ และเบอร์ทีมงานทั้งหมดจึงไปไม่ถึงเขา
 * เลย — push ส่งไปไม่มีเครื่องรับ อีเมลบางคนก็ไม่มี เหลือ SMS ที่ยัดเนื้อหาไม่ได้
 *
 * ทางออกคือส่ง *ลิงก์เดียว* (หน้า /t/{token}) ผ่านช่องที่ลูกค้ามีจริง:
 *   - มีอีเมลที่ส่งถึงคนจริงได้ → อีเมลฉบับเต็ม + ลิงก์ท้ายอีเมล
 *   - ไม่มี → SMS หนึ่งข้อความพร้อมลิงก์
 *
 * จังหวะการส่ง (เวลาไทย):
 *   D-2 18:00  ฉบับแรก — เผื่อเวลาให้ลูกค้าทักกลับมาถามถ้ามีอะไรไม่ตรง
 *   D-1 18:00  ฉบับแรก สำหรับรอบที่เมื่อวานข้อมูลยังไม่พร้อม
 *   ทุกวันหลังจากนั้น  ฉบับ "อัปเดต" เมื่อข้อมูลที่ส่งไปแล้วเปลี่ยน (เปลี่ยนรถ
 *   เปลี่ยนสตาฟ ขยับกำหนดการ) เทียบด้วยลายนิ้วมือ ไม่ใช่ส่งซ้ำทุกวัน
 *
 * ใบเดินทางที่ออกไปตอนแอดมินยังไม่ได้กรอกอะไรเลย แย่กว่าไม่ส่ง — ลูกค้าจะสรุปว่า
 * ทีมงานยังไม่พร้อม จึงรอถึง D-1 ให้ข้อมูลมาก่อน (ดู TripBriefService::isReady)
 */
class SendTripBriefsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    private const TIMEZONE = 'Asia/Bangkok';

    /** ส่งฉบับแรกก่อนวันเดินทางกี่วัน */
    public const LEAD_DAYS = 2;

    /**
     * สรุปผลของรอบที่เพิ่งวิ่ง — คำสั่ง trip-briefs:send อ่านค่านี้ไปแสดงให้ทีมงาน
     * เห็นว่าเกิดอะไรขึ้นบ้าง โดยไม่ต้องไปเปิด log
     *
     * @var array<string, int>
     */
    public array $summary = ['sent' => 0, 'updated' => 0, 'waiting' => 0, 'skipped' => 0];

    /**
     * ส่งใบจองใบเดียวทันที แทนที่จะกวาดทั้งช่วง
     *
     * มีไว้สำหรับ "จองวันนี้ ไปพรุ่งนี้" — รอบส่งประจำวันวิ่งไปแล้วตอน 18:05 และ
     * รอบถัดไปคือ 18:05 ของพรุ่งนี้ ซึ่งอาจเป็นเวลาที่รถออกไปแล้วครึ่งค่อนวัน
     * BookingObserver จึงยิงงานนี้ให้ทันทีที่ใบจองยืนยัน (ดู BookingObserver)
     */
    public function __construct(public readonly ?int $bookingId = null) {}

    public function handle(TripBriefService $briefs, MailService $mail, SmsService $sms): void
    {
        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        $bookings = Booking::query()
            ->whereIn('status', TripBriefService::ELIGIBLE_STATUSES)
            ->when(
                $this->bookingId !== null,
                // ใบเดียวเจาะจง — ไม่ต้องผ่านช่วงวัน เพราะจุดที่เรียกรู้แล้วว่าด่วน
                fn ($query) => $query->whereKey($this->bookingId),
                fn ($query) => $query->whereHas('schedule', function ($sub) use ($today) {
                    // ไล่จากวันทริป ไม่ใช่ departs_at — รอบที่รถออกคืนก่อนหน้าก็ยัง
                    // ต้องได้ใบเดินทางล่วงหน้าเท่ากัน (และ departs_at อาจไม่เคยถูกตั้ง)
                    $sub->whereDate('departure_date', '>=', $today->toDateString())
                        ->whereDate('departure_date', '<=', $today->copy()->addDays(self::LEAD_DAYS)->toDateString())
                        ->where('status', '!=', 'cancelled');
                }),
            )
            ->with($briefs->relations())
            ->get();

        foreach ($bookings as $booking) {
            $result = $this->process($booking, $briefs, $mail, $sms);
            $this->summary[$result] = ($this->summary[$result] ?? 0) + 1;
        }

        Log::info('SendTripBriefsJob completed', $this->summary);
    }

    private function process(Booking $booking, TripBriefService $briefs, MailService $mail, SmsService $sms): string
    {
        // รถออกไปแล้วก็ไม่ต้องส่งอะไรตามไปอีก — ทั้งฉบับแรกและฉบับอัปเดต
        if (! $briefs->isSendable($booking)) {
            return 'skipped';
        }

        $digest = $briefs->digest($booking);
        $isUpdate = $booking->brief_sent_at !== null;

        if ($isUpdate) {
            // ส่งไปแล้วและไม่มีอะไรเปลี่ยน = เงียบไว้ อย่ากวนลูกค้าทุกวัน
            if ($booking->brief_digest === $digest) {
                return 'skipped';
            }
        } elseif (! $briefs->isReady($booking) && ! $this->isLastCall($booking)) {
            // ยังไม่มีทั้งทีมงาน รถ และจุดขึ้นรถ — รอพรุ่งนี้ก่อน
            return 'waiting';
        }

        $delivered = $this->deliver($booking, $isUpdate, $mail, $sms);

        if (! $delivered) {
            // ไม่มีทั้งอีเมลและเบอร์ = ส่งไม่ได้จริง ๆ อย่าเพิ่งจดว่าส่งแล้ว
            // เผื่อวันหลังทีมงานเติมข้อมูลติดต่อเข้ามา
            Log::warning('Trip brief has nowhere to go', [
                'booking_ref' => $booking->booking_ref,
            ]);

            return 'skipped';
        }

        $booking->forceFill([
            'brief_sent_at' => now(),
            'brief_digest' => $digest,
        ])->save();

        return $isUpdate ? 'updated' : 'sent';
    }

    /**
     * โอกาสสุดท้ายที่จะส่งแล้ว — พรุ่งนี้หรือวันนี้เดินทาง ข้อมูลเท่าที่มีก็ยังดีกว่าเงียบ
     */
    private function isLastCall(Booking $booking): bool
    {
        $departureDate = $booking->schedule?->departure_date;

        if (! $departureDate) {
            return true;
        }

        // เทียบเป็นวันที่ล้วน — departure_date เก็บวันไทยไว้ในชนิด UTC
        $departureDay = Carbon::parse($departureDate->toDateString(), self::TIMEZONE)->startOfDay();

        return Carbon::now(self::TIMEZONE)->startOfDay()->diffInDays($departureDay, false) <= 1;
    }

    /**
     * ส่งทางช่องที่ลูกค้ามีจริง — อีเมลก่อนเพราะใส่เนื้อหาได้ครบ ไม่มีค่อยถอยไป SMS
     *
     * ไม่ส่งทั้งสองช่องทางโดยตั้งใจ: คนที่มีอีเมลจริงได้ทั้งอีเมลและ push อยู่แล้ว
     * SMS ซ้ำอีกฉบับคือค่าใช้จ่ายที่ไม่ได้เพิ่มอะไรให้ลูกค้า
     */
    private function deliver(Booking $booking, bool $isUpdate, MailService $mail, SmsService $sms): bool
    {
        // ต้องมีโทเคนก่อน ไม่งั้นอีเมลจะพาไปหน้าที่เปิดไม่ได้
        $url = $booking->briefUrl();

        if ($mail->sendTripBriefEmail($booking, $isUpdate)) {
            return true;
        }

        $log = $sms->sendTripBrief(
            $booking,
            $url,
            $isUpdate ? 'update:'.substr((string) $booking->brief_digest, 0, 8) : 'default',
        );

        return $log !== null && $log->status !== 'skipped';
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTripBriefsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
