<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SosWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function confirmedBooking(User $user, string $departure, string $return): Booking
    {
        $trip = Trip::create([
            'title' => 'SOS Trip',
            'slug' => 'sos-trip-'.uniqid(),
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
            'departure_date' => $departure,
            'return_date' => $return,
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

    public function test_sos_allowed_one_day_before_departure(): void
    {
        Bus::fake();
        // วันนี้ = 1 วันก่อนเดินทาง
        Carbon::setTestNow(Carbon::parse('2026-05-06 09:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertOk();

        $this->assertDatabaseHas('sos_alerts', [
            'user_id' => $user->id,
            'schedule_id' => $booking->schedule_id,
            'status' => 'active',
        ]);
    }

    public function test_sos_rejected_two_days_before_departure(): void
    {
        Bus::fake();
        // วันนี้ = 2 วันก่อนเดินทาง (ยังไม่ถึงช่วงที่อนุญาต)
        Carbon::setTestNow(Carbon::parse('2026-05-05 09:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->confirmedBooking($user, '2026-05-07', '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $booking->schedule_id])
            ->assertStatus(422);

        $this->assertDatabaseCount('sos_alerts', 0);
    }
}
