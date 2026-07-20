<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\TripReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripReadinessTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'Doi '.uniqid(),
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'distance_km' => 20,
            'elevation_gain_m' => 1000,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $overrides));
    }

    /** ให้ผู้ใช้ "เคยเดินจบ" ทริปหนึ่ง เพื่อสร้างประวัติใน Passport */
    private function completeTrip(User $user, Trip $trip): void
    {
        $departure = now('Asia/Bangkok')->subDays(10)->toDateString();

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        Booking::create([
            'booking_ref' => sprintf('LLK-20200101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'completed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    public function test_guest_is_told_to_log_in(): void
    {
        $trip = $this->makeTrip();

        $this->getJson("/api/v1/trips/{$trip->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'not_logged_in');
    }

    public function test_trip_without_distance_or_elevation_cannot_be_evaluated(): void
    {
        $trip = $this->makeTrip(['distance_km' => null, 'elevation_gain_m' => null]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$trip->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'trip_data_missing');
    }

    public function test_user_without_history_or_baseline_is_asked_for_one(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$trip->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'no_baseline');
    }

    public function test_history_makes_a_similar_trip_comfortable(): void
    {
        $user = User::factory()->create();
        $this->completeTrip($user, $this->makeTrip(['distance_km' => 20, 'elevation_gain_m' => 1000]));

        $target = $this->makeTrip(['distance_km' => 18, 'elevation_gain_m' => 900]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$target->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.verdict', TripReadinessService::VERDICT_COMFORTABLE)
            ->assertJsonPath('data.source', 'history')
            ->assertJsonPath('data.you.max_distance_km', 20);
    }

    public function test_much_harder_trip_is_flagged_as_beyond(): void
    {
        $user = User::factory()->create();
        $this->completeTrip($user, $this->makeTrip(['distance_km' => 10, 'elevation_gain_m' => 400]));

        // 24 กม. เทียบ 10 กม. = 2.4 เท่า เกิน BEYOND_RATIO
        $target = $this->makeTrip(['distance_km' => 24, 'elevation_gain_m' => 500]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$target->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.verdict', TripReadinessService::VERDICT_BEYOND)
            ->assertJsonPath('data.comparison.distance_ratio', 2.4);
    }

    public function test_verdict_follows_the_hardest_dimension(): void
    {
        $user = User::factory()->create();
        $this->completeTrip($user, $this->makeTrip(['distance_km' => 20, 'elevation_gain_m' => 300]));

        // ระยะทางสบาย (0.5 เท่า) แต่ความชันหนักมาก (4 เท่า) → ต้องตัดสินว่าเกินตัว
        $target = $this->makeTrip(['distance_km' => 10, 'elevation_gain_m' => 1200]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$target->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.verdict', TripReadinessService::VERDICT_BEYOND);
    }

    public function test_self_reported_baseline_is_used_when_there_is_no_history(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/hiking-baseline', ['max_distance_km' => 12, 'max_elevation_gain_m' => 600])
            ->assertOk()
            ->assertJsonPath('data.source', 'self_reported');

        // 15/12 = 1.25 เท่า — เกิน STRETCH_RATIO แต่ยังไม่ถึง BEYOND_RATIO
        $target = $this->makeTrip(['distance_km' => 15, 'elevation_gain_m' => 620]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$target->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.source', 'self_reported')
            ->assertJsonPath('data.verdict', TripReadinessService::VERDICT_STRETCH);
    }

    public function test_real_history_wins_over_self_reported(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['self_reported_max_distance_km' => 3, 'self_reported_max_elevation_m' => 50])->save();
        $this->completeTrip($user, $this->makeTrip(['distance_km' => 30, 'elevation_gain_m' => 1500]));

        $target = $this->makeTrip(['distance_km' => 25, 'elevation_gain_m' => 1200]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$target->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.source', 'history')
            ->assertJsonPath('data.verdict', TripReadinessService::VERDICT_COMFORTABLE);
    }

    public function test_beyond_verdict_suggests_easier_trips_of_the_same_type(): void
    {
        $user = User::factory()->create();
        $this->completeTrip($user, $this->makeTrip(['distance_km' => 10, 'elevation_gain_m' => 400]));

        $easier = $this->makeTrip(['title' => 'Easy walk', 'distance_km' => 8, 'elevation_gain_m' => 200]);
        // ทริปคนละประเภทต้องไม่ถูกแนะนำ
        $this->makeTrip(['type' => 'diving', 'distance_km' => 5, 'elevation_gain_m' => 100]);

        $target = $this->makeTrip(['distance_km' => 40, 'elevation_gain_m' => 2000]);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$target->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.verdict', TripReadinessService::VERDICT_BEYOND);

        $slugs = array_column($res->json('data.alternatives'), 'slug');
        $this->assertContains($easier->slug, $slugs);
        $this->assertNotContains($target->slug, $slugs);
    }

    public function test_comfortable_verdict_does_not_suggest_alternatives(): void
    {
        $user = User::factory()->create();
        $this->completeTrip($user, $this->makeTrip(['distance_km' => 30, 'elevation_gain_m' => 1500]));
        $this->makeTrip(['distance_km' => 5, 'elevation_gain_m' => 100]);

        $target = $this->makeTrip(['distance_km' => 20, 'elevation_gain_m' => 900]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/trips/{$target->slug}/readiness")
            ->assertOk()
            ->assertJsonPath('data.alternatives', []);
    }

    public function test_baseline_requires_at_least_one_value(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/me/hiking-baseline', [])
            ->assertStatus(422);
    }

    public function test_baseline_rejects_absurd_values(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/me/hiking-baseline', ['max_elevation_gain_m' => 99000])
            ->assertStatus(422);
    }

    public function test_baseline_requires_authentication(): void
    {
        $this->postJson('/api/v1/me/hiking-baseline', ['max_distance_km' => 5])
            ->assertStatus(401);
    }
}
