<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupOrphanBookingSeatsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makeBookingWithSeat(TripSchedule $schedule, string $status, string $seatId): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 1500,
            'qr_code' => Booking::generateQrCode(),
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'Test',
            'phone' => '0812345678',
        ]);

        BookingSeat::create([
            'booking_id' => $booking->id,
            'schedule_id' => $schedule->id,
            'seat_id' => $seatId,
            'passenger_name' => 'Test',
        ]);

        return $booking;
    }

    public function test_dry_run_reports_orphans_without_deleting(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeBookingWithSeat($schedule, 'refunded', 'A2');

        $this->artisan('seats:cleanup-orphans')
            ->expectsOutputToContain('พบ orphan 1 แถว')
            ->assertSuccessful();

        // dry-run ต้องไม่ลบ
        $this->assertSame(1, BookingSeat::where('seat_id', 'A2')->count());
    }

    public function test_apply_deletes_orphans_and_keeps_active_seats(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeBookingWithSeat($schedule, 'refunded', 'A2');   // orphan
        $this->makeBookingWithSeat($schedule, 'cancelled', 'A3');  // orphan
        $this->makeBookingWithSeat($schedule, 'confirmed', 'A4');  // ต้องคงไว้
        $this->makeBookingWithSeat($schedule, 'pending', 'A5');    // ต้องคงไว้

        $this->artisan('seats:cleanup-orphans --apply')
            ->assertSuccessful();

        $this->assertSame(0, BookingSeat::where('seat_id', 'A2')->count());
        $this->assertSame(0, BookingSeat::where('seat_id', 'A3')->count());
        $this->assertSame(1, BookingSeat::where('seat_id', 'A4')->count());
        $this->assertSame(1, BookingSeat::where('seat_id', 'A5')->count());

        // booked_seats ถูก sync ให้ตรงกับที่นั่ง active ที่เหลือ (A4, A5)
        $this->assertSame(2, $schedule->fresh()->booked_seats);
    }

    /**
     * ที่นั่งที่ค้างจากการลบผู้โดยสาร (การจองยัง active แต่ที่นั่ง > ผู้โดยสาร)
     * ต้องถูกกวาดคืนด้วย ไม่งั้นไม่มีใครจองเบอร์นั้นซ้ำได้
     */
    public function test_apply_releases_seats_beyond_passenger_count(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->makeBookingWithSeat($schedule, 'confirmed', 'B1');

        // ผู้โดยสารเหลือ 1 คน แต่มีที่นั่งค้างอยู่ 3 เบอร์
        foreach (['B2', 'B3'] as $seatId) {
            BookingSeat::create([
                'booking_id' => $booking->id,
                'schedule_id' => $schedule->id,
                'seat_id' => $seatId,
                'passenger_name' => 'Test',
            ]);
        }

        $this->artisan('seats:cleanup-orphans --apply')
            ->expectsOutputToContain('พบที่นั่งส่วนเกิน 2 แถว')
            ->assertSuccessful();

        // เก็บที่นั่งแรกไว้ให้ผู้โดยสารที่เหลือ ปล่อยคืนสองเบอร์ท้าย
        $this->assertSame(1, BookingSeat::where('seat_id', 'B1')->count());
        $this->assertSame(0, BookingSeat::where('seat_id', 'B2')->count());
        $this->assertSame(0, BookingSeat::where('seat_id', 'B3')->count());
    }

    /**
     * การจองที่ยังไม่ได้กรอกผู้โดยสารเลยไม่ใช่เคสลบผู้โดยสาร — ห้ามลบที่นั่งทิ้ง
     */
    public function test_apply_keeps_seats_when_booking_has_no_passengers(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->makeBookingWithSeat($schedule, 'confirmed', 'C1');
        $booking->passengers()->delete();

        $this->artisan('seats:cleanup-orphans --apply')
            ->expectsOutputToContain('สะอาดดีอยู่แล้ว')
            ->assertSuccessful();

        $this->assertSame(1, BookingSeat::where('seat_id', 'C1')->count());
    }

    public function test_reports_clean_when_no_orphans(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeBookingWithSeat($schedule, 'confirmed', 'A4');

        $this->artisan('seats:cleanup-orphans --apply')
            ->expectsOutputToContain('สะอาดดีอยู่แล้ว')
            ->assertSuccessful();

        $this->assertSame(1, BookingSeat::where('seat_id', 'A4')->count());
    }
}
