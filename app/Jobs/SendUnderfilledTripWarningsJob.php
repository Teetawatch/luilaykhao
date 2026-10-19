<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\EmailLog;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Services\ChatRoomEventService;
use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A few days before departure, warn customers whose round still hasn't reached
 * the guaranteed minimum number of booked seats — the trip may be cancelled.
 *
 * แจ้งทางอีเมล + SMS + แจ้งเตือนในแอป (ซึ่งส่งต่อเข้า LINE ให้คนที่ไม่มีแอป)
 *
 * เดิมยิงวันละครั้งเฉพาะรอบที่ออก "พอดี" D-7 — วันไหน worker ล่มหรือ job ล้ม รอบนั้น
 * ก็ไม่มีใครได้แจ้งเลย คนที่จองหลัง D-7 หรือรอบที่มีคนยกเลิกจนต่ำกว่าเกณฑ์หลัง D-7
 * ก็หลุดเช่นกัน ตอนนี้จึงกวาดทุกรอบที่ออกภายใน D-7 ถึง D-LAST_DAYS_BEFORE ทุกรอบที่
 * วิ่ง แล้วกันซ้ำต่อใบจองต่อรอบจากหลักฐานที่บันทึกไว้ (email_logs / sms_logs)
 */
class SendUnderfilledTripWarningsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** How many days before departure the warning is sent. */
    public const DAYS_BEFORE = 7;

    /**
     * ใกล้กว่านี้ไม่ตามแจ้งแล้ว — ทีมงานตัดสินใจเรื่องรอบไปแล้ว ข้อความ "ยังไม่ครบ"
     * คืนก่อนเดินทางมีแต่ทำให้ลูกค้าตกใจโดยไม่มีเวลาทำอะไร
     */
    public const LAST_DAYS_BEFORE = 2;

    public function handle(MailService $mailService, SmsService $smsService, ChatRoomEventService $chatEvents): void
    {
        $totals = ['schedules' => 0, 'bookings' => 0, 'first_warnings' => 0];
        $minSeats = TripSchedule::underfilledMinSeats();
        $today = now('Asia/Bangkok')->startOfDay();

        foreach (range(self::DAYS_BEFORE, self::LAST_DAYS_BEFORE) as $daysLeft) {
            $schedules = TripSchedule::query()
                ->departingOn($today->copy()->addDays($daysLeft)->toDateString())
                ->where('status', '!=', 'cancelled')
                // รอบเหมาคันออกเดินทางแน่นอน ไม่มีเรื่องคนไม่ครบ
                ->where('is_charter', false)
                // ทีมงานยืนยันแล้วว่าออกแม้คนไม่ครบ — ห้ามบอกลูกค้าว่ารอบอาจไม่ได้ไป
                ->whereNull('departure_confirmed_at')
                ->where('booked_seats', '<', $minSeats)
                ->where('booked_seats', '>', 0)
                ->with('trip')
                ->get();

            foreach ($schedules as $schedule) {
                $totals['schedules']++;

                // ลูกค้าหลายคนเปิดห้องแชทบ่อยกว่าอีเมล — บอกในห้องด้วย (ครั้งเดียวต่อรอบ)
                $chatEvents->roundUnderfilled($schedule, (int) $schedule->booked_seats, $minSeats);

                foreach ($this->bookingsToWarn($schedule) as $booking) {
                    $totals['bookings']++;

                    if ($this->warn($booking, $schedule, $daysLeft, $minSeats, $mailService, $smsService)) {
                        $totals['first_warnings']++;
                    }
                }
            }
        }

        Log::info('SendUnderfilledTripWarningsJob completed', $totals);
    }

    /**
     * ใบจองที่ยืนยันแล้ว รวมถึงใบที่ยังรอตรวจสลิปแต่จ่ายเงินมาแล้ว — คนกลุ่มหลัง
     * มีเงินค้างอยู่กับเราเท่ากัน ต้องรู้เรื่องสิทธิ์ย้ายรอบ/คืนเงินเหมือนกัน
     *
     * @return Collection<int, Booking>
     */
    private function bookingsToWarn(TripSchedule $schedule)
    {
        return Booking::query()
            ->where('schedule_id', $schedule->id)
            ->where(function ($query) {
                $query->where('status', 'confirmed')
                    ->orWhere(fn ($query) => $query->where('status', 'pending')->where('paid_amount', '>', 0));
            })
            ->with(['user', 'passengers', 'pickupPoint', 'schedule.trip'])
            ->get();
    }

    /**
     * ส่งทุกช่องทางที่ยังไม่ถึง — เรียกซ้ำได้ทุกชั่วโมงโดยไม่ส่งซ้ำ
     *
     * @return bool true เมื่อเป็นการแจ้งใบนี้ครั้งแรกของรอบนี้
     */
    private function warn(
        Booking $booking,
        TripSchedule $schedule,
        int $daysLeft,
        int $minSeats,
        MailService $mailService,
        SmsService $smsService,
    ): bool {
        $bookedSeats = (int) $schedule->booked_seats;

        // ครั้งแรก = ยังไม่มีแถวหลักฐานอีเมลของรอบนี้เลย (ใบที่ไม่มีอีเมลก็ได้แถว skipped)
        $firstTime = ! EmailLog::query()
            ->where('type', EmailLog::TYPE_UNDERFILLED_WARNING)
            ->where('booking_id', $booking->id)
            ->where('schedule_id', $schedule->id)
            ->exists();

        $emails = [];

        try {
            $emails = $mailService->sendTripUnderfilledWarningEmail($booking, $daysLeft, $bookedSeats, $minSeats);
        } catch (\Throwable $e) {
            Log::error('Failed to send trip underfilled warning email', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);
        }

        // SMS กันซ้ำในตัวเอง (ใบละครั้งต่อรอบ) ที่ล้มเหลว sms:send-pending ลองให้อีก
        $smsService->sendUnderfilledWarning($booking, $bookedSeats, $minSeats, emailed: $emails !== []);

        if ($firstTime && $booking->user_id) {
            // บอกด้วยว่า "ทำอะไรได้บ้าง" ไม่ใช่แค่แจ้งว่าคนยังไม่ครบ —
            // ในใบจองมีการ์ดช่วยกันเปิดรอบพร้อมลิงก์ชวนเพื่อนรออยู่แล้ว
            $seatsShort = max(0, $minSeats - $bookedSeats);

            SmartNotification::send(
                $booking->user_id,
                'trip_underfilled_warning',
                'อัปเดตการยืนยันรอบเดินทาง',
                "ทริป{$schedule->trip->title} ตอนนี้มีผู้ร่วมทริป {$bookedSeats}/{$minSeats} ท่าน "
                    ."ขาดอีก {$seatsShort} ท่านก็ออกเดินทางตามกำหนดครับ "
                    .'รายละเอียดการเดินทางจะส่งให้เมื่อรอบยืนยันออกเดินทางแล้ว '
                    .'เปิดใบจองเพื่อส่งลิงก์ชวนเพื่อนมาร่วมทาง หรือทักทีมงานเพื่อย้ายไปรอบอื่นได้เลย '
                    .'และหากรอบนี้ไม่ได้ออกเดินทาง เราคืนเงินเต็มจำนวนครับ',
                ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
            );
        }

        return $firstTime;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendUnderfilledTripWarningsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
