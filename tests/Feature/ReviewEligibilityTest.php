<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReviewEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_review_can_only_be_created_after_return_date_8pm_bangkok_time(): void
    {
        $user = User::factory()->create();
        $booking = $this->createConfirmedBooking($user, '2026-05-08');

        Carbon::setTestNow(Carbon::parse('2026-05-08 19:59:00', 'Asia/Bangkok'));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'comment' => 'ยังไม่ควรรีวิวได้',
            ])
            ->assertStatus(422)
            ->assertJson([
                'message' => 'สามารถรีวิวได้หลังจบทริปวันสุดท้าย เวลา 20:00 น. เป็นต้นไป',
            ]);

        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'comment' => 'รีวิวได้แล้ว',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('reviews', [
            'booking_id' => $booking->id,
            'rating' => 5,
            'comment' => 'รีวิวได้แล้ว',
        ]);
    }

    public function test_booking_resource_marks_review_available_after_return_date_8pm(): void
    {
        $user = User::factory()->create();
        $booking = $this->createConfirmedBooking($user, '2026-05-08');

        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $booking->id)
            ->assertJsonPath('data.0.can_review', true)
            ->assertJsonPath('data.0.schedule.review_available_at', '2026-05-08T13:00:00.000000Z');
    }

    private function createConfirmedBooking(User $user, string $returnDate): Booking
    {
        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip-' . uniqid(),
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
            'booking_ref' => Booking::generateRef() . '-' . uniqid(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
    }
}
