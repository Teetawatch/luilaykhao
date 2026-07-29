<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Services\TripFactsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends in-app + push (FCM) pre-departure reminders 7 and 1 days before the
 * trip, deep-linking to the booking detail (which carries the checklist).
 * SMS reminders are handled separately by SendBookingRemindersJob.
 *
 * ข้อความ 1 วันก่อนเดินทางพก "คำตอบ" ไปด้วย (จุดรับ + เวลา + ทะเบียนรถ + เบอร์
 * คนขับ เท่าที่รู้แล้ว) เพราะคำถามที่ลูกค้าถามซ้ำที่สุดคือเรื่องพวกนี้ — อ่านจบ
 * ใน notification ได้เลยโดยไม่ต้องเปิดแอปหรือทักมาถาม
 */
class SendTripReminderNotificationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    private const REMIND_DAYS_BEFORE = [7, 1];

    public function handle(): void
    {
        $sent = 0;

        foreach (self::REMIND_DAYS_BEFORE as $daysBefore) {
            $bookings = Booking::where('status', 'confirmed')
                ->whereNotNull('user_id')
                ->whereHas('schedule', function ($query) use ($daysBefore) {
                    // นับวันจากเวลาออกเดินทางจริง (departs_at) ไม่ใช่วันทริป
                    // เพื่อให้รอบที่รถออกคืนก่อนวันทริปได้แจ้งเตือนถูกวัน
                    $query->departingOn(now()->addDays($daysBefore))
                        ->where('status', '!=', 'cancelled');
                })
                ->with(['schedule.trip', 'schedule.vehicle', 'schedule.pickupPoints', 'pickupPoint'])
                ->get();

            foreach ($bookings as $booking) {
                if ($this->alreadySent($booking, $daysBefore)) {
                    continue;
                }

                $tripTitle = $booking->schedule?->trip?->title ?? 'ทริปของคุณ';

                $title = $daysBefore === 1
                    ? 'พรุ่งนี้ออกเดินทางแล้ว! 🎒'
                    : "อีก {$daysBefore} วันจะถึงวันเดินทาง";

                $departsAt = $booking->schedule?->departs_at;
                $timeNote = $departsAt ? ' เวลา '.$departsAt->format('H:i').' น.' : '';

                if ($daysBefore === 1) {
                    $facts = app(TripFactsService::class)->reminderLine($booking);
                    $body = "{$tripTitle} ออกเดินทางพรุ่งนี้{$timeNote}";
                    $body .= $facts !== '' ? " · {$facts}" : '';
                    $body .= ' อย่าลืมเช็กรายการสิ่งที่ต้องเตรียมให้พร้อม';
                } else {
                    $body = "{$tripTitle} ใกล้ถึงแล้ว เริ่มเตรียมของตามเช็กลิสต์ได้เลย";
                }

                SmartNotification::send(
                    $booking->user_id,
                    'trip_reminder',
                    $title,
                    $body,
                    [
                        'booking_ref' => $booking->booking_ref,
                        'trip_id' => $booking->schedule?->trip_id,
                        'schedule_id' => $booking->schedule_id,
                        'days_before' => $daysBefore,
                        'route' => 'booking',
                    ],
                );

                $sent++;
            }
        }

        Log::info('SendTripReminderNotificationsJob completed', ['sent' => $sent]);
    }

    /**
     * Guard against double-sending if the daily job runs more than once.
     */
    private function alreadySent(Booking $booking, int $daysBefore): bool
    {
        return SmartNotification::where('user_id', $booking->user_id)
            ->where('type', 'trip_reminder')
            ->where('data->booking_ref', $booking->booking_ref)
            ->where('data->days_before', $daysBefore)
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTripReminderNotificationsJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
