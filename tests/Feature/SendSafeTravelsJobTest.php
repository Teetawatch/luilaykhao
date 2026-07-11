<?php

namespace Tests\Feature;

use App\Jobs\SendSafeTravelsJob;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SendSafeTravelsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_wishes_confirmed_traveller_safe_travels_once_the_trip_wraps_up(): void
    {
        $user = User::factory()->create();
        $booking = $this->createConfirmedBooking($user, '2026-05-08');

        // 20:15 Bangkok on the return day — the trip has wrapped up.
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:15:00', 'Asia/Bangkok'));

        (new SendSafeTravelsJob)->handle();

        $this->assertSame(
            1,
            SmartNotification::where('user_id', $user->id)
                ->where('type', 'safe_travels')
                ->where('data->booking_ref', $booking->booking_ref)
                ->count(),
        );

        // Running again must not send a second message.
        (new SendSafeTravelsJob)->handle();

        $this->assertSame(
            1,
            SmartNotification::where('type', 'safe_travels')->count(),
        );
    }

    public function test_does_not_send_before_the_trip_wraps_up(): void
    {
        $user = User::factory()->create();
        $this->createConfirmedBooking($user, '2026-05-08');

        // Before the 20:00 window opens.
        Carbon::setTestNow(Carbon::parse('2026-05-08 19:59:00', 'Asia/Bangkok'));

        (new SendSafeTravelsJob)->handle();

        $this->assertSame(0, SmartNotification::where('type', 'safe_travels')->count());
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
            'booking_ref' => Booking::generateRef().'-'.uniqid(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
    }
}
