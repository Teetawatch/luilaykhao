<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Services\RentalOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * "วันนี้วันสุดท้ายที่เช่าอุปกรณ์เพิ่มได้" — push ตอนเช้าของวันสุดท้ายที่ยังสั่งในแอปได้
 *
 * เหตุที่ต้องเตือน: ลูกค้าส่วนใหญ่นึกออกว่าขาดเต็นท์/ถุงนอนตอนจัดกระเป๋าคืนก่อนไป
 * ซึ่งปิดรับไปแล้ว แล้วต้องทักแอดมินให้แก้ใบจองเอง
 *
 * ชวนเฉพาะใบที่ยังไม่ได้เช่าอะไรเลย (คนที่เช่าแล้วคิดเรื่องนี้ไปแล้ว) และทริปที่มี
 * อุปกรณ์ขาย ใบละครั้ง (rental_reminder_sent_at) — งานลองซ้ำได้ แต่ไม่เตือนซ้ำ
 */
class SendRentalOrderRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(RentalOrderService $rentals): void
    {
        $sent = 0;
        // วันสุดท้ายที่สั่งได้คือ D-N → รอบที่ออกอีก N วันนับจากวันนี้ (เวลาไทย)
        $departure = now('Asia/Bangkok')->addDays(RentalOrderService::cutoffDays());

        Booking::query()
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->whereNull('rental_reminder_sent_at')
            ->whereDoesntHave('rentalOrders')
            ->whereHas('schedule', fn ($q) => $q
                ->departingOn($departure)
                ->where('status', '!=', 'cancelled')
                // รอบที่ยังไม่ครบขั้นต่ำ — อย่าชวนจ่ายเพิ่มให้รอบที่อาจไม่ออก
                ->travelMessagesAllowed())
            ->with(['schedule.trip'])
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use ($rentals, &$sent) {
                foreach ($bookings as $booking) {
                    if (! empty($booking->selected_rentals)) {
                        continue;
                    }
                    // ทริปไม่มีของขาย / ใบรอเลือกรอบใหม่ / ปิดไปแล้ว — ไม่มีอะไรให้ชวน
                    if ($rentals->closedReason($booking) !== null) {
                        continue;
                    }

                    $this->remind($booking);
                    $sent++;
                }
            });

        if ($sent > 0) {
            Log::info('SendRentalOrderRemindersJob: reminded '.$sent.' bookings');
        }
    }

    private function remind(Booking $booking): void
    {
        $trip = $booking->schedule?->trip?->title ?? 'ทริปของคุณ';

        // บันทึกก่อนส่ง — งานถูกลองซ้ำได้ อย่าให้คนเดียวกันได้สองรอบ
        $booking->forceFill(['rental_reminder_sent_at' => now()])->saveQuietly();

        SmartNotification::send(
            $booking->user_id,
            'rental_order_reminder',
            'วันนี้วันสุดท้ายที่เช่าอุปกรณ์เพิ่มได้',
            "{$trip} — ขาดเต็นท์ ถุงนอน หรืออุปกรณ์อื่น เช่าเพิ่มในแอปได้ถึงเที่ยงคืนนี้ ทีมงานเตรียมไว้ให้ที่จุดขึ้นรถ",
            ['booking_ref' => $booking->booking_ref, 'route' => 'rentals'],
        );
    }
}
