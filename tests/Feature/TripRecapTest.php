<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripPost;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TripRecapTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(array $tripOverrides = [], ?string $departure = null): Booking
    {
        Mail::fake();

        $trip = Trip::create(array_merge([
            'title' => 'Doi Luang',
            'slug' => 'doi-luang-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Rai',
            'region' => 'north',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'distance_km' => 24.5,
            'elevation_gain_m' => 1800,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $tripOverrides));

        $departure = $departure ?? now()->subDays(3)->toDateString();

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $user = User::factory()->create();

        return app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [
                ['title' => 'Mr.', 'name' => 'A', 'phone' => '0812345678', 'email' => 'a@example.test'],
                ['title' => 'Ms.', 'name' => 'B', 'phone' => '0812345679', 'email' => 'b@example.test'],
            ],
        )->fresh();
    }

    public function test_recap_returns_trip_stats_for_owner(): void
    {
        $booking = $this->makeBooking();

        $response = $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/recap");

        $response->assertOk()
            ->assertJsonPath('data.trip.title', 'Doi Luang')
            ->assertJsonPath('data.trip.difficulty_label', 'สายโหด')
            ->assertJsonPath('data.duration_days', 3)
            ->assertJsonPath('data.distance_km', 24.5)
            ->assertJsonPath('data.elevation_gain_m', 1800)
            ->assertJsonPath('data.group_size', 2)
            ->assertJsonPath('data.trip_completed', true)
            ->assertJsonPath('data.has_reviewed', false);
    }

    public function test_recap_includes_top_liked_feed_photos(): void
    {
        $booking = $this->makeBooking();
        $schedule = $booking->schedule;

        TripPost::create([
            'trip_id' => $schedule->trip_id,
            'schedule_id' => $schedule->id,
            'user_id' => $booking->user_id,
            'caption' => 'low',
            'photos' => [['disk' => 'public', 'path' => 'a.jpg', 'url' => 'https://cdn.test/a.jpg']],
            'likes_count' => 1,
            'status' => TripPost::STATUS_PUBLISHED,
        ]);
        TripPost::create([
            'trip_id' => $schedule->trip_id,
            'schedule_id' => $schedule->id,
            'user_id' => $booking->user_id,
            'caption' => 'high',
            'photos' => [['disk' => 'public', 'path' => 'b.jpg', 'url' => 'https://cdn.test/b.jpg']],
            'likes_count' => 99,
            'status' => TripPost::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/recap");

        $response->assertOk()
            ->assertJsonPath('data.photos.0', 'https://cdn.test/b.jpg')
            ->assertJsonPath('data.photos.1', 'https://cdn.test/a.jpg');
    }

    public function test_recap_flags_upcoming_trip_as_not_completed(): void
    {
        $booking = $this->makeBooking(departure: now()->addDays(5)->toDateString());

        $response = $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/recap");

        $response->assertOk()
            ->assertJsonPath('data.trip_completed', false);
    }

    public function test_recap_forbidden_for_unrelated_user(): void
    {
        $booking = $this->makeBooking();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/recap")
            ->assertStatus(404);
    }

    public function test_recap_rejects_cancelled_booking(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => 'cancelled']);

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/recap")
            ->assertStatus(422);
    }
}
