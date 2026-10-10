<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\SmartNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * "พรุ่งนี้ไปครบไหม" — ถามคนจองของใบจองหลายคน หนึ่งวันก่อนรถออก
 *
 * เหตุที่ต้องถาม: ใบจอง 4 คนที่มาจริง 3 คน สตาฟรู้ตอนยืนรอคนที่สี่อยู่ที่จุดรับ
 * ถามวันก่อนเดินทางได้คำตอบที่ยังมีประโยชน์ (ยังส่งต่อที่นั่งให้คนอื่นไปแทนได้
 * จนถึง 3 ชั่วโมงก่อนรถออก) และคนจองรู้แล้วว่าใครไปไม่ได้
 *
 * ถามเฉพาะใบที่มีมากกว่าหนึ่งคน — ใบคนเดียวที่ไม่มา คือคนที่ไม่ได้อ่านข้อความอยู่ดี
 * ถามครั้งเดียวต่อใบ (attendance_asked_at) และไม่ถามใบที่ตอบมาเองแล้ว
 *
 * คนที่ไม่มีแอปแต่จองผ่าน LINE ได้ข้อความทาง LINE พร้อมลิงก์ใบเดินทาง ซึ่งมีปุ่ม
 * ตอบอยู่ในนั้น (LIFF ยังไม่มีหน้านี้)
 */
class AskTripAttendanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(): void
    {
        $asked = 0;

        Booking::query()
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->whereNull('attendance_asked_at')
            ->whereNull('attendance_confirmed_at')
            // รอบที่ถูกเลื่อนเพราะเหตุสุดวิสัยและลูกค้ายังไม่ได้เลือกรอบใหม่ — ไม่มีอะไรให้ไป
            ->where(fn ($q) => $q->whereNull('force_majeure_at')->orWhereNotNull('force_majeure_resolved_at'))
            ->whereHas('schedule', fn ($q) => $q
                // now('Asia/Bangkok') ไม่ใช่ now() — แอปตั้งโซนเป็น UTC ช่วงเช้ามืดเวลาไทย
                // "พรุ่งนี้" จาก UTC คือวันนี้ของไทย
                ->departingOn(now('Asia/Bangkok')->addDay())
                ->where('status', '!=', 'cancelled'))
            ->has('passengers', '>=', 2)
            ->with(['schedule.trip'])
            ->withCount('passengers')
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use (&$asked) {
                foreach ($bookings as $booking) {
                    $this->ask($booking);
                    $asked++;
                }
            });

        if ($asked > 0) {
            Log::info('AskTripAttendanceJob: asked '.$asked.' bookings');
        }
    }

    private function ask(Booking $booking): void
    {
        $trip = $booking->schedule?->trip?->title ?? 'ทริปของคุณ';
        $count = (int) $booking->passengers_count;

        // บันทึกก่อนส่ง — งานถูกลองซ้ำได้ (tries) อย่าให้คนเดียวกันได้คำถามสองรอบ
        // saveQuietly: ไม่ใช่การเปลี่ยนแปลงของการจอง ไม่ต้องปลุกงานที่เฝ้าดูใบจอง
        $booking->ensureBriefToken();
        $booking->forceFill(['attendance_asked_at' => now()])->saveQuietly();

        SmartNotification::send(
            $booking->user_id,
            'attendance_check',
            "พรุ่งนี้ไปครบ {$count} คนไหม?",
            "{$trip} ออกเดินทางพรุ่งนี้ ถ้ามีใครไปไม่ได้ บอกทีมงานไว้ก่อน จะได้ไม่ต้องรอที่จุดขึ้นรถ — "
                .'หรือส่งต่อที่นั่งให้คนอื่นไปแทนได้',
            [
                'booking_ref' => $booking->booking_ref,
                'route' => 'attendance',
                // ลิงก์สำหรับคนที่ได้ข้อความทาง LINE — ใบเดินทางมีปุ่มตอบอยู่แล้ว
                'web_url' => $booking->briefUrl().'#attendance',
            ],
        );
    }
}
