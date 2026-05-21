<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingModificationTest extends TestCase
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

    private function makeSchedule(Trip $trip, string $departure, array $overrides = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function makeSeatBooking(User $user, TripSchedule $schedule, array $seatIds): Booking
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

        return $booking->fresh(['seats', 'passengers', 'schedule']);
    }

    public function test_reschedule_moves_booking_keeps_price_and_assigns_new_seats(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $source = $this->makeSchedule($trip, now()->addMonth()->toDateString());
        $target = $this->makeSchedule($trip, now()->addMonths(2)->toDateString(), [
            'price_override' => 9999, // ราคารอบใหม่ต่างกัน — ต้องไม่ถูกนำมาคิด
        ]);

        $booking = $this->makeSeatBooking($user, $source, ['A1']);
        $originalTotal = $booking->total_amount;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", [
                'target_schedule_id' => $target->id,
                'seat_ids' => ['B2'],
            ])
            ->assertOk()
            ->assertJsonPath('data.schedule.id', $target->id);

        $booking->refresh();
        $this->assertSame($target->id, $booking->schedule_id);
        $this->assertEquals($originalTotal, $booking->total_amount); // คงราคาเดิม
        $this->assertDatabaseHas('booking_seats', [
            'booking_id' => $booking->id,
            'schedule_id' => $target->id,
            'seat_id' => 'B2',
        ]);
        $this->assertDatabaseMissing('booking_seats', [
            'booking_id' => $booking->id,
            'schedule_id' => $source->id,
        ]);
    }

    public function test_reschedule_fails_when_target_seat_already_booked(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $trip = $this->makeTrip();
        $source = $this->makeSchedule($trip, now()->addMonth()->toDateString());
        $target = $this->makeSchedule($trip, now()->addMonths(2)->toDateString());

        $booking = $this->makeSeatBooking($user, $source, ['A1']);
        $this->makeSeatBooking($other, $target, ['B2']); // B2 ถูกจองบนรอบปลายทางแล้ว

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", [
                'target_schedule_id' => $target->id,
                'seat_ids' => ['B2'],
            ])
            ->assertStatus(422);

        $this->assertSame($source->id, $booking->fresh()->schedule_id);
    }

    public function test_reschedule_fails_within_one_day_of_departure(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $source = $this->makeSchedule($trip, now()->toDateString()); // เดินทางวันนี้
        $target = $this->makeSchedule($trip, now()->addMonth()->toDateString());

        $booking = $this->makeSeatBooking($user, $source, ['A1']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", [
                'target_schedule_id' => $target->id,
                'seat_ids' => ['B2'],
            ])
            ->assertStatus(422);
    }

    public function test_reschedule_rejects_different_trip(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $otherTrip = Trip::create([
            'title' => 'Other', 'slug' => 'other-trip', 'type' => 'trekking',
            'location' => 'Pai', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => 1000, 'status' => 'active',
        ]);
        $source = $this->makeSchedule($trip, now()->addMonth()->toDateString());
        $target = $this->makeSchedule($otherTrip, now()->addMonths(2)->toDateString());

        $booking = $this->makeSeatBooking($user, $source, ['A1']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/reschedule", [
                'target_schedule_id' => $target->id,
                'seat_ids' => ['B2'],
            ])
            ->assertStatus(422);
    }

    public function test_change_pickup_updates_point_and_keeps_price(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, now()->addMonth()->toDateString());
        $pickup = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 1800,
            'sort_order' => 1,
        ]);

        $booking = $this->makeSeatBooking($user, $schedule, ['A1']);
        $originalTotal = $booking->total_amount;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/change-pickup", [
                'pickup_point_id' => $pickup->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.pickup_point.id', $pickup->id);

        $booking->refresh();
        $this->assertSame($pickup->id, $booking->pickup_point_id);
        $this->assertSame('bangkok', $booking->pickup_region);
        $this->assertEquals($originalTotal, $booking->total_amount);
    }

    public function test_change_pickup_rejects_point_from_other_schedule(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, now()->addMonth()->toDateString());
        $otherSchedule = $this->makeSchedule($trip, now()->addMonths(2)->toDateString());
        $foreignPickup = SchedulePickupPoint::create([
            'schedule_id' => $otherSchedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 1800,
            'sort_order' => 1,
        ]);

        $booking = $this->makeSeatBooking($user, $schedule, ['A1']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/change-pickup", [
                'pickup_point_id' => $foreignPickup->id,
            ])
            ->assertStatus(422);
    }
}
