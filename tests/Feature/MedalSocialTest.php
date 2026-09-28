<?php

namespace Tests\Feature;

use App\Jobs\AwardTripMedalsJob;
use App\Models\Booking;
use App\Models\ChallengeCompletion;
use App\Models\MedalKudos;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripMedal;
use App\Models\TripSchedule;
use App\Models\TripTrack;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\ChallengeService;
use App\Services\MedalService;
use App\Services\YearReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MedalSocialTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBangkok('2026-09-20 12:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function travelToBangkok(string $at): void
    {
        $now = CarbonImmutable::parse($at, 'Asia/Bangkok');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ดอยหลวงเชียงดาว 3 วัน 2 คืน',
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'region' => 'north',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'distance_km' => 20,
            'elevation_gain_m' => 1500,
            'max_participants' => 12,
            'price_per_person' => 5900,
            'status' => 'active',
        ], $overrides));
    }

    /** รอบที่จบแล้ว + ใบจองของทุกคน แล้วแจกเหรียญ */
    private function finishRound(Trip $trip, string $date, array $users): TripSchedule
    {
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $date,
            'return_date' => $date,
            'total_seats' => 12,
            'booked_seats' => count($users),
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        foreach ($users as $user) {
            Booking::create([
                'booking_ref' => sprintf('LLK-20260901-%04d', ++$this->refSeq),
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'status' => 'confirmed',
                'total_amount' => 5900,
                'paid_amount' => 5900,
            ]);
        }

        app(MedalService::class)->awardSchedule($schedule);

        return $schedule;
    }

    private function medalOf(User $user): TripMedal
    {
        return TripMedal::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    // ── ปรบมือ ───────────────────────────────────────────────────────────────

    public function test_round_board_lists_everyone_who_finished_that_round(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create();
        $outsider = User::factory()->create();
        $trip = $this->trip();
        $this->finishRound($trip, '2026-09-05', [$a, $b, $c]);
        $this->finishRound($trip, '2026-09-12', [$outsider]);

        $this->actingAs($a, 'sanctum')
            ->getJson('/api/v1/me/medals/'.$this->medalOf($a)->id.'/round')
            ->assertOk()
            ->assertJsonCount(3, 'data.finishers')
            ->assertJsonPath('data.finishers.0.is_me', true)
            ->assertJsonPath('data.finishers.0.finisher_no', 1)
            ->assertJsonPath('data.trip_name', 'ดอยหลวงเชียงดาว');

        // เหรียญของคนอื่นใช้เปิดกระดานไม่ได้ — ต้องเป็นเหรียญของตัวเอง
        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/v1/me/medals/'.$this->medalOf($a)->id.'/round')
            ->assertNotFound();
    }

    public function test_kudos_toggles_and_only_works_between_round_mates(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $outsider = User::factory()->create();
        $trip = $this->trip();
        $this->finishRound($trip, '2026-09-05', [$a, $b]);
        $this->finishRound($trip, '2026-09-12', [$outsider]);
        $target = $this->medalOf($b);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/me/medals/{$target->id}/kudos")
            ->assertOk()
            ->assertJsonPath('data.kudoed', true)
            ->assertJsonPath('data.kudos_count', 1);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/me/medals/{$target->id}/kudos")
            ->assertOk()
            ->assertJsonPath('data.kudoed', false)
            ->assertJsonPath('data.kudos_count', 0);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/me/medals/{$target->id}/kudos")
            ->assertForbidden();

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/me/medals/{$target->id}/kudos")
            ->assertForbidden()
            ->assertJsonPath('message', 'ปรบมือให้เหรียญของตัวเองไม่ได้');
    }

    public function test_kudos_push_is_throttled_per_medal(): void
    {
        [$owner, $x, $y, $z] = User::factory()->count(4)->create();
        $this->finishRound($this->trip(), '2026-09-05', [$owner, $x, $y, $z]);
        $target = $this->medalOf($owner);

        foreach ([$x, $y] as $giver) {
            $this->actingAs($giver, 'sanctum')->postJson("/api/v1/me/medals/{$target->id}/kudos")->assertOk();
        }

        $this->assertSame(1, SmartNotification::where('user_id', $owner->id)->where('type', 'medal_kudos')->count());

        $this->travelToBangkok('2026-09-20 13:00');
        $this->actingAs($z, 'sanctum')->postJson("/api/v1/me/medals/{$target->id}/kudos")->assertOk();

        $this->assertSame(2, SmartNotification::where('user_id', $owner->id)->where('type', 'medal_kudos')->count());
    }

    public function test_blocked_users_disappear_from_the_board_and_cannot_kudos(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create();
        $this->finishRound($this->trip(), '2026-09-05', [$a, $b, $c]);
        UserBlock::create(['blocker_id' => $a->id, 'blocked_id' => $b->id]);

        $names = collect(
            $this->actingAs($a, 'sanctum')
                ->getJson('/api/v1/me/medals/'.$this->medalOf($a)->id.'/round')
                ->json('data.finishers'),
        )->pluck('medal_id');

        $this->assertNotContains($this->medalOf($b)->id, $names);
        $this->assertCount(2, $names);

        // ฝ่ายที่ถูกบล็อกก็ปรบมือให้ไม่ได้ (บล็อกเป็นสองทาง)
        $this->actingAs($b, 'sanctum')
            ->postJson('/api/v1/me/medals/'.$this->medalOf($a)->id.'/kudos')
            ->assertForbidden();
    }

    public function test_cabinet_shows_kudos_count_and_recent_names(): void
    {
        $owner = User::factory()->create();
        $fan = User::factory()->create(['nickname' => 'เจ']);
        $this->finishRound($this->trip(), '2026-09-05', [$owner, $fan]);
        MedalKudos::create(['medal_id' => $this->medalOf($owner)->id, 'user_id' => $fan->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/me/medals')
            ->assertOk()
            ->assertJsonPath('data.medals.0.kudos_count', 1)
            ->assertJsonPath('data.medals.0.kudos_recent.0', 'เจ');
    }

    // ── ชาเลนจ์ ──────────────────────────────────────────────────────────────

    public function test_challenges_track_progress_and_record_completion_date(): void
    {
        $user = User::factory()->create();
        $trip = $this->trip(['elevation_gain_m' => 600]);
        $this->finishRound($trip, '2026-09-03', [$user]);
        $this->finishRound($trip, '2026-09-10', [$user]);

        $data = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/challenges')->assertOk()->json('data');

        $month = collect($data['month']['challenges'])->keyBy('key');
        $this->assertSame('2026-09', $data['month']['period']);
        $this->assertTrue($month['month_trip']['completed']);
        $this->assertSame('2026-09-03', $month['month_trip']['completed_on']);
        // 600 + 600 = 1,200 ≥ 1,000 — ถึงเป้าในทริปที่สอง
        $this->assertTrue($month['month_climb']['completed']);
        $this->assertSame('2026-09-10', $month['month_climb']['completed_on']);

        $year = collect($data['year']['challenges'])->keyBy('key');
        $this->assertEqualsWithDelta(40.0, $year['year_distance']['current'], 0.01);
        $this->assertEqualsWithDelta(0.4, $year['year_distance']['progress'], 0.001);
        $this->assertFalse($year['year_distance']['completed']);
        $this->assertSame(2.0, (float) $year['year_trips']['current']);
        $this->assertSame('ปี 2569', $data['year']['label']);

        $this->assertSame(2, ChallengeCompletion::where('user_id', $user->id)->count());
        $this->assertCount(2, $data['history']);
    }

    public function test_challenges_prefer_the_gps_numbers(): void
    {
        $user = User::factory()->create();
        $schedule = $this->finishRound($this->trip(['distance_km' => 20]), '2026-09-05', [$user]);
        TripTrack::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'points' => [
                ['lat' => 19.4, 'lng' => 98.9, 'ele' => 900, 'km' => 0],
                ['lat' => 19.41, 'lng' => 98.9, 'ele' => 1000, 'km' => 1],
                ['lat' => 19.42, 'lng' => 98.91, 'ele' => 1100, 'km' => 2],
                ['lat' => 19.43, 'lng' => 98.91, 'ele' => 1300, 'km' => 3],
                ['lat' => 19.44, 'lng' => 98.92, 'ele' => 1500, 'km' => 24],
            ],
            'distance_km' => 24,
            'elevation_gain_m' => 1600,
            'max_elevation_m' => 2170,
            'moving_seconds' => 30000,
        ]);

        $year = collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/challenges')->json('data.year.challenges'),
        )->keyBy('key');

        $this->assertEqualsWithDelta(24.0, $year['year_distance']['current'], 0.01);
    }

    public function test_regions_count_distinct_regions_and_countries(): void
    {
        $user = User::factory()->create();
        $this->finishRound($this->trip(['region' => 'north']), '2026-02-01', [$user]);
        $this->finishRound($this->trip(['region' => 'north']), '2026-03-01', [$user]);
        $this->finishRound($this->trip(['region' => 'south']), '2026-04-01', [$user]);
        $this->finishRound($this->trip([
            'destination_type' => 'international',
            'region' => null,
            'country_code' => 'LA',
        ]), '2026-05-01', [$user]);

        $year = collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/challenges')->json('data.year.challenges'),
        )->keyBy('key');

        $this->assertSame(3.0, (float) $year['year_regions']['current']);
        $this->assertTrue($year['year_regions']['completed']);
        $this->assertSame('2026-05-01', $year['year_regions']['completed_on']);
    }

    public function test_challenge_push_waits_until_noon_and_is_silent_for_old_ones(): void
    {
        $recent = User::factory()->create();
        $old = User::factory()->create();
        $trip = $this->trip();

        $this->travelToBangkok('2026-09-19 21:00');
        $this->finishRound($trip, '2026-09-19', [$recent]);
        $this->finishRound($trip, '2025-01-10', [$old]);

        $service = app(ChallengeService::class);
        $service->sync($recent->id);
        $service->sync($old->id);

        $this->assertSame(0, $service->notifyPending());

        $this->travelToBangkok('2026-09-20 11:59');
        $this->assertSame(0, $service->notifyPending());

        $this->travelToBangkok('2026-09-20 12:05');
        $this->assertSame(1, $service->notifyPending());
        $this->assertSame(0, $service->notifyPending());

        // ทริปเดียวสำเร็จสองชาเลนจ์ (ลุยประจำเดือน + ไต่ 1,000 ม.) → push เดียว
        $push = SmartNotification::where('type', 'challenge_completed')->sole();
        $this->assertSame($recent->id, $push->user_id);
        $this->assertStringContainsString('2 ชาเลนจ์', $push->title);
        $this->assertSame(0, ChallengeCompletion::whereNull('notified_at')->count());
    }

    public function test_hourly_job_records_challenges_for_people_who_just_got_a_medal(): void
    {
        $user = User::factory()->create();
        $schedule = TripSchedule::create([
            'trip_id' => $this->trip()->id,
            'departure_date' => '2026-09-18',
            'return_date' => '2026-09-18',
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
        Booking::create([
            'booking_ref' => 'LLK-20260901-9999',
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 5900,
            'paid_amount' => 5900,
        ]);

        (new AwardTripMedalsJob)->handle(app(MedalService::class), app(ChallengeService::class));

        $this->assertTrue(
            ChallengeCompletion::where('user_id', $user->id)->where('challenge_key', 'month_trip')->exists(),
        );
    }

    // ── สรุปทั้งปี ───────────────────────────────────────────────────────────

    public function test_year_review_sums_the_year_and_ignores_other_years(): void
    {
        $user = User::factory()->create(['nickname' => 'ต้น']);
        $mate = User::factory()->create();
        $this->finishRound($this->trip(['distance_km' => 20, 'elevation_gain_m' => 1500, 'duration_days' => 3]), '2026-03-10', [$user, $mate]);
        $this->finishRound($this->trip(['distance_km' => 32.5, 'elevation_gain_m' => 1200, 'duration_days' => 4, 'region' => 'south']), '2026-09-05', [$user]);
        $this->finishRound($this->trip(), '2025-12-01', [$user]);
        MedalKudos::create(['medal_id' => $this->medalOf($user)->id, 'user_id' => $mate->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/year-review?year=2026')
            ->assertOk()
            ->assertJsonPath('data.year_label', '2569')
            ->assertJsonPath('data.holder_name', 'ต้น')
            ->assertJsonPath('data.trips_count', 2)
            ->assertJsonPath('data.distance_km', 52.5)
            ->assertJsonPath('data.climb_m', 2700)
            ->assertJsonPath('data.inthanon_multiple', 1.1)
            ->assertJsonPath('data.days_on_trail', 7)
            ->assertJsonPath('data.months_active', 2)
            ->assertJsonPath('data.companions_count', 1)
            ->assertJsonPath('data.longest.distance_km', 32.5)
            ->assertJsonPath('data.available_years', [2026, 2025])
            ->assertJsonCount(2, 'data.medals')
            ->assertJsonCount(2, 'data.places');
    }

    public function test_year_review_of_an_empty_year_is_zeroes_not_an_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/year-review')
            ->assertOk()
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.trips_count', 0)
            ->assertJsonPath('data.longest', null)
            ->assertJsonPath('data.available_years', []);
    }

    public function test_year_ready_push_goes_once_per_person_per_year(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $this->finishRound($this->trip(), '2026-09-05', [$a, $b]);
        $service = app(YearReviewService::class);

        $this->assertSame(2, $service->notifyYearReady(2026));
        $this->assertSame(0, $service->notifyYearReady(2026));
        $this->assertSame(0, $service->notifyYearReady(2024));
    }
}
