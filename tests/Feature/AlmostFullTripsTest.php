<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlmostFullTripsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $title): Trip
    {
        return Trip::create([
            'title' => $title,
            'slug' => str()->slug($title).'-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
    }

    private function addSchedule(Trip $trip, int $total, string $status = 'open', int $daysAhead = 14): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays($daysAhead)->toDateString(),
            'return_date' => now()->addDays($daysAhead + 1)->toDateString(),
            'total_seats' => $total,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => $status,
        ]);
    }

    /** Occupy $count seats with a confirmed booking carrying $count passengers. */
    private function occupy(TripSchedule $schedule, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500 * $count,
        ]);
        for ($i = 0; $i < $count; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => 'Mr.',
                'name' => 'Pax '.$i,
                'phone' => '08100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }
    }

    private function tripRow(string $slug): ?array
    {
        return collect($this->getJson('/api/v1/trips')->assertOk()->json('data'))
            ->firstWhere('slug', $slug);
    }

    public function test_trip_resource_exposes_seats_left_and_almost_full_flag(): void
    {
        $trip = $this->makeTrip('Almost Full Trek');
        $this->occupy($this->addSchedule($trip, 10), 8); // 2 left

        $row = $this->tripRow($trip->slug);

        $this->assertSame(2, $row['seats_left']);
        $this->assertTrue($row['is_almost_full']);
    }

    public function test_booked_passengers_count_is_head_count_not_booking_count(): void
    {
        $trip = $this->makeTrip('Head Count Trek');
        $schedule = $this->addSchedule($trip, 20);
        // Two confirmed bookings carrying 3 + 2 travellers = 5 people across 2 bookings.
        $this->occupy($schedule, 3);
        $this->occupy($schedule, 2);

        $row = $this->tripRow($trip->slug);

        $this->assertSame(2, $row['bookings_count']);
        $this->assertSame(5, $row['booked_passengers_count']);
    }

    public function test_plenty_of_seats_is_not_almost_full(): void
    {
        $trip = $this->makeTrip('Plenty Trek');
        $this->occupy($this->addSchedule($trip, 10), 2); // 8 left

        $row = $this->tripRow($trip->slug);

        $this->assertSame(8, $row['seats_left']);
        $this->assertFalse($row['is_almost_full']);
    }

    public function test_full_trip_reports_null_seats_left(): void
    {
        $trip = $this->makeTrip('Full Trek');
        $this->occupy($this->addSchedule($trip, 10), 10); // full

        $row = $this->tripRow($trip->slug);

        $this->assertNull($row['seats_left']);
        $this->assertFalse($row['is_almost_full']);
    }

    public function test_almost_full_endpoint_lists_only_low_seat_trips_sorted_by_urgency(): void
    {
        $urgent = $this->makeTrip('Two Left');
        $this->occupy($this->addSchedule($urgent, 10), 8); // 2 left

        $soon = $this->makeTrip('Four Left');
        $this->occupy($this->addSchedule($soon, 10), 6); // 4 left

        $plenty = $this->makeTrip('Eight Left');
        $this->occupy($this->addSchedule($plenty, 10), 2); // 8 left — excluded

        $full = $this->makeTrip('No Seats');
        $this->occupy($this->addSchedule($full, 10), 10); // full — excluded

        $slugs = collect($this->getJson('/api/v1/trips/almost-full')->assertOk()->json('data'))
            ->pluck('slug');

        $this->assertSame([$urgent->slug, $soon->slug], $slugs->all());
    }

    public function test_almost_full_ignores_full_round_but_keeps_low_open_round(): void
    {
        $trip = $this->makeTrip('Mixed Rounds');
        $this->occupy($this->addSchedule($trip, 10, 'open', 14), 10); // full round
        $this->occupy($this->addSchedule($trip, 10, 'open', 30), 7);  // 3 left round

        $row = $this->tripRow($trip->slug);

        $this->assertSame(3, $row['seats_left']);
        $this->assertTrue($row['is_almost_full']);
        // The date must point at the near-full (3-left) round, not the soonest
        // (already full) one.
        $this->assertSame(now()->addDays(30)->toDateString(), $row['almost_full_date']);
    }
}
