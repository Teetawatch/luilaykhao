<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fires check-in-aware reminders shortly before a round's *actual* departure
 * time (`departs_at`), for rounds departing within {@see self::LEAD_MINUTES}:
 *
 *  1. To each still-unchecked passenger — "อย่าลืมแสดง QR เช็คอิน" so they open
 *     the app and have the code ready when staff scan them.
 *  2. To the round's assigned staff — a one-line summary of how many bookings
 *     are still unchecked (or "ครบแล้ว"), so they can decide to wait or leave.
 *
 * Complements the coarser reminders (day-before, ~3h `trip_departure_soon`,
 * minute-level pickup ETA) — none of which look at check-in status. Runs on a
 * short interval; every recipient is notified once (dedupe via SmartNotification).
 *
 * Timezone note: `departs_at` is stored as Thai wall-clock (never converted),
 * while the app timezone is UTC — so the window is built from now('Asia/Bangkok')
 * whose formatted value matches the stored digits. See [[reference_departs_at_timezone]].
 */
class SendCheckInRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    private const TIMEZONE = 'Asia/Bangkok';

    /** Remind once the real departure is within this many minutes. */
    private const LEAD_MINUTES = 45;

    public function handle(): void
    {
        $now = Carbon::now(self::TIMEZONE);
        $windowEnd = $now->copy()->addMinutes(self::LEAD_MINUTES);

        $schedules = TripSchedule::query()
            ->whereNotNull('departs_at')
            ->where('departs_at', '>=', $now)
            ->where('departs_at', '<=', $windowEnd)
            ->where('status', '!=', 'cancelled')
            ->with(['trip:id,title', 'staff:id'])
            ->get();

        $passengerSent = 0;
        $staffSent = 0;

        foreach ($schedules as $schedule) {
            $confirmed = Booking::where('schedule_id', $schedule->id)
                ->where('status', 'confirmed')
                ->get(['id', 'user_id', 'booking_ref', 'checked_in', 'schedule_id']);

            if ($confirmed->isEmpty()) {
                continue;
            }

            $passengerSent += $this->remindUncheckedPassengers($schedule, $confirmed);
            $staffSent += $this->summariseForStaff($schedule, $confirmed);
        }

        Log::info('SendCheckInRemindersJob completed', [
            'passenger_sent' => $passengerSent,
            'staff_sent' => $staffSent,
        ]);
    }

    /**
     * นัดเตือนเฉพาะการจองที่ยังไม่ถูกสแกนเช็คอิน และมีเจ้าของบัญชี
     *
     * @param  Collection<int, Booking>  $confirmed
     */
    private function remindUncheckedPassengers(TripSchedule $schedule, $confirmed): int
    {
        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';
        $timeLabel = $schedule->departs_at?->format('H:i') ?? '';
        $sent = 0;

        foreach ($confirmed as $booking) {
            if ($booking->checked_in || ! $booking->user_id) {
                continue;
            }
            if ($this->passengerAlreadyReminded($booking)) {
                continue;
            }

            SmartNotification::send(
                $booking->user_id,
                'checkin_reminder',
                'ใกล้ออกเดินทางแล้ว อย่าลืมเช็คอิน 🎫',
                "{$tripTitle} ออกเวลา {$timeLabel} น. เปิดแอปแสดง QR เช็คอินให้เจ้าหน้าที่สแกนได้เลย",
                [
                    'booking_ref' => $booking->booking_ref,
                    'trip_id' => $schedule->trip_id,
                    'schedule_id' => $schedule->id,
                    'route' => 'booking',
                ],
            );

            $sent++;
        }

        return $sent;
    }

    /**
     * แจ้งสตาฟประจำรอบว่าเหลือกี่รายยังไม่มาเช็คอิน (หรือครบแล้ว) หนึ่งครั้งต่อรอบ
     *
     * @param  Collection<int, Booking>  $confirmed
     */
    private function summariseForStaff(TripSchedule $schedule, $confirmed): int
    {
        $staffIds = $schedule->staff->pluck('id');
        if ($staffIds->isEmpty()) {
            return 0;
        }

        $total = $confirmed->count();
        $checkedIn = $confirmed->where('checked_in', true)->count();
        $remaining = $total - $checkedIn;

        $tripTitle = $schedule->trip?->title ?? 'ทริป';
        $timeLabel = $schedule->departs_at?->format('H:i') ?? '';

        if ($remaining > 0) {
            $title = "เหลือ {$remaining} รายยังไม่เช็คอิน ⏳";
            $body = "{$tripTitle} ออกเวลา {$timeLabel} น. • เช็คอินแล้ว {$checkedIn}/{$total} ราย";
        } else {
            $title = 'เช็คอินครบแล้ว พร้อมออกเดินทาง ✅';
            $body = "{$tripTitle} ออกเวลา {$timeLabel} น. • ทุกราย ({$total}) เช็คอินเรียบร้อย";
        }

        $sent = 0;
        foreach ($staffIds as $staffId) {
            if ($this->staffAlreadyNotified($staffId, $schedule->id)) {
                continue;
            }

            SmartNotification::send(
                $staffId,
                'checkin_staff_summary',
                $title,
                $body,
                [
                    'schedule_id' => $schedule->id,
                    'trip_id' => $schedule->trip_id,
                    'remaining' => $remaining,
                    'checked_in' => $checkedIn,
                    'total' => $total,
                    'route' => 'staff_manifest',
                ],
            );

            $sent++;
        }

        return $sent;
    }

    /** เตือนลูกค้าครั้งเดียวต่อการจอง แม้ job จะรันซ้ำระหว่างอยู่ในช่วงก่อนออกรถ */
    private function passengerAlreadyReminded(Booking $booking): bool
    {
        return SmartNotification::where('user_id', $booking->user_id)
            ->where('type', 'checkin_reminder')
            ->where('data->booking_ref', $booking->booking_ref)
            ->exists();
    }

    /** แจ้งสตาฟครั้งเดียวต่อรอบ */
    private function staffAlreadyNotified(int $staffId, int $scheduleId): bool
    {
        return SmartNotification::where('user_id', $staffId)
            ->where('type', 'checkin_staff_summary')
            ->where('data->schedule_id', $scheduleId)
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendCheckInRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
