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

    public function test_review_accepts_optional_breakdown_ratings_and_trip_resource_averages_them(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        $userA = User::factory()->create();
        $bookingA = $this->createConfirmedBooking($userA, '2026-05-08');
        $tripId = $bookingA->schedule->trip_id;

        // Full breakdown
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $bookingA->id,
                'rating' => 5,
                'rating_guide' => 5,
                'rating_vehicle' => 4,
                'rating_food' => 5,
                'rating_value' => 4,
                'comment' => 'breakdown ครบ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating_guide', 5)
            ->assertJsonPath('data.rating_value', 4);

        // Same trip, no breakdown (optional)
        $userB = User::factory()->create();
        $bookingB = $this->createConfirmedBookingForTrip($userB, $bookingA->schedule->trip, '2026-05-08');
        $this->actingAs($userB, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $bookingB->id,
                'rating' => 3,
                'comment' => 'ไม่กรอก breakdown',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating_guide', null);

        // Trip resource averages only the reviews that rated each category
        $this->getJson("/api/v1/trips/{$bookingA->schedule->trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.rating_breakdown.guide', 5)
            ->assertJsonPath('data.rating_breakdown.vehicle', 4)
            ->assertJsonPath('data.rating_breakdown.value', 4);
    }

    public function test_review_accepts_up_to_six_images_and_rejects_more(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));

        $user = User::factory()->create();
        $booking = $this->createConfirmedBooking($user, '2026-05-08');

        $sixImages = array_map(
            fn ($i) => "/storage/reviews/photo-{$i}.jpg",
            range(1, 6),
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'comment' => 'แนบรูปหกภาพ',
                'images' => $sixImages,
            ])
            ->assertCreated()
            ->assertJsonCount(6, 'data.images');

        // A seventh image is over the limit.
        $userB = User::factory()->create();
        $bookingB = $this->createConfirmedBooking($userB, '2026-05-08');

        $this->actingAs($userB, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $bookingB->id,
                'rating' => 5,
                'comment' => 'แนบรูปเจ็ดภาพ',
                'images' => array_merge($sixImages, ['/storage/reviews/photo-7.jpg']),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');
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

    public function test_long_past_trip_is_reviewable_until_reviewed(): void
    {
        // ทริปที่จบไปแล้วนาน (return_date ในอดีต) ต้องยังรีวิวได้
        $user = User::factory()->create();
        $booking = $this->createConfirmedBooking($user, '2026-05-08');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.can_review', true)
            ->assertJsonPath('data.0.has_reviewed', false);

        // หลังรีวิวแล้ว can_review ต้องเป็น false และ has_reviewed เป็น true
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'comment' => 'จบทริปแล้วมารีวิว',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.can_review', false)
            ->assertJsonPath('data.0.has_reviewed', true);
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

    private function createConfirmedBookingForTrip(User $user, Trip $trip, string $returnDate): Booking
    {
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
