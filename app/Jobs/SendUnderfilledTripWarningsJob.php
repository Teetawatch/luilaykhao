<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Services\MailService;
use App\Support\SiteSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A few days before departure, warn customers whose round still hasn't reached
 * the guaranteed minimum number of booked seats — the trip may be cancelled.
 *
 * Runs once a day and targets rounds departing exactly DAYS_BEFORE days out, so
 * each booking is naturally notified at most once (mirrors SendBalanceDueRemindersJob).
 */
class SendUnderfilledTripWarningsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** How many days before departure the warning is sent. */
    private const DAYS_BEFORE = 7;

    /** Minimum booked seats that guarantees the round runs (default only). */
    private const MIN_SEATS = 8;

    /** เกณฑ์ที่นั่งขั้นต่ำ — แอดมินปรับได้ที่หน้าตั้งค่าระบบ */
    private function minSeats(): int
    {
        return max(1, SiteSettings::int('underfilled_min_seats'));
    }

    public function handle(MailService $mailService): void
    {
        $totals = ['schedules' => 0, 'emailed' => 0];
        $minSeats = $this->minSeats();

        $targetDate = now('Asia/Bangkok')->addDays(self::DAYS_BEFORE)->toDateString();

        $schedules = TripSchedule::query()
            ->departingOn($targetDate)
            ->where('status', '!=', 'cancelled')
            ->where('booked_seats', '<', $minSeats)
            ->where('booked_seats', '>', 0)
            ->with('trip')
            ->get();

        foreach ($schedules as $schedule) {
            $totals['schedules']++;

            $bookings = Booking::query()
                ->where('schedule_id', $schedule->id)
                ->where('status', 'confirmed')
                ->with(['user', 'passengers', 'pickupPoint', 'schedule.trip'])
                ->get();

            foreach ($bookings as $booking) {
                try {
                    $mailService->sendTripUnderfilledWarningEmail(
                        $booking,
                        self::DAYS_BEFORE,
                        (int) $schedule->booked_seats,
                        $minSeats,
                    );
                    $totals['emailed']++;
                } catch (\Throwable $e) {
                    Log::error('Failed to send trip underfilled warning email', [
                        'booking_ref' => $booking->booking_ref,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($booking->user_id) {
                    // บอกด้วยว่า "ทำอะไรได้บ้าง" ไม่ใช่แค่แจ้งว่าคนยังไม่ครบ —
                    // ในใบจองมีการ์ดช่วยกันเปิดรอบพร้อมลิงก์ชวนเพื่อนรออยู่แล้ว
                    $seatsShort = max(0, $minSeats - (int) $schedule->booked_seats);

                    SmartNotification::send(
                        $booking->user_id,
                        'trip_underfilled_warning',
                        'อัปเดตการยืนยันรอบเดินทาง',
                        "ทริป{$schedule->trip->title} ตอนนี้มีผู้ร่วมทริป {$schedule->booked_seats}/{$minSeats} ท่าน "
                            ."ขาดอีก {$seatsShort} ท่านก็ออกเดินทางตามกำหนดครับ "
                            .'เปิดใบจองเพื่อส่งลิงก์ชวนเพื่อนมาร่วมทาง หรือทักทีมงานเพื่อย้ายไปรอบอื่นได้เลย '
                            .'และหากรอบนี้ไม่ได้ออกเดินทาง เราคืนเงินเต็มจำนวนครับ',
                        ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
                    );
                }
            }
        }

        Log::info('SendUnderfilledTripWarningsJob completed', $totals);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendUnderfilledTripWarningsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
