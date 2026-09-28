<?php

namespace Tests\Feature;

use App\Jobs\AwardTripMedalsJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Category;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripMedal;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MedalService;
use App\Support\MedalDesign;
use App\Support\MedalGeometry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TripMedalTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // เที่ยงวัน 10 ก.ย. 2569 เวลาไทย — ทริปที่จบวันที่ 5 ผ่าน 20:00 ของวันนั้นไปนานแล้ว
        $this->travelToBangkok('2026-09-10 12:00');
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
            'distance_km' => 32.5,
            'elevation_gain_m' => 1200,
            'max_participants' => 12,
            'price_per_person' => 9900,
            'status' => 'active',
        ], $overrides));
    }

    private function makeSchedule(Trip $trip, string $departure, ?string $return = null, string $status = 'open'): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $return ?? $departure,
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => $status,
        ]);
    }

    private function book(User $user, TripSchedule $schedule, string $status = 'confirmed', ?string $checkedInAt = null): Booking
    {
        return Booking::create([
            'booking_ref' => sprintf('LLK-20260901-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 9900,
            'paid_amount' => 9900,
            'checked_in' => $checkedInAt !== null,
            'checked_in_at' => $checkedInAt,
        ]);
    }

    private function medals(): MedalService
    {
        return app(MedalService::class);
    }

    // ── ใครได้เหรียญ ─────────────────────────────────────────────────────────

    public function test_owner_and_companion_each_get_a_medal_once_the_trip_ends(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-02', '2026-09-05');
        $booking = $this->book($owner, $schedule);
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->assertSame(2, $this->medals()->awardSchedule($schedule));

        $this->assertSame(1, TripMedal::where('user_id', $owner->id)->value('finisher_no'));
        $this->assertSame(2, TripMedal::where('user_id', $friend->id)->value('finisher_no'));
        $this->assertSame('2026-09-05', TripMedal::where('user_id', $owner->id)->first()->earned_on->toDateString());
    }

    public function test_no_medal_before_eight_pm_on_the_last_day(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-09', '2026-09-10');
        $this->book($owner, $schedule);

        $this->travelToBangkok('2026-09-10 19:59');
        $this->assertSame(0, $this->medals()->awardSchedule($schedule));

        $this->travelToBangkok('2026-09-10 20:00');
        $this->assertSame(1, $this->medals()->awardSchedule($schedule));
    }

    public function test_when_the_round_used_check_in_only_checked_in_travellers_get_a_medal(): void
    {
        $came = User::factory()->create();
        $noShow = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $this->book($came, $schedule, checkedInAt: '2026-09-05 06:10:00');
        $this->book($noShow, $schedule);

        $this->medals()->awardSchedule($schedule);

        $this->assertTrue(TripMedal::where('user_id', $came->id)->exists());
        $this->assertFalse(TripMedal::where('user_id', $noShow->id)->exists());
    }

    public function test_a_round_nobody_was_checked_in_on_still_awards_confirmed_travellers(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $this->book($a, $schedule);
        $this->book($b, $schedule, 'completed');

        $this->assertSame(2, $this->medals()->awardSchedule($schedule));
    }

    public function test_cancelled_pending_bookings_and_cancelled_rounds_get_nothing(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, '2026-09-05');
        $this->book(User::factory()->create(), $schedule, 'cancelled');
        $this->book(User::factory()->create(), $schedule, 'pending');

        $cancelledRound = $this->makeSchedule($trip, '2026-09-06', status: 'cancelled');
        $this->book(User::factory()->create(), $cancelledRound);

        $this->assertSame(0, $this->medals()->awardSchedule($schedule));
        $this->assertSame(0, $this->medals()->awardSchedule($cancelledRound));
        $this->assertSame(0, TripMedal::count());
    }

    public function test_admin_who_booked_on_behalf_does_not_collect_the_medal_but_the_companion_does(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $customer = User::factory()->create();

        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $booking = $this->book($admin, $schedule);
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $customer->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->medals()->awardSchedule($schedule);

        $this->assertFalse(TripMedal::where('user_id', $admin->id)->exists());
        $this->assertTrue(TripMedal::where('user_id', $customer->id)->exists());
    }

    // ── เลข Finisher ─────────────────────────────────────────────────────────

    public function test_finisher_numbers_follow_the_calendar_even_when_a_newer_round_is_opened_first(): void
    {
        $trip = $this->makeTrip();
        $old = $this->makeSchedule($trip, '2026-03-01', '2026-03-04');
        $new = $this->makeSchedule($trip, '2026-09-02', '2026-09-05');

        $veteran = User::factory()->create();
        $rookie = User::factory()->create();
        $this->book($veteran, $old);
        $this->book($rookie, $new);

        // คนจากรอบใหม่เปิดตู้เหรียญก่อน — รอบเก่าต้องได้เลขก่อนอยู่ดี
        $this->actingAs($rookie, 'sanctum')->getJson('/api/v1/me/medals')->assertOk();

        $this->assertSame(1, TripMedal::where('user_id', $veteran->id)->value('finisher_no'));
        $this->assertSame(2, TripMedal::where('user_id', $rookie->id)->value('finisher_no'));
    }

    public function test_check_in_order_decides_numbers_inside_a_round(): void
    {
        $late = User::factory()->create();
        $early = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $this->book($late, $schedule, checkedInAt: '2026-09-05 06:30:00');
        $this->book($early, $schedule, checkedInAt: '2026-09-05 06:05:00');

        $this->medals()->awardSchedule($schedule);

        $this->assertSame(1, TripMedal::where('user_id', $early->id)->value('finisher_no'));
        $this->assertSame(2, TripMedal::where('user_id', $late->id)->value('finisher_no'));
    }

    public function test_a_companion_added_later_gets_the_next_number_without_shifting_anyone(): void
    {
        $trip = $this->makeTrip();
        $first = $this->makeSchedule($trip, '2026-08-01');
        $second = $this->makeSchedule($trip, '2026-09-05');
        $owner = User::factory()->create();
        $booking = $this->book($owner, $first);
        $this->book(User::factory()->create(), $second);

        $this->medals()->awardSchedule($first);
        $this->medals()->awardSchedule($second);

        $late = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $late->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($late, 'sanctum')->getJson('/api/v1/me/medals')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.medals.0.finisher_no', 3);

        $this->assertSame(1, TripMedal::where('user_id', $owner->id)->value('finisher_no'));
    }

    public function test_a_transferred_booking_hands_the_medal_and_its_number_to_the_new_owner(): void
    {
        $shadow = User::factory()->create();
        $real = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $booking = $this->book($shadow, $schedule);

        $this->medals()->awardSchedule($schedule);
        $before = TripMedal::where('user_id', $shadow->id)->first();

        $booking->update(['user_id' => $real->id]);
        $this->medals()->awardSchedule($schedule);

        $after = TripMedal::where('user_id', $real->id)->first();
        $this->assertNotNull($after);
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->finisher_no, $after->finisher_no);
        $this->assertNotSame($before->share_token, $after->share_token);
        $this->assertFalse(TripMedal::where('user_id', $shadow->id)->exists());
    }

    public function test_cancelling_a_booking_after_the_trip_withdraws_the_medal(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $booking = $this->book($user, $schedule);
        $this->medals()->awardSchedule($schedule);

        $booking->update(['status' => 'cancelled']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/medals')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    // ── API ──────────────────────────────────────────────────────────────────

    public function test_medal_cabinet_payload_carries_the_design_and_counts_repeat_visits(): void
    {
        $user = User::factory()->create(['nickname' => 'ต้น']);
        $trip = $this->makeTrip();
        $this->book($user, $this->makeSchedule($trip, '2025-12-01', '2025-12-04'));
        $this->book($user, $this->makeSchedule($trip, '2026-09-02', '2026-09-05'));

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/medals')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.unseen_count', 2)
            ->assertJsonPath('data.trips_count', 1)
            ->assertJsonPath('data.medals.0.earned_on', '2026-09-05')
            ->assertJsonPath('data.medals.0.attempt', 2)
            ->assertJsonPath('data.medals.0.attempts_of_trip', 2)
            ->assertJsonPath('data.medals.1.attempt', 1)
            ->assertJsonPath('data.medals.0.holder_name', 'ต้น')
            ->assertJsonPath('data.medals.0.finisher_label', 'Finisher #2')
            ->assertJsonPath('data.medals.0.design.name', 'เดินป่าลาวใต้ ที่ราบสูงโบลาเวน')
            ->assertJsonPath('data.medals.0.design.icon', 'hiking')
            ->assertJsonPath('data.medals.0.design.is_custom', false)
            ->assertJsonPath('data.medals.0.trip.distance_km', 32.5);

        $this->assertStringContainsString('/m/', $response->json('data.medals.0.share_url'));
    }

    public function test_seen_endpoint_only_touches_the_callers_medals(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $this->book($me, $schedule);
        $this->book($other, $schedule);
        $this->medals()->awardSchedule($schedule);
        $theirs = TripMedal::where('user_id', $other->id)->first();

        $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/me/medals/seen', ['ids' => [$theirs->id]])
            ->assertOk()
            ->assertJsonPath('data.updated', 0);

        $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/me/medals/seen')
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertNull($theirs->fresh()->seen_at);
        $this->assertNotNull(TripMedal::where('user_id', $me->id)->value('seen_at'));
    }

    public function test_medal_cabinet_requires_login(): void
    {
        $this->getJson('/api/v1/me/medals')->assertUnauthorized();
    }

    // ── แจ้งเตือน ────────────────────────────────────────────────────────────

    public function test_push_goes_out_at_ten_the_morning_after_the_trip_ends(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-08', '2026-09-10');
        $this->book($user, $schedule);

        $this->travelToBangkok('2026-09-10 20:05');
        $this->medals()->awardSchedule($schedule);
        $this->assertSame(0, $this->medals()->notifyPending());

        $this->travelToBangkok('2026-09-11 09:59');
        $this->assertSame(0, $this->medals()->notifyPending());

        $this->travelToBangkok('2026-09-11 10:05');
        $this->assertSame(1, $this->medals()->notifyPending());
        $this->assertSame(0, $this->medals()->notifyPending());

        $push = SmartNotification::where('user_id', $user->id)->where('type', 'medal_earned')->first();
        $this->assertNotNull($push);
        $this->assertSame(TripMedal::first()->id, $push->data['medal_id']);
        $this->assertArrayNotHasKey('booking_ref', $push->data);
    }

    public function test_no_push_for_a_medal_already_opened_or_for_old_trips(): void
    {
        $seenUser = User::factory()->create();
        $recent = $this->makeSchedule($this->makeTrip(), '2026-09-09');
        $this->book($seenUser, $recent);

        $oldUser = User::factory()->create();
        $old = $this->makeSchedule($this->makeTrip(), '2025-01-10');
        $this->book($oldUser, $old);

        $this->medals()->awardSchedule($recent);
        $this->medals()->awardSchedule($old);
        $this->medals()->markSeen($seenUser->id);

        $this->assertSame(0, $this->medals()->notifyPending());
        $this->assertSame(0, SmartNotification::where('type', 'medal_earned')->count());
        $this->assertSame(0, TripMedal::whereNull('notified_at')->count());
    }

    public function test_hourly_job_backfills_every_past_round_in_order_without_pushing(): void
    {
        $trip = $this->makeTrip();
        $users = User::factory()->count(3)->create();
        $this->book($users[2], $this->makeSchedule($trip, '2026-05-01'));
        $this->book($users[0], $this->makeSchedule($trip, '2024-11-01'));
        $this->book($users[1], $this->makeSchedule($trip, '2025-06-01'));
        $this->book(User::factory()->create(), $this->makeSchedule($trip, '2026-12-01'));

        (new AwardTripMedalsJob)->handle($this->medals());

        $this->assertSame(3, TripMedal::count());
        foreach ([0, 1, 2] as $i) {
            $this->assertSame($i + 1, TripMedal::where('user_id', $users[$i]->id)->value('finisher_no'));
        }
        $this->assertSame(0, SmartNotification::where('type', 'medal_earned')->count());
    }

    // ── หน้าสาธารณะ ──────────────────────────────────────────────────────────

    public function test_public_medal_page_shows_the_medal_without_booking_details(): void
    {
        $user = User::factory()->create(['name' => 'สมชาย ใจดี', 'nickname' => null]);
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-02', '2026-09-05');
        $booking = $this->book($user, $schedule);
        $this->medals()->awardSchedule($schedule);
        $medal = TripMedal::first();

        $this->get('/m/'.$medal->share_token)
            ->assertOk()
            ->assertSee('Finisher #1')
            ->assertSee('สมชาย ใจดี')
            ->assertSee('เดินป่าลาวใต้ ที่ราบสูงโบลาเวน')
            ->assertSee(route('medal.og', ['token' => $medal->share_token]), false)
            ->assertDontSee($booking->booking_ref);

        $this->get('/m/doesnotexist')->assertNotFound();
    }

    public function test_og_image_is_a_1200_by_630_png(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $this->book($user, $schedule);
        $this->medals()->awardSchedule($schedule);

        $response = $this->get('/m/'.TripMedal::first()->share_token.'/og.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        [$width, $height] = getimagesizefromstring($response->getContent());
        $this->assertSame([1200, 630], [$width, $height]);

        $this->get('/m/doesnotexist/og.png')->assertNotFound();
    }

    public function test_public_profile_shows_the_medal_shelf(): void
    {
        $user = User::factory()->create([
            'public_handle' => 'tonhiker',
            'public_profile_enabled' => true,
        ]);
        $schedule = $this->makeSchedule($this->makeTrip(), '2026-09-05');
        $this->book($user, $schedule);
        $this->medals()->awardSchedule($schedule);

        $this->get('/u/tonhiker')
            ->assertOk()
            ->assertSee('ตู้เหรียญพิชิต (1)')
            ->assertSee('/m/'.TripMedal::first()->share_token, false);
    }

    // ── หน้าตาเหรียญ ─────────────────────────────────────────────────────────

    public function test_admin_can_design_the_medal_and_the_color_is_normalised(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Category::firstOrCreate(['slug' => 'trekking'], ['name' => 'เดินป่า']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $trip = $this->makeTrip();

        $payload = [
            'title' => $trip->title,
            'type' => 'trekking',
            'location' => $trip->location,
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 4,
            'max_participants' => 12,
            'price_per_person' => 9900,
        ];

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $payload + ['medal_icon' => 'not-an-icon'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medal_icon');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $payload + [
                'medal_name' => 'พิชิตโบลาเวน',
                'medal_icon' => 'coffee',
                'medal_color' => '7c4a2d',
                'medal_image' => 'https://media.luilaykhao.com/media/bolaven-medal.png',
            ])
            ->assertOk();

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.medal.name', 'พิชิตโบลาเวน')
            ->assertJsonPath('data.medal.icon', 'coffee')
            ->assertJsonPath('data.medal.color', '#7C4A2D')
            ->assertJsonPath('data.medal.is_custom', true)
            ->assertJsonPath('data.medal_color', '#7C4A2D');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/medal-options')
            ->assertOk()
            ->assertJsonPath('data.icons.0.value', 'hiking')
            ->assertJsonCount(MedalGeometry::SCALLOPS, 'data.geometry.scallops')
            ->assertJsonCount(count(MedalGeometry::laurel()), 'data.geometry.laurel');
    }

    public function test_default_medal_name_drops_the_trip_length(): void
    {
        $this->assertSame('เดินป่าดอยอินทนนท์', MedalDesign::nameFromTitle('เดินป่าดอยอินทนนท์ 2 วัน 1 คืน'));
        $this->assertSame('ปีนผาไร่เลย์', MedalDesign::nameFromTitle('ปีนผาไร่เลย์ 1 วัน'));
        $this->assertSame('ภูกระดึง', MedalDesign::nameFromTitle('ภูกระดึง (3วัน2คืน)'));
        $this->assertSame('3 วัน 2 คืน', MedalDesign::nameFromTitle('3 วัน 2 คืน'));
        $this->assertSame('ภูชี้ฟ้า', MedalDesign::nameFromTitle('ภูชี้ฟ้า'));
    }

    public function test_every_medal_icon_has_a_glyph_in_the_og_icon_font(): void
    {
        // MedalIcons.otf วางไอคอนเรียงตามลำดับของ MedalDesign::ICONS ที่ U+E000 เป็นต้นไป
        // เพิ่มไอคอนใน ICONS แล้วลืมสร้างฟอนต์ใหม่ ภาพ OG จะได้ช่องว่างแทนไอคอน
        $font = resource_path('fonts/MedalIcons.otf');

        foreach (array_keys(MedalDesign::ICONS) as $i => $icon) {
            $box = imagettfbbox(40, 0, $font, mb_chr(0xE000 + $i));
            $this->assertGreaterThan(10, abs($box[2] - $box[0]), "ไม่มี glyph ของ {$icon}");
        }
    }

    public function test_template_geometry_stays_inside_the_medal(): void
    {
        $inDisc = fn (float $x, float $y, float $pad = 0) => hypot($x - MedalGeometry::CX, $y - MedalGeometry::CY)
            <= MedalGeometry::DISC_R - $pad;

        // ช่อใบไม้ทุกใบอยู่ในดวงสี ไม่ล้นไปทับแถบตัวอักษร
        foreach (MedalGeometry::laurel() as $leaf) {
            $this->assertTrue($inDisc($leaf['cx'], $leaf['cy'], $leaf['rx'] * 0.5), json_encode($leaf));
        }

        // ขอบหยักไม่ล้นผืน 100×118
        $outer = MedalGeometry::ROSETTE_R + MedalGeometry::SCALLOP_R;
        $this->assertLessThanOrEqual(MedalGeometry::WIDTH / 2, $outer);
        $this->assertLessThanOrEqual(MedalGeometry::HEIGHT, MedalGeometry::CY + $outer);

        // ปลายล่างของริบบิ้นจมอยู่ใต้ขอบหยักเสมอ
        foreach (MedalGeometry::ribbon()['bands'] as $band) {
            foreach ([[$band[4], $band[5]], [$band[6], $band[7]]] as [$x, $y]) {
                $this->assertLessThan(MedalGeometry::ROSETTE_R, hypot($x - MedalGeometry::CX, $y - MedalGeometry::CY));
            }
        }
    }

    public function test_public_medal_page_draws_the_template_medal_with_the_year(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip(['medal_icon' => 'coffee']), '2026-09-02', '2026-09-05');
        $this->book($user, $schedule);
        $this->medals()->awardSchedule($schedule);

        $this->get('/m/'.TripMedal::first()->share_token)
            ->assertOk()
            ->assertSee('LUILAYKHAO  •  FINISHER  •  2569')
            ->assertSee('>coffee</text>', false);
    }
}
