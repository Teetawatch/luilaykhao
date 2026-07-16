<?php

namespace Tests\Feature;

use App\Jobs\SendReviewInvitesJob;
use App\Models\Booking;
use App\Models\Review;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SendReviewInvitesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_invites_confirmed_traveller_once_the_review_window_opens(): void
    {
        $user = User::factory()->create();
        $booking = $this->createConfirmedBooking($user, '2026-05-08');

        // 20:00 Bangkok on the return day — the review window is open.
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        (new SendReviewInvitesJob)->handle();

        $this->assertSame(
            1,
            SmartNotification::where('user_id', $user->id)
                ->where('type', 'review_invite')
                ->where('data->booking_ref', $booking->booking_ref)
                ->count(),
        );

        // Running again must not send a second invite.
        (new SendReviewInvitesJob)->handle();

        $this->assertSame(
            1,
            SmartNotification::where('type', 'review_invite')->count(),
        );
    }

    public function test_does_not_invite_before_the_review_window_opens(): void
    {
        $user = User::factory()->create();
        $this->createConfirmedBooking($user, '2026-05-08');

        // One minute before the window opens.
        Carbon::setTestNow(Carbon::parse('2026-05-08 19:59:00', 'Asia/Bangkok'));

        (new SendReviewInvitesJob)->handle();

        $this->assertSame(0, SmartNotification::where('type', 'review_invite')->count());
    }

    public function test_does_not_invite_a_booking_that_was_already_reviewed(): void
    {
        $user = User::factory()->create();
        $booking = $this->createConfirmedBooking($user, '2026-05-08');

        Review::create([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'trip_id' => $booking->schedule->trip_id,
            'rating' => 5,
            'comment' => 'รีวิวไปแล้ว',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        (new SendReviewInvitesJob)->handle();

        $this->assertSame(0, SmartNotification::where('type', 'review_invite')->count());
    }

    private function createConfirmedBooking(User $user, string $returnDate): Booking
    {
        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-05-07',
            'return_date' => $returnDate,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
    }
}
