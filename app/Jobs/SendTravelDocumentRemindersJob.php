<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Services\MailService;
use App\Services\TravelDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ตามเก็บเอกสารเดินทางของทริปต่างประเทศเป็นระยะ ๆ ก่อนถึงวันเดินทาง
 *
 * ทำไมต้องมีทั้งที่ส่งอีเมลไปแล้วตอนจอง: อีเมลฉบับเดียวตอนจองไม่พอ เพราะ
 *  1. คนที่ไม่ได้เปิดอ่านวันนั้นจะไม่มีใครทวงอีกเลยจนถึงวันเดินทาง
 *  2. "พาสปอร์ตใกล้หมดอายุ" ไม่ใช่ความผิดพลาดตอนจอง — ตอนจองยังผ่านเกณฑ์
 *     แต่พอเลื่อนรอบหรือจองล่วงหน้าหลายเดือน วันหมดอายุเดิมก็ตกเกณฑ์ขึ้นมาเอง
 *     ต้องมีคนไล่ตรวจซ้ำเมื่อเวลาผ่านไป
 *
 * ยิงเฉพาะรอบที่ออกเดินทางอีก DAYS_BEFORE วันพอดี จึงเตือนสูงสุด 3 ครั้งต่อการ
 * จองโดยไม่ต้องมีตารางกันส่งซ้ำ (แนวเดียวกับ SendUnderfilledTripWarningsJob)
 * และครั้งสุดท้าย 10 วันก่อนเดินทาง ยังทันทำเล่มใหม่แบบเร่งด่วน
 */
class SendTravelDocumentRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** จำนวนวันก่อนเดินทางที่จะเตือน */
    private const DAYS_BEFORE = [45, 21, 10];

    public function handle(MailService $mailService, TravelDocumentService $documents): void
    {
        $totals = ['bookings' => 0, 'missing' => 0, 'expiring' => 0];

        foreach (self::DAYS_BEFORE as $daysBefore) {
            $targetDate = now('Asia/Bangkok')->addDays($daysBefore)->toDateString();

            $schedules = TripSchedule::query()
                ->departingOn($targetDate)
                ->where('status', '!=', 'cancelled')
                ->whereHas('trip', fn ($q) => $q->where('destination_type', 'international'))
                ->with('trip')
                ->get();

            foreach ($schedules as $schedule) {
                $bookings = Booking::query()
                    ->where('schedule_id', $schedule->id)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->with(['user', 'passengers', 'schedule.trip'])
                    ->get();

                foreach ($bookings as $booking) {
                    $missing = $documents->missing($booking);
                    $expiring = $documents->expiringTooSoon($booking);

                    if ($missing->isEmpty() && $expiring->isEmpty()) {
                        continue;
                    }

                    $totals['bookings']++;

                    if ($missing->isNotEmpty()) {
                        $totals['missing']++;
                        $mailService->sendPassportInfoNeededEmail($booking);
                        $this->notify(
                            $booking,
                            'passport_info_needed',
                            'ขอข้อมูลพาสปอร์ตของผู้เดินทาง',
                            "ทริป{$schedule->trip->title} ออกเดินทางในอีก {$daysBefore} วัน "
                                ."ยังขาดข้อมูลพาสปอร์ตของผู้เดินทาง {$missing->count()} ท่านครับ "
                                .'เปิดใบจองแล้วกรอกได้เลย ใช้เวลาไม่เกินสองนาที '
                                .'ถ้ายังไม่มีเล่มอยู่กับตัว เว้นไว้แล้วกลับมากรอกทีหลังได้ครับ',
                        );
                    }

                    if ($expiring->isNotEmpty()) {
                        $totals['expiring']++;
                        $mailService->sendPassportExpiringEmail($booking, $daysBefore);
                        $this->notify(
                            $booking,
                            'passport_expiring',
                            'พาสปอร์ตใกล้หมดอายุ',
                            "พาสปอร์ตของผู้เดินทาง {$expiring->count()} ท่านในทริป{$schedule->trip->title} "
                                .'จะหมดอายุเร็วกว่าเกณฑ์ 6 เดือนนับจากวันเดินทางครับ '
                                ."ยังเหลือเวลาอีก {$daysBefore} วัน ต่อเล่มใหม่ทันครับ "
                                .'ได้เล่มใหม่แล้วแก้เลขพาสปอร์ตในใบจองได้เลย',
                        );
                    }
                }
            }
        }

        Log::info('SendTravelDocumentRemindersJob completed', $totals);
    }

    private function notify(Booking $booking, string $type, string $title, string $body): void
    {
        if (! $booking->user_id) {
            return;
        }

        SmartNotification::send(
            $booking->user_id,
            $type,
            $title,
            $body,
            ['booking_ref' => $booking->booking_ref, 'route' => 'travel_documents'],
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTravelDocumentRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
