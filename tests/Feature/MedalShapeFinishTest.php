<?php

namespace Tests\Feature;

use App\Http\Controllers\MedalPageController;
use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripMedal;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MedalImageService;
use App\Services\MedalService;
use App\Support\MedalFinish;
use App\Support\MedalGeometry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ทรงเหรียญที่เจ้าของเลือก (ติดไปถึงลิงก์ /m/ และภาพ OG) + ผิวเหรียญตามครั้งที่มา
 */
class MedalShapeFinishTest extends TestCase
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

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'เดินป่าลาวใต้ ที่ราบสูงโบลาเวน 4 วัน 3 คืน',
            'slug' => 'bolaven-'.uniqid(),
            'type' => 'trekking',
            'location' => 'ปากเซ',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 4,
            'max_participants' => 12,
            'price_per_person' => 9900,
            'status' => 'active',
        ], $overrides));
    }

    private function makeSchedule(Trip $trip, string $date): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $date,
            'return_date' => $date,
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function book(User $user, TripSchedule $schedule): Booking
    {
        return Booking::create([
            'booking_ref' => sprintf('LLK-20260901-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 9900,
            'paid_amount' => 9900,
        ]);
    }

    private function medals(): MedalService
    {
        return app(MedalService::class);
    }

    /** เหรียญหนึ่งใบของผู้ใช้คนใหม่ บนทริปใหม่ */
    private function oneMedal(array $trip = []): TripMedal
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip($trip), '2026-09-05');
        $this->book($user, $schedule);
        $this->medals()->awardSchedule($schedule);

        return TripMedal::where('user_id', $user->id)->firstOrFail();
    }

    // ── ทรงเหรียญ ───────────────────────────────────────────────────────────

    public function test_owner_picks_a_shape_and_the_cabinet_and_public_card_carry_it(): void
    {
        $medal = $this->oneMedal();

        $this->actingAs($medal->user, 'sanctum')
            ->patchJson('/api/v1/me/medals/'.$medal->id, ['shape' => 'hexagon'])
            ->assertOk()
            ->assertJsonPath('data.shape', 'hexagon')
            ->assertJsonPath('data.share_url', url('/m/'.$medal->share_token));

        $this->assertSame('hexagon', $medal->fresh()->shape);

        $this->actingAs($medal->user, 'sanctum')
            ->getJson('/api/v1/me/medals')
            ->assertJsonPath('data.medals.0.shape', 'hexagon');

        $this->assertSame('hexagon', $this->medals()->forShareToken($medal->share_token)['shape']);

        // null = กลับไปใช้แบบของทริป
        $this->actingAs($medal->user, 'sanctum')
            ->patchJson('/api/v1/me/medals/'.$medal->id, ['shape' => null])
            ->assertOk()
            ->assertJsonPath('data.shape', null);

        $this->assertNull($medal->fresh()->shape);
    }

    public function test_rosette_on_a_template_trip_is_stored_as_the_trip_default(): void
    {
        $template = $this->oneMedal();
        $custom = $this->oneMedal(['medal_image' => 'https://media.luilaykhao.com/media/bolaven-medal.png']);

        $this->actingAs($template->user, 'sanctum')
            ->patchJson('/api/v1/me/medals/'.$template->id, ['shape' => 'rosette'])
            ->assertOk()
            ->assertJsonPath('data.shape', null);

        // ทริปที่มีภาพออกแบบเอง ขอบหยักคือการเลือก "ไม่เอาภาพ" จริง ๆ ต้องจำไว้
        $this->actingAs($custom->user, 'sanctum')
            ->patchJson('/api/v1/me/medals/'.$custom->id, ['shape' => 'rosette'])
            ->assertOk()
            ->assertJsonPath('data.shape', 'rosette');
    }

    public function test_shape_update_is_validated_and_limited_to_the_owner(): void
    {
        $medal = $this->oneMedal();
        $stranger = User::factory()->create();

        $this->patchJson('/api/v1/me/medals/'.$medal->id, ['shape' => 'coin'])->assertUnauthorized();

        $this->actingAs($medal->user, 'sanctum')
            ->patchJson('/api/v1/me/medals/'.$medal->id, ['shape' => 'triangle'])
            ->assertUnprocessable();

        // ต้องส่ง shape มาเสมอ (null ได้) — body ว่างไม่ใช่การล้างทรง
        $this->actingAs($medal->user, 'sanctum')
            ->patchJson('/api/v1/me/medals/'.$medal->id, [])
            ->assertUnprocessable();

        $this->actingAs($stranger, 'sanctum')
            ->patchJson('/api/v1/me/medals/'.$medal->id, ['shape' => 'coin'])
            ->assertNotFound();

        $this->assertNull($medal->fresh()->shape);
    }

    public function test_an_unknown_stored_shape_falls_back_to_the_trip_design(): void
    {
        $medal = $this->oneMedal();
        $medal->forceFill(['shape' => 'octagon'])->save();

        $this->assertNull($this->medals()->forShareToken($medal->share_token)['shape']);
    }

    public function test_a_transferred_medal_starts_the_new_owner_on_the_trip_design(): void
    {
        $shadow = User::factory()->create();
        $real = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $booking = $this->book($shadow, $schedule);
        $this->medals()->awardSchedule($schedule);
        $this->medals()->setShape($shadow->id, TripMedal::first()->id, 'shield');

        $booking->update(['user_id' => $real->id]);
        $this->medals()->awardSchedule($schedule);

        $medal = TripMedal::where('user_id', $real->id)->firstOrFail();
        $this->assertNull($medal->shape);
    }

    public function test_public_page_draws_the_chosen_shape_even_over_custom_art(): void
    {
        $medal = $this->oneMedal(['medal_image' => 'https://media.luilaykhao.com/media/bolaven-medal.png']);

        $this->get('/m/'.$medal->share_token)
            ->assertOk()
            ->assertSee('data-shape="trip"', false)
            ->assertSee('bolaven-medal.png', false);

        $this->medals()->setShape($medal->user_id, $medal->id, 'shield');

        $this->get('/m/'.$medal->share_token)
            ->assertOk()
            ->assertSee('data-shape="shield"', false)
            ->assertSee('LUILAYKHAO  •  FINISHER  •  2569')
            ->assertDontSee('bolaven-medal.png', false);
    }

    public function test_og_image_url_changes_when_the_shape_changes(): void
    {
        $medal = $this->oneMedal();
        $before = MedalPageController::ogVersion($this->medals()->forShareToken($medal->share_token));

        $this->get('/m/'.$medal->share_token)
            ->assertSee(route('medal.og', ['token' => $medal->share_token, 'v' => $before]), false);

        $this->medals()->setShape($medal->user_id, $medal->id, 'coin');
        $after = MedalPageController::ogVersion($this->medals()->forShareToken($medal->share_token));

        $this->assertNotSame($before, $after);
        $this->get('/m/'.$medal->share_token)
            ->assertSee(route('medal.og', ['token' => $medal->share_token, 'v' => $after]), false);
    }

    public function test_og_image_renders_every_shape_in_every_finish(): void
    {
        $medal = $this->oneMedal();
        $card = $this->medals()->forShareToken($medal->share_token);
        $images = app(MedalImageService::class);

        foreach (MedalGeometry::SHAPES as $shape) {
            foreach (array_keys(MedalFinish::PALETTES) as $finish) {
                $png = $images->render([...$card, 'shape' => $shape, 'finish' => $finish, 'finish_label' => MedalFinish::label($finish)]);
                [$width, $height] = getimagesizefromstring($png);

                $this->assertSame([1200, 630], [$width, $height], "{$shape}/{$finish}");
            }
        }
    }

    public function test_every_shape_fits_the_canvas_and_leaves_room_for_the_ring_text(): void
    {
        $polygons = [
            'sunburst' => [MedalGeometry::star(), null],
            'hexagon' => [MedalGeometry::hexagon(MedalGeometry::HEX_OUTER_R), MedalGeometry::hexagon(MedalGeometry::HEX_BAND_R)],
            'shield' => [MedalGeometry::shield(), MedalGeometry::shield(MedalGeometry::SHIELD_BAND_INSET)],
        ];

        foreach ($polygons as $shape => [$frame, $band]) {
            foreach (array_chunk($frame, 2) as [$x, $y]) {
                $this->assertGreaterThanOrEqual(0, $x, $shape);
                $this->assertLessThanOrEqual(MedalGeometry::WIDTH, $x, $shape);
                $this->assertLessThanOrEqual(MedalGeometry::HEIGHT + 0.01, $y, $shape);
            }

            // ตัวอักษรรอบขอบ (รัศมี 38.2 + ครึ่งความสูงตัวอักษร) ต้องอยู่ในแถบเข้มทั้งแถบ
            $bandInner = $band === null ? MedalGeometry::BAND_R : $this->distanceToEdge($band);
            $this->assertGreaterThanOrEqual(40.5, $bandInner, $shape);
        }

        // ร่องของดาวแฉกยังอยู่นอกแถบเข้ม
        $this->assertGreaterThan(MedalGeometry::BAND_R, MedalGeometry::STAR_INNER_R);
        // ลายเม็ดของเหรียญกลมอยู่บนขอบทอง ไม่ทับแถบเข้ม
        $this->assertGreaterThan(MedalGeometry::BAND_R + MedalGeometry::COIN_BEAD_SIZE, MedalGeometry::COIN_BEAD_R);
        $this->assertLessThan(MedalGeometry::ROSETTE_R - MedalGeometry::COIN_BEAD_SIZE, MedalGeometry::COIN_BEAD_R);
    }

    /** ระยะใกล้สุดจากจุดศูนย์กลางเหรียญถึงขอบรูปหลายเหลี่ยม */
    private function distanceToEdge(array $xy): float
    {
        $points = array_chunk($xy, 2);
        $min = INF;

        foreach ($points as $i => [$x1, $y1]) {
            [$x2, $y2] = $points[($i + 1) % count($points)];
            $dx = $x2 - $x1;
            $dy = $y2 - $y1;
            $len = $dx * $dx + $dy * $dy;
            $t = $len > 0 ? max(0, min(1, ((MedalGeometry::CX - $x1) * $dx + (MedalGeometry::CY - $y1) * $dy) / $len)) : 0;
            $min = min($min, hypot($x1 + $t * $dx - MedalGeometry::CX, $y1 + $t * $dy - MedalGeometry::CY));
        }

        return $min;
    }

    // ── ผิวเหรียญ ───────────────────────────────────────────────────────────

    public function test_finish_tiers_follow_the_attempt_number(): void
    {
        $this->assertSame('gold', MedalFinish::forAttempt(1));
        $this->assertSame('gold', MedalFinish::forAttempt(2));
        $this->assertSame('platinum', MedalFinish::forAttempt(3));
        $this->assertSame('platinum', MedalFinish::forAttempt(4));
        $this->assertSame('obsidian', MedalFinish::forAttempt(5));
        $this->assertSame('obsidian', MedalFinish::forAttempt(12));
        $this->assertSame('gold', MedalFinish::forAttempt(0));
        $this->assertSame(MedalFinish::PALETTES['gold'], MedalFinish::palette('diamond'));
    }

    public function test_repeat_visits_earn_platinum_then_obsidian_everywhere(): void
    {
        $user = User::factory()->create([
            'public_handle' => 'tonhiker',
            'public_profile_enabled' => true,
        ]);
        $trip = $this->makeTrip();
        $other = $this->makeTrip(['title' => 'ภูกระดึง 3 วัน 2 คืน']);

        foreach (['2025-11-01', '2025-12-01', '2026-01-10', '2026-03-01', '2026-09-05'] as $date) {
            $this->book($user, $this->makeSchedule($trip, $date));
        }
        // ทริปอื่นไม่นับรวม — ครั้งแรกของทริปนั้นยังเป็นทอง
        $this->book($user, $this->makeSchedule($other, '2026-09-05'));

        $medals = collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/medals')->assertOk()->json('data.medals'),
        );

        $bolaven = $medals->where('trip.id', $trip->id)->sortBy('attempt')->values();
        $this->assertSame([1, 2, 3, 4, 5], $bolaven->pluck('attempt')->all());
        $this->assertSame(
            ['gold', 'gold', 'platinum', 'platinum', 'obsidian'],
            $bolaven->pluck('finish')->all(),
        );
        $this->assertSame('ดำทอง', $bolaven[4]['finish_label']);
        $this->assertSame('gold', $medals->firstWhere('trip.id', $other->id)['finish']);

        // หน้าสาธารณะ: บอกผิว ไม่บอกเลขครั้งที่
        $fifth = TripMedal::where('user_id', $user->id)->where('trip_id', $trip->id)
            ->orderByDesc('earned_on')->firstOrFail();
        $this->get('/m/'.$fifth->share_token)
            ->assertOk()
            ->assertSee('data-finish="obsidian"', false)
            ->assertSee('เหรียญดำทอง')
            ->assertDontSee('ครั้งที่ 5');

        $first = TripMedal::where('user_id', $user->id)->where('trip_id', $trip->id)
            ->orderBy('earned_on')->firstOrFail();
        $this->get('/m/'.$first->share_token)
            ->assertOk()
            ->assertSee('data-finish="gold"', false)
            ->assertDontSee('เหรียญทอง');

        // ชั้นวางในโปรไฟล์นับครั้งที่ในคิวรีเดียวกัน ผลต้องตรงกับตู้เหรียญ
        $this->get('/u/tonhiker')
            ->assertOk()
            ->assertSee('data-finish="obsidian"', false)
            ->assertSee('data-finish="platinum"', false);

        $review = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/year-review?year=2026')
            ->assertOk()
            ->json('data.medals');
        $this->assertContains('obsidian', array_column($review, 'finish'));
        $this->assertArrayHasKey('shape', $review[0]);
    }
}
