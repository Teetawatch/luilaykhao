<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AbandonedBookingWinbackTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Doi Inthanon',
            'slug' => 'doi-inthanon-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1800,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, int $total = 4): TripSchedule
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

    private function abandonedBooking(
        User $user,
        TripSchedule $schedule,
        \DateTimeInterface $cancelledAt,
    ): Booking {
        return Booking::create([
            'booking_ref' => 'LLK-'.strtoupper(uniqid()),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'cancelled',
            'total_amount' => 1800,
            'was_auto_expired' => true,
            'cancelled_at' => $cancelledAt,
        ]);
    }

    public function test_sends_one_winback_for_a_lapsed_booking_on_an_open_round(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $user = User::factory()->create();
        $booking = $this->abandonedBooking($user, $schedule, now()->subHours(3));

        $sent = app(BookingService::class)->sendAbandonedWinbacks();

        $this->assertSame(1, $sent);

        $note = SmartNotification::where('user_id', $user->id)
            ->where('type', 'booking_winback')
            ->first();
        $this->assertNotNull($note);
        $this->assertSame($trip->slug, $note->data['trip_slug']);

        $this->assertNotNull($booking->fresh()->winback_sent_at);

        // Idempotent — a second pass sends nothing more.
        $this->assertSame(0, app(BookingService::class)->sendAbandonedWinbacks());
    }

    public function test_does_not_winback_too_soon(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $user = User::factory()->create();
        // Lapsed only one hour ago — still inside the 2h cool-off.
        $this->abandonedBooking($user, $schedule, now()->subHour());

        $this->assertSame(0, app(BookingService::class)->sendAbandonedWinbacks());
        $this->assertSame(
            0,
            SmartNotification::where('type', 'booking_winback')->count(),
        );
    }

    public function test_skips_when_customer_already_rebooked_the_round(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $user = User::factory()->create();
        $booking = $this->abandonedBooking($user, $schedule, now()->subHours(3));

        // Same user now holds a live booking on the same round.
        Booking::create([
            'booking_ref' => 'LLK-'.strtoupper(uniqid()),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1800,
        ]);

        $this->assertSame(0, app(BookingService::class)->sendAbandonedWinbacks());
        $this->assertSame(
            0,
            SmartNotification::where('type', 'booking_winback')->count(),
        );
        // Closed out so it's never reconsidered.
        $this->assertNotNull($booking->fresh()->winback_sent_at);
    }

    public function test_skips_when_the_round_is_no_longer_bookable(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $soldOut = $this->makeSchedule($trip, total: 0); // no seats → not bookable
        $user = User::factory()->create();
        $this->abandonedBooking($user, $soldOut, now()->subHours(3));

        $this->assertSame(0, app(BookingService::class)->sendAbandonedWinbacks());
        $this->assertSame(
            0,
            SmartNotification::where('type', 'booking_winback')->count(),
        );
    }
}
