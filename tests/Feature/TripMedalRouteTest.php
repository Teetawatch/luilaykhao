<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripMedal;
use App\Models\TripSchedule;
use App\Models\TripTrack;
use App\Models\User;
use App\Services\MedalRouteService;
use App\Services\RouteTrackService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TripMedalRouteTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $now = CarbonImmutable::parse('2026-09-10 12:00', 'Asia/Bangkok');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** เส้นทางเดินขึ้นเขา ~3 กม. ไต่จาก 900 → 1300 ม. */
    private function climb(float $startLat = 15.2, float $startLng = 106.4, int $n = 30): array
    {
        $raw = [];
        for ($i = 0; $i < $n; $i++) {
            $raw[] = [
                'lat' => $startLat + $i * 0.001,
                'lng' => $startLng + sin($i / 4) * 0.0008,
                'ele' => 900 + $i * (400 / ($n - 1)),
            ];
        }

        return app(RouteTrackService::class)->build($raw)['points'];
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'เดินป่าลาวใต้ ที่ราบสูงโบลาเวน 4 วัน 3 คืน',
            'slug' => 'bolaven-'.uniqid(),
            'type' => 'trekking',
            'location' => 'ปากเซ',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 4,
            'distance_km' => 32.5,
            'elevation_gain_m' => 1200,
            'max_participants' => 12,
            'price_per_person' => 9900,
            'status' => 'active',
        ], $overrides));
    }

    private function bookRound(User $user, Trip $trip, string $date): TripSchedule
    {
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $date,
            'return_date' => $date,
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        Booking::create([
            'booking_ref' => sprintf('LLK-20260901-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 9900,
            'paid_amount' => 9900,
        ]);

        return $schedule;
    }

    private function track(User $user, TripSchedule $schedule, array $overrides = []): TripTrack
    {
        return TripTrack::create(array_merge([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'points' => $this->climb(),
            'distance_km' => 3.2,
            'elevation_gain_m' => 400,
            'elevation_loss_m' => 0,
            'max_elevation_m' => 1300,
            'moving_seconds' => 5400,
            'started_at' => $schedule->departure_date->copy()->setTime(7, 0),
        ], $overrides));
    }

    public function test_shape_fits_a_unit_square_and_carries_no_coordinates(): void
    {
        $shape = app(MedalRouteService::class)->shape($this->climb());

        $this->assertNotNull($shape);
        $this->assertCount(30, $shape['points']);

        foreach ($shape['points'] as [$x, $y]) {
            $this->assertGreaterThanOrEqual(0, $x);
            $this->assertLessThanOrEqual(1, $x);
            $this->assertGreaterThanOrEqual(0, $y);
            $this->assertLessThanOrEqual(1, $y);
        }

        // ด้านยาว (เหนือ-ใต้) เต็มกรอบพอดี ด้านสั้นถูกจัดกึ่งกลาง
        $ys = array_column($shape['points'], 1);
        $this->assertEqualsWithDelta(0, min($ys), 0.0001);
        $this->assertEqualsWithDelta(1, max($ys), 0.0001);
        $xs = array_column($shape['points'], 0);
        $this->assertEqualsWithDelta(1, min($xs) + max($xs), 0.001);

        // เดินขึ้นเหนือ = y ลดลง (แกน y ของจอชี้ลง)
        $this->assertGreaterThan(end($ys), $ys[0]);

        $this->assertStringNotContainsString('lat', json_encode($shape));
        $this->assertCount(MedalRouteService::PROFILE_SAMPLES, $shape['elevations']);
        $this->assertSame(900, $shape['elevations'][0]);
        $this->assertSame(1300, end($shape['elevations']));
    }

    public function test_shape_rejects_tracks_that_cannot_be_drawn(): void
    {
        $service = app(MedalRouteService::class);

        $this->assertNull($service->shape(null));
        $this->assertNull($service->shape([['lat' => 15, 'lng' => 106]]));
        $this->assertNull($service->shape([
            ['lat' => 15, 'lng' => 106, 'ele' => 900, 'km' => 0],
            ['lat' => 15, 'lng' => 106, 'ele' => 900, 'km' => 0],
        ]));

        // ไม่มีความสูง — ยังวาดเส้นทางได้ แต่ไม่มีกราฟ
        $flat = $service->shape([
            ['lat' => 15.0, 'lng' => 106.0, 'ele' => null, 'km' => 0],
            ['lat' => 15.01, 'lng' => 106.0, 'ele' => null, 'km' => 1.1],
        ]);
        $this->assertNotNull($flat);
        $this->assertNull($flat['elevations']);
    }

    public function test_medal_uses_the_travellers_own_track_and_numbers(): void
    {
        $user = User::factory()->create();
        $schedule = $this->bookRound($user, $this->trip(), '2026-09-05');
        $this->track($user, $schedule);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/medals')
            ->assertOk()
            ->assertJsonPath('data.medals.0.route.source', 'recorded')
            ->assertJsonPath('data.medals.0.personal.distance_km', 3.2)
            ->assertJsonPath('data.medals.0.personal.elevation_gain_m', 400)
            ->assertJsonPath('data.medals.0.personal.moving_seconds', 5400)
            ->assertJsonPath('data.medals.0.personal.avg_speed_kmh', 2.1)
            ->assertJsonPath('data.medals.0.personal.max_elevation_m', 1300)
            ->assertJsonPath('data.medals.0.records', []);
    }

    public function test_without_a_track_the_trip_route_is_drawn_and_no_personal_numbers(): void
    {
        $user = User::factory()->create();
        $trip = $this->trip(['route_track' => ['points' => $this->climb()]]);
        $this->bookRound($user, $trip, '2026-09-05');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/medals')
            ->assertOk()
            ->assertJsonPath('data.medals.0.route.source', 'planned')
            ->assertJsonPath('data.medals.0.personal', null);
    }

    public function test_a_throwaway_recording_falls_back_to_the_trip_route(): void
    {
        $user = User::factory()->create();
        $trip = $this->trip(['route_track' => ['points' => $this->climb()]]);
        $schedule = $this->bookRound($user, $trip, '2026-09-05');
        $this->track($user, $schedule, ['distance_km' => 0.1]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/medals')
            ->assertOk()
            ->assertJsonPath('data.medals.0.route.source', 'planned')
            ->assertJsonPath('data.medals.0.personal', null);
    }

    public function test_no_route_anywhere_means_null(): void
    {
        $user = User::factory()->create();
        $this->bookRound($user, $this->trip(), '2026-09-05');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/medals')
            ->assertOk()
            ->assertJsonPath('data.medals.0.route', null);
    }

    public function test_personal_records_go_to_the_track_that_set_them(): void
    {
        $user = User::factory()->create();
        $a = $this->bookRound($user, $this->trip(), '2026-03-01');
        $b = $this->bookRound($user, $this->trip(), '2026-09-05');

        // A ไกลกว่า แต่ B ไต่เยอะกว่าและขึ้นสูงกว่า
        $this->track($user, $a, ['distance_km' => 12, 'elevation_gain_m' => 300, 'max_elevation_m' => 1100]);
        $this->track($user, $b, ['distance_km' => 8, 'elevation_gain_m' => 900, 'max_elevation_m' => 1600]);

        $medals = collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/medals')->json('data.medals'),
        )->keyBy('earned_on');

        $this->assertSame(['distance'], $medals['2026-03-01']['records']);
        $this->assertSame(['climb', 'altitude'], $medals['2026-09-05']['records']);
    }

    public function test_a_tie_stays_with_whoever_did_it_first(): void
    {
        $user = User::factory()->create();
        $a = $this->bookRound($user, $this->trip(), '2026-03-01');
        $b = $this->bookRound($user, $this->trip(), '2026-09-05');
        $this->track($user, $a, ['distance_km' => 8]);
        $this->track($user, $b, ['distance_km' => 8]);

        $medals = collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/medals')->json('data.medals'),
        )->keyBy('earned_on');

        $this->assertContains('distance', $medals['2026-03-01']['records']);
        $this->assertNotContains('distance', $medals['2026-09-05']['records']);
    }

    public function test_public_medal_page_never_exposes_the_track(): void
    {
        $user = User::factory()->create();
        $schedule = $this->bookRound($user, $this->trip(), '2026-09-05');
        $this->track($user, $schedule);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/medals')->assertOk();
        $token = TripMedal::first()->share_token;

        $this->get('/m/'.$token)
            ->assertOk()
            ->assertDontSee('15.2')
            ->assertDontSee('106.4');
    }
}
