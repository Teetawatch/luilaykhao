<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\TripTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripTrackTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function booking(User $user, ?TripSchedule $schedule = null): Booking
    {
        $schedule ??= $this->schedule();

        return Booking::create([
            'booking_ref' => sprintf('LLK-20260101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'completed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Doi Test',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->subDays(10)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(9)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /** ทางขึ้นเนิน ~2.2 กม. ไต่ 400 ม. */
    private function points(): array
    {
        return [
            ['lat' => 18.5000, 'lng' => 98.5, 'ele' => 500],
            ['lat' => 18.5100, 'lng' => 98.5, 'ele' => 700],
            ['lat' => 18.5200, 'lng' => 98.5, 'ele' => 900],
        ];
    }

    public function test_uploading_a_track_computes_stats_on_the_server(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/track", [
                'points' => $this->points(),
                'moving_seconds' => 3600,
            ])
            ->assertOk()
            ->assertJsonPath('data.elevation_gain_m', 400)
            ->assertJsonPath('data.peers_count', 1)
            ->assertJsonPath('data.rank_by_distance', 1);

        $track = TripTrack::where('user_id', $user->id)->firstOrFail();
        $this->assertEqualsWithDelta(2.22, (float) $track->distance_km, 0.05);
    }

    public function test_client_supplied_distance_is_ignored(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/track", [
                'points' => $this->points(),
                // ตัวเลขปลอมจากฝั่ง client — ต้องไม่ถูกนำไปใช้
                'distance_km' => 999,
                'elevation_gain_m' => 99999,
            ])
            ->assertOk()
            ->assertJsonPath('data.elevation_gain_m', 400);

        $this->assertEqualsWithDelta(
            2.22,
            (float) TripTrack::where('user_id', $user->id)->value('distance_km'),
            0.05,
        );
    }

    public function test_uploading_twice_updates_the_same_track(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        foreach ([1, 2] as $_) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/bookings/{$booking->booking_ref}/track", [
                    'points' => $this->points(),
                ])
                ->assertOk();
        }

        $this->assertSame(1, TripTrack::where('user_id', $user->id)->count());
    }

    public function test_rank_compares_against_others_on_the_same_round(): void
    {
        $schedule = $this->schedule();
        $walker = User::factory()->create();
        $further = User::factory()->create();

        $walkerBooking = $this->booking($walker, $schedule);
        $furtherBooking = $this->booking($further, $schedule);

        $this->actingAs($walker, 'sanctum')
            ->postJson("/api/v1/bookings/{$walkerBooking->booking_ref}/track", [
                'points' => $this->points(),
            ])->assertOk();

        $this->actingAs($further, 'sanctum')
            ->postJson("/api/v1/bookings/{$furtherBooking->booking_ref}/track", [
                'points' => [
                    ['lat' => 18.5000, 'lng' => 98.5, 'ele' => 500],
                    ['lat' => 18.5500, 'lng' => 98.5, 'ele' => 900],
                ],
            ])->assertOk();

        $this->actingAs($walker, 'sanctum')
            ->getJson("/api/v1/bookings/{$walkerBooking->booking_ref}/track")
            ->assertOk()
            ->assertJsonPath('data.peers_count', 2)
            ->assertJsonPath('data.rank_by_distance', 2);
    }

    public function test_average_pace_is_returned_when_moving_time_is_known(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/track", [
                'points' => $this->points(),
                'moving_seconds' => 7200,
            ])->assertOk();

        $pace = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/track")
            ->assertOk()
            ->json('data.average_pace_kmh');

        // ~2.22 กม. ใน 2 ชม.
        $this->assertEqualsWithDelta(1.11, $pace, 0.05);
    }

    public function test_show_returns_null_before_anything_is_recorded(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/track")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_a_stranger_cannot_upload_a_track_to_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/track", [
                'points' => $this->points(),
            ])
            ->assertNotFound();

        $this->assertSame(0, TripTrack::count());
    }

    public function test_a_track_needs_at_least_two_points(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/track", [
                'points' => [['lat' => 18.5, 'lng' => 98.5, 'ele' => 500]],
            ])
            ->assertStatus(422);
    }

    public function test_passport_reports_recorded_stats_separately_from_route_estimates(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/track", [
                'points' => $this->points(),
                'moving_seconds' => 3600,
            ])->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport')
            ->assertOk()
            ->assertJsonPath('data.recorded.tracks_count', 1)
            ->assertJsonPath('data.recorded.elevation_gain_m', 400)
            ->assertJsonPath('data.recorded.highest_point_m', 900);
    }

    public function test_passport_recorded_block_is_empty_when_nothing_was_tracked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport')
            ->assertOk()
            ->assertJsonPath('data.recorded.tracks_count', 0)
            ->assertJsonPath('data.recorded.average_pace_kmh', null);
    }
}
