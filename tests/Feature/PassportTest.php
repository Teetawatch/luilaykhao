<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassportTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeCompletedBooking(User $user, array $tripOverrides = [], ?string $departure = null, string $status = 'completed'): Booking
    {
        $trip = Trip::create(array_merge([
            'title' => 'Doi '.uniqid(),
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Rai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'distance_km' => 20,
            'elevation_gain_m' => 1000,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $tripOverrides));

        $departure = $departure ?? now()->subDays(5)->toDateString();

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => sprintf('LLK-20200101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    public function test_passport_aggregates_lifetime_stats(): void
    {
        $user = User::factory()->create();
        $this->makeCompletedBooking($user, ['distance_km' => 20, 'elevation_gain_m' => 1000, 'region' => 'north', 'duration_days' => 2]);
        $this->makeCompletedBooking($user, ['distance_km' => 15.5, 'elevation_gain_m' => 800, 'region' => 'south', 'duration_days' => 3]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport')
            ->assertOk()
            ->assertJsonPath('data.stats.trips_count', 2)
            ->assertJsonPath('data.stats.total_distance_km', 35.5)
            ->assertJsonPath('data.stats.total_elevation_gain_m', 1800)
            ->assertJsonPath('data.stats.total_days', 5)
            ->assertJsonPath('data.stats.regions_count', 2)
            ->assertJsonPath('data.highlights.inthanon_multiple', 0.7);
    }

    public function test_passport_excludes_upcoming_and_cancelled_trips(): void
    {
        $user = User::factory()->create();
        $this->makeCompletedBooking($user); // completed, counts
        $this->makeCompletedBooking($user, departure: now()->addDays(10)->toDateString()); // future, excluded
        $this->makeCompletedBooking($user, status: 'cancelled'); // cancelled, excluded
        $this->makeCompletedBooking($user, status: 'pending'); // pending, excluded

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport')
            ->assertOk()
            ->assertJsonPath('data.stats.trips_count', 1);
    }

    public function test_passport_counts_trips_where_user_is_active_member(): void
    {
        $owner = User::factory()->create();
        $companion = User::factory()->create();
        $booking = $this->makeCompletedBooking($owner);

        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($companion, 'sanctum')
            ->getJson('/api/v1/me/passport')
            ->assertOk()
            ->assertJsonPath('data.stats.trips_count', 1);
    }

    public function test_passport_unlocks_expected_badges(): void
    {
        $user = User::factory()->create();
        $this->makeCompletedBooking($user, [
            'difficulty' => 'hard',
            'region' => 'north',
            'elevation_gain_m' => 3000,
        ], departure: '2026-07-05'); // July = rainy season

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport')
            ->assertOk();

        $badges = collect($response->json('data.badges'))->keyBy('key');

        $this->assertTrue($badges['first_summit']['earned']);
        $this->assertTrue($badges['hardcore']['earned']);
        $this->assertTrue($badges['rainy_soul']['earned']);
        $this->assertTrue($badges['elev_inthanon']['earned']); // 3000 >= 2565
        $this->assertFalse($badges['hunter_10']['earned']);
        $this->assertFalse($badges['winter_soul']['earned']);
        // ตราแบบสะสมยอดพก progress (current/target); ตราพิเศษเป็น null
        $this->assertEquals(1, $badges['explorer_5']['progress']['current']);
        $this->assertEquals(5, $badges['explorer_5']['progress']['target']);
        $this->assertNull($badges['hardcore']['progress']);
    }

    public function test_passport_reports_when_each_badge_was_unlocked(): void
    {
        $user = User::factory()->create();
        // ทริปแรก (เก่ากว่า) ปลด "ก้าวแรก"; ระยะทางรวมยังไม่ถึง 100
        $this->makeCompletedBooking($user, ['distance_km' => 40], departure: '2026-01-10');
        // ทริปที่สอง (ใหม่กว่า) ดันระยะทางสะสมข้าม 100 → dist_100 ปลดที่วันนี้
        $this->makeCompletedBooking($user, ['distance_km' => 70], departure: '2026-03-20');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport')
            ->assertOk();

        $badges = collect($response->json('data.badges'))->keyBy('key');

        $this->assertSame('2026-01-10', $badges['first_summit']['earned_at']);
        $this->assertSame('2026-03-20', $badges['dist_100']['earned_at']);
        $this->assertNull($badges['dist_500']['earned_at']);
        $this->assertFalse($badges['dist_500']['earned']);
    }

    public function test_passport_dedupes_same_round_for_owner_and_member(): void
    {
        $user = User::factory()->create();
        $booking = $this->makeCompletedBooking($user);

        // ผู้ใช้เป็นทั้งเจ้าของและถูกบันทึกเป็น member ในการจองเดียวกัน — ต้องนับรอบเดียว
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/passport')
            ->assertOk()
            ->assertJsonPath('data.stats.trips_count', 1);
    }

    public function test_passport_requires_auth(): void
    {
        $this->getJson('/api/v1/me/passport')->assertUnauthorized();
    }
}
