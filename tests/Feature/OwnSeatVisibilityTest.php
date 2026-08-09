<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ที่นั่งที่ลูกค้าถือไว้เอง (ล็อกไว้ / อยู่ในใบจองของตัวเอง) ต้องแยกออกจากที่นั่ง
 * ของคนอื่นให้ชัด — ลูกค้าที่จองคนเดียวเคยเห็นแค่ "จองแล้ว" แล้วเข้าใจว่ามีคนอื่นแย่งไป
 */
class OwnSeatVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'own-seat-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'Van 1',
            'type' => 'van',
            'capacity' => 12,
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function passengersFor(array $seatIds): array
    {
        return collect($seatIds)->map(fn ($seatId, $i) => [
            'title' => 'Mr.',
            'name' => "Passenger {$i}",
            'phone' => '0812345678',
            'email' => "p{$i}@example.test",
        ])->all();
    }

    public function test_seat_map_marks_the_callers_own_booked_seats(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $booking = app(BookingService::class)->createBooking(
            userId: $owner->id,
            scheduleId: $schedule->id,
            passengers: $this->passengersFor(['A1', 'A2']),
            seatIds: ['A1', 'A2'],
        );

        $ownerView = $this->actingAs($owner)
            ->getJson("/api/v1/schedules/{$schedule->id}/seats")
            ->assertOk()
            ->json('data.seats');

        $ownSeat = collect($ownerView)->firstWhere('id', 'A1');
        $this->assertSame('booked', $ownSeat['status']);
        $this->assertTrue($ownSeat['booked_by_current_user']);
        $this->assertSame($booking->booking_ref, $ownSeat['booking_ref']);

        // คนอื่นต้องไม่เห็นทั้งธงและเลขที่ใบจอง
        $strangerView = $this->actingAs($stranger)
            ->getJson("/api/v1/schedules/{$schedule->id}/seats")
            ->assertOk()
            ->json('data.seats');

        $sameSeat = collect($strangerView)->firstWhere('id', 'A1');
        $this->assertSame('booked', $sameSeat['status']);
        $this->assertFalse($sameSeat['booked_by_current_user']);
        $this->assertNull($sameSeat['booking_ref']);
    }

    public function test_free_seats_carry_the_ownership_flags_as_false(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();

        $seats = $this->actingAs($user)
            ->getJson("/api/v1/schedules/{$schedule->id}/seats")
            ->assertOk()
            ->json('data.seats');

        $seat = collect($seats)->firstWhere('id', 'A1');
        $this->assertSame('available', $seat['status']);
        $this->assertFalse($seat['booked_by_current_user']);
        $this->assertFalse($seat['locked_by_current_user']);
        $this->assertNull($seat['booking_ref']);
    }

    public function test_rebooking_your_own_seats_says_they_are_already_yours(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $service = app(BookingService::class);

        $booking = $service->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengersFor(['A1', 'A2']),
            seatIds: ['A1', 'A2'],
        );

        try {
            $service->createBooking(
                userId: $user->id,
                scheduleId: $schedule->id,
                passengers: $this->passengersFor(['A1', 'A2']),
                seatIds: ['A1', 'A2'],
            );
            $this->fail('คาดว่าจะ throw exception เพราะที่นั่งอยู่ในใบจองของตัวเองแล้ว');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ของคุณอยู่แล้ว', $e->getMessage());
            $this->assertStringContainsString($booking->booking_ref, $e->getMessage());
            $this->assertStringNotContainsString('ถูกจองไปแล้ว', $e->getMessage());
        }
    }

    public function test_seats_held_by_someone_else_still_read_as_taken(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(BookingService::class);

        $service->createBooking(
            userId: $owner->id,
            scheduleId: $schedule->id,
            passengers: $this->passengersFor(['A1']),
            seatIds: ['A1'],
        );

        try {
            $service->createBooking(
                userId: $other->id,
                scheduleId: $schedule->id,
                passengers: $this->passengersFor(['A1']),
                seatIds: ['A1'],
            );
            $this->fail('คาดว่าจะ throw exception เพราะที่นั่งถูกคนอื่นจองไปแล้ว');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ถูกจองไปแล้ว', $e->getMessage());
            $this->assertStringNotContainsString('ของคุณ', $e->getMessage());
        }
    }
}
