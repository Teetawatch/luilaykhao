<?php

namespace Tests\Concerns;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\TripSchedule;
use App\Models\User;

/**
 * ใส่ลูกค้าที่จองยืนยันแล้วลงในรอบ — งานที่ตามเรื่อง "รถออกแล้ว" มองเฉพาะรอบที่รถ
 * ออกจริง (TripSchedule::goingAhead: ถึงขั้นต่ำหรือแอดมินยืนยัน และมีคนเดินทาง)
 */
trait BooksTravellers
{
    protected function bookTraveller(TripSchedule $schedule, array $passenger = []): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500,
        ]);

        BookingPassenger::create($passenger + [
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'ลูกทริป',
            'phone' => '0811111111',
        ]);

        return $booking;
    }

    /** แอดมินกด "ยืนยันออกเดินทาง" — รอบที่คนยังไม่ครบขั้นต่ำจึงนับว่ารถออกจริง */
    protected function confirmDeparture(TripSchedule $schedule): void
    {
        $schedule->forceFill(['departure_confirmed_at' => now()])->save();
    }
}
