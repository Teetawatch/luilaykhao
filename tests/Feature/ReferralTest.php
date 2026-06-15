<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\Referral;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        config(['referral.referrer_points' => 150, 'referral.referee_points' => 100]);
    }

    private function referrer(): User
    {
        return User::factory()->create();
    }

    private function registerFriend(string $code): User
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Friend',
            'title' => 'Mr.',
            'email' => 'friend@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'referral_code' => $code,
        ]);
        $response->assertCreated();

        return User::where('email', 'friend@gmail.com')->firstOrFail();
    }

    private function confirmFirstBooking(User $user): Booking
    {
        $trip = Trip::create([
            'title' => 'Test Trip', 'slug' => 'test-trip', 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 1500, 'status' => 'active',
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
                'title' => 'Mr.', 'name' => 'New Friend',
                'phone' => '0812345678', 'email' => 'friend@gmail.com',
            ]],
        );

        return $service->confirmBooking($booking, 'omise', 'pay_ref_1', $booking->total_amount);
    }

    public function test_registering_with_a_code_attributes_the_referrer(): void
    {
        $referrer = $this->referrer();
        $code = app(ReferralService::class)->codeFor($referrer);

        $friend = $this->registerFriend($code);

        $this->assertSame($referrer->id, $friend->referred_by);
        $this->assertDatabaseHas('referrals', [
            'referrer_id' => $referrer->id,
            'referred_user_id' => $friend->id,
            'status' => Referral::STATUS_PENDING,
        ]);
    }

    public function test_invalid_code_is_ignored_and_registration_still_succeeds(): void
    {
        $friend = $this->registerFriend('NOPENOPE');

        $this->assertNull($friend->referred_by);
        $this->assertDatabaseCount('referrals', 0);
    }

    public function test_first_paid_booking_rewards_both_sides_once(): void
    {
        $referrer = $this->referrer();
        $code = app(ReferralService::class)->codeFor($referrer);
        $friend = $this->registerFriend($code);

        $this->confirmFirstBooking($friend);

        $this->assertDatabaseHas('referrals', [
            'referred_user_id' => $friend->id,
            'status' => Referral::STATUS_REWARDED,
            'referrer_points' => 150,
            'referee_points' => 100,
        ]);
        $this->assertSame(150, LoyaltyAccount::forUser($referrer->id)->points);
        $this->assertSame(100, LoyaltyAccount::forUser($friend->id)->points);

        // Loyalty ledger records the earn for both users.
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $referrer->id, 'type' => 'earn', 'points' => 150,
        ]);
    }

    public function test_second_booking_does_not_double_reward(): void
    {
        $referrer = $this->referrer();
        $code = app(ReferralService::class)->codeFor($referrer);
        $friend = $this->registerFriend($code);

        $this->confirmFirstBooking($friend);
        // A second qualifying event must not pay out again.
        app(ReferralService::class)->qualifyFromBooking(
            Booking::where('user_id', $friend->id)->firstOrFail()
        );

        $this->assertSame(150, LoyaltyAccount::forUser($referrer->id)->points);
        $this->assertSame(1, Referral::where('referred_user_id', $friend->id)->count());
    }

    public function test_self_referral_is_rejected(): void
    {
        $user = User::factory()->create();
        $service = app(ReferralService::class);
        $code = $service->codeFor($user);

        $result = $service->attachReferrer($user, $code);

        $this->assertNull($result);
        $this->assertNull($user->fresh()->referred_by);
    }

    public function test_referral_endpoint_returns_code_and_summary(): void
    {
        $referrer = $this->referrer();
        $code = app(ReferralService::class)->codeFor($referrer);
        $friend = $this->registerFriend($code);
        $this->confirmFirstBooking($friend);

        $response = $this->actingAs($referrer)->getJson('/api/v1/referral');

        $response->assertOk()
            ->assertJsonPath('data.code', $code)
            ->assertJsonPath('data.referrer_points', 150)
            ->assertJsonPath('data.summary.invited', 1)
            ->assertJsonPath('data.summary.rewarded', 1)
            ->assertJsonPath('data.summary.points_earned', 150);
    }
}
