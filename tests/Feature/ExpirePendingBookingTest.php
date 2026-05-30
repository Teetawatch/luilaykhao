<?php

namespace Tests\Feature;

use App\Jobs\ExpirePendingBookingsJob;
use App\Mail\BookingCancelledMail;
use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExpirePendingBookingTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
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
    }

    private function makeSchedule(Trip $trip): TripSchedule
    {
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

    private function makePendingBooking(User $user, TripSchedule $schedule, array $seatIds): Booking
    {
        Mail::fake();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: collect($seatIds)->map(fn ($s, $i) => [
                'title' => 'Mr.',
                'name' => "Passenger {$i}",
                'phone' => '0812345678',
                'email' => "p{$i}@example.test",
            ])->all(),
        );

        foreach ($seatIds as $i => $seatId) {
            BookingSeat::create([
                'booking_id' => $booking->id,
                'schedule_id' => $schedule->id,
                'seat_id' => $seatId,
                'passenger_name' => "Passenger {$i}",
            ]);
        }

        return $booking->fresh(['seats', 'schedule']);
    }

    public function test_stale_pending_booking_is_cancelled_and_seats_freed(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip());

        $booking = $this->makePendingBooking($user, $schedule, ['A1', 'A2']);

        // รอบนี้ถูกจองที่นั่งไว้ 2 ที่ทันทีหลังสร้าง
        $this->assertSame(2, $schedule->fresh()->booked_seats);

        // ทำให้การจองเก่ากว่าเส้นตาย
        $booking->forceFill([
            'created_at' => now()->subMinutes(Booking::PENDING_TTL_MINUTES + 1),
        ])->saveQuietly();

        Mail::fake();
        app(ExpirePendingBookingsJob::class)->handle(app(BookingService::class));

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame(0, $booking->seats()->count());
        $this->assertSame(0, $schedule->fresh()->booked_seats);

        // แจ้งลูกค้าทางอีเมล (ฟรี) — ไม่ส่ง SMS
        Mail::assertSent(BookingCancelledMail::class);
        $this->assertDatabaseMissing('sms_logs', ['booking_id' => $booking->id]);
    }

    public function test_recent_pending_booking_is_not_cancelled(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip());

        $booking = $this->makePendingBooking($user, $schedule, ['A1']);

        // เพิ่งสร้าง — ยังไม่ถึงเส้นตาย
        app(ExpirePendingBookingsJob::class)->handle(app(BookingService::class));

        $this->assertSame('pending', $booking->fresh()->status);
        $this->assertSame(1, $schedule->fresh()->booked_seats);
    }

    public function test_confirmed_booking_is_never_expired(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip());

        $booking = $this->makePendingBooking($user, $schedule, ['A1']);
        $booking->update(['status' => 'confirmed']);
        $booking->forceFill([
            'created_at' => now()->subMinutes(Booking::PENDING_TTL_MINUTES + 30),
        ])->saveQuietly();

        app(ExpirePendingBookingsJob::class)->handle(app(BookingService::class));

        $this->assertSame('confirmed', $booking->fresh()->status);
    }
}
