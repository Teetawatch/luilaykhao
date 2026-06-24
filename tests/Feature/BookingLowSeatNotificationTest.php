<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastNotificationJob;
use App\Models\BroadcastDispatch;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookingLowSeatNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Khao Sok Lake',
            'slug' => 'khao-sok-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Surat Thani',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1800,
            'status' => 'active',
        ]);
    }

    // booked_seats is recalculated from real bookings by syncBookedSeats(), so we
    // size total_seats such that a single fresh booking lands where we want it.
    private function makeSchedule(Trip $trip, int $total): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => $total,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function passenger(string $name = 'Somchai'): array
    {
        return [[
            'title' => 'Mr.',
            'name' => $name,
            'phone' => '0812345678',
            'email' => 'somchai@example.test',
        ]];
    }

    public function test_booking_into_the_low_band_blasts_low_seats_in_real_time(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        // 4 seats, empty — one booking drops it to 3 (the low band).
        $schedule = $this->makeSchedule($trip, total: 4);

        $user = User::factory()->create();
        app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passenger(),
        );

        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'low_seats',
            'dedupe_key' => "low_seats:{$schedule->id}:3",
        ]);
        Queue::assertPushed(
            fn (SendBroadcastNotificationJob $job) => $job->type === 'low_seats'
                && $job->data['schedule_id'] === $schedule->id,
        );
    }

    public function test_booking_that_keeps_seats_above_the_band_does_not_blast(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        // 10 seats, empty → booking one leaves 9, well above the threshold.
        $schedule = $this->makeSchedule($trip, total: 10);

        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passenger(),
        );

        $this->assertSame(0, BroadcastDispatch::where('event_type', 'low_seats')->count());
    }

    public function test_booking_that_sells_out_does_not_send_a_low_seat_blast(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, total: 1); // single seat → sells out

        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passenger(),
        );

        // Full → no low-seat blast (the "sold out" path handles this instead).
        $this->assertSame(0, BroadcastDispatch::where('event_type', 'low_seats')->count());
    }

    public function test_booking_that_sells_out_blasts_sold_out_to_everyone_and_subscribers(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, total: 1); // single seat → sells out

        $watcher = User::factory()->create();
        TripAlert::create([
            'user_id' => $watcher->id,
            'trip_id' => $trip->id,
            'low_seat_threshold' => 3,
        ]);

        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passenger(),
        );

        // Marketing broadcast to the whole base.
        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'sold_out',
            'dedupe_key' => "sold_out:{$schedule->id}",
        ]);
        Queue::assertPushed(
            fn (SendBroadcastNotificationJob $job) => $job->type === 'sold_out'
                && $job->data['schedule_id'] === $schedule->id,
        );

        // Trip subscriber gets a sold-out alert too.
        $note = SmartNotification::where('user_id', $watcher->id)
            ->where('type', 'trip_alert')
            ->first();
        $this->assertNotNull($note);
        $this->assertSame('sold_out', $note->data['alert_type']);
    }

    public function test_real_time_blast_also_reaches_trip_subscribers(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, total: 4); // empty → one booking leaves 3

        $watcher = User::factory()->create();
        TripAlert::create([
            'user_id' => $watcher->id,
            'trip_id' => $trip->id,
            'low_seat_threshold' => 3,
        ]);

        // One booking drops the round to 3 left (inside the watcher's threshold).
        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passenger(),
        );

        $note = SmartNotification::where('user_id', $watcher->id)
            ->where('type', 'trip_alert')
            ->first();
        $this->assertNotNull($note);
        $this->assertSame('low_seats', $note->data['alert_type']);
    }
}
