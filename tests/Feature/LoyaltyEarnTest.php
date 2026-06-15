<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoyaltyEarnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['loyalty.baht_per_point' => 100]);
    }

    private function confirmBooking(User $user, float $pricePerPerson): Booking
    {
        $trip = Trip::create([
            'title' => 'Test Trip', 'slug' => 'test-trip', 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => $pricePerPerson, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id, 'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(), 'total_seats' => 10,
            'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        $service = app(BookingService::class);
        $booking = $service->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'Mr.', 'name' => 'Traveller',
                'phone' => '0812345678', 'email' => 'traveller@example.test',
            ]],
        );

        return $service->confirmBooking($booking, 'omise', 'pay_ref', $booking->total_amount);
    }

    public function test_confirming_a_booking_awards_one_point_per_100_baht(): void
    {
        $user = User::factory()->create();

        $this->confirmBooking($user, 1500); // total 1,500 → 15 points

        $this->assertSame(15, LoyaltyAccount::forUser($user->id)->points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $user->id,
            'type' => 'earn',
            'points' => 15,
        ]);
    }

    public function test_points_are_not_granted_twice_for_the_same_booking(): void
    {
        $user = User::factory()->create();
        $booking = $this->confirmBooking($user, 1500);

        // Re-running the award (e.g. a retry) must not double-credit.
        app(LoyaltyService::class)->awardForBooking($booking->fresh());

        $this->assertSame(15, LoyaltyAccount::forUser($user->id)->points);
        $this->assertSame(
            1,
            LoyaltyAccount::forUser($user->id)
                ->transactions()
                ->where('type', 'earn')
                ->count(),
        );
    }

    public function test_amounts_below_the_rate_earn_zero_points(): void
    {
        $user = User::factory()->create();

        $this->confirmBooking($user, 80); // total 80 → 0 points

        $this->assertSame(0, LoyaltyAccount::forUser($user->id)->points);
    }

    public function test_guest_booking_without_account_is_skipped(): void
    {
        $booking = new Booking(['user_id' => null, 'total_amount' => 1500]);

        // Should no-op without touching the database.
        app(LoyaltyService::class)->awardForBooking($booking);

        $this->assertDatabaseCount('loyalty_transactions', 0);
    }
}
