<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\LoyaltyService;
use App\Services\SeatLockService;
use App\Services\WaitlistService;
use App\Support\LoyaltyTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyTierPerksTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function userAtTier(string $tier, int $lifetimePoints, int $trips = 0): User
    {
        $user = User::factory()->create();

        LoyaltyAccount::create([
            'user_id' => $user->id,
            'points' => $lifetimePoints,
            'lifetime_points' => $lifetimePoints,
            'lifetime_trips' => $trips,
            'tier' => $tier,
        ]);

        return $user;
    }

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริป '.uniqid(),
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 3500,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(21)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 10,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function makeBooking(User $user, TripSchedule $schedule, float $total): Booking
    {
        return Booking::create([
            'booking_ref' => sprintf('LLK-20260101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => $total,
            'paid_amount' => $total,
        ]);
    }

    // ── ระดับและเกณฑ์ ──

    public function test_tier_follows_the_number_of_trips_travelled(): void
    {
        $this->assertSame(LoyaltyTier::FRIEND, LoyaltyTier::forTrips(0));
        $this->assertSame(LoyaltyTier::FRIEND, LoyaltyTier::forTrips(1));
        $this->assertSame(LoyaltyTier::FREQUENT, LoyaltyTier::forTrips(2));
        $this->assertSame(LoyaltyTier::COMRADE, LoyaltyTier::forTrips(5));
        $this->assertSame(LoyaltyTier::INSIDER, LoyaltyTier::forTrips(10));
        $this->assertSame(LoyaltyTier::INSIDER, LoyaltyTier::forTrips(99));
    }

    public function test_a_big_spender_on_one_trip_is_not_a_regular(): void
    {
        // เกณฑ์เดิมคิดจากยอดเงิน คนที่จองทริปแพงให้ทั้งกลุ่มครั้งเดียวจึงกลายเป็น
        // ขาประจำทันที ทั้งที่ยังไม่เคยกลับมาอีกเลย
        $schedule = $this->makeSchedule();
        $user = $this->userAtTier(LoyaltyTier::FRIEND, 0);

        app(LoyaltyService::class)->awardForBooking($this->makeBooking($user, $schedule, 50000));

        $account = LoyaltyAccount::forUser($user->id);
        $this->assertSame(500, $account->points);
        $this->assertSame(LoyaltyTier::FRIEND, $account->tier);
    }

    public function test_every_tier_name_is_thai(): void
    {
        foreach (LoyaltyTier::all() as $tier) {
            $this->assertMatchesRegularExpression(
                '/^[\x{0E00}-\x{0E7F}\s]+$/u',
                $tier['label'],
                "ชื่อระดับ {$tier['code']} ต้องเป็นภาษาไทยล้วน",
            );
        }
    }

    public function test_an_unknown_tier_code_falls_back_instead_of_breaking(): void
    {
        // ข้อมูลเก่าที่ยังไม่ถูกย้าย ต้องไม่ทำให้หน้าจอพัง
        $this->assertSame('เพื่อนร่วมทาง', LoyaltyTier::label('silver'));
        $this->assertSame(0, LoyaltyTier::rank('gold'));
    }

    public function test_api_serves_one_label_for_web_and_app(): void
    {
        $user = $this->userAtTier(LoyaltyTier::COMRADE, 350, trips: 6);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/loyalty/account')
            ->assertOk()
            ->assertJsonPath('data.tier', 'comrade')
            ->assertJsonPath('data.tier_label', 'สหายนักเดิน')
            ->assertJsonPath('data.lifetime_trips', 6)
            ->assertJsonPath('data.next_tier.label', 'คนกันเอง')
            // เหลืออีก 4 ทริปถึงจะครบ 10 ทริปของระดับคนกันเอง
            ->assertJsonPath('data.next_tier.trips_needed', 4);

        // ส่งบันไดทั้งชุดไปด้วย เพื่อให้ทั้งสองฝั่งไม่ต้องฮาร์ดโค้ดชื่อเอง
        $this->assertCount(4, $response->json('data.tiers'));
        $this->assertNotEmpty($response->json('data.perks'));
    }

    public function test_every_advertised_perk_has_something_enforcing_it(): void
    {
        $user = $this->userAtTier(LoyaltyTier::INSIDER, 900);

        $keys = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/loyalty/account')
                ->assertOk()
                ->json('data.perks')
        )->pluck('key');

        // ทุกข้อที่ประกาศต้องมีโค้ดบังคับใช้จริง (ดูเทสต์ของแต่ละสิทธิ์ประกอบ)
        $this->assertContains('early_access_hours', $keys);
        $this->assertContains('birthday_coupon_baht', $keys);
        $this->assertContains('point_multiplier', $keys);
        $this->assertContains('seat_lock_bonus_minutes', $keys);
        $this->assertContains('deposit_discount_percent', $keys);
        $this->assertContains('waitlist_priority', $keys);
    }

    public function test_top_tier_has_no_next_tier(): void
    {
        $user = $this->userAtTier(LoyaltyTier::INSIDER, 900);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/loyalty/account')
            ->assertOk()
            ->assertJsonPath('data.next_tier', null);
    }

    // ── สิทธิ์: แต้มคูณ ──

    public function test_higher_tier_earns_bonus_points(): void
    {
        $schedule = $this->makeSchedule();
        $service = app(LoyaltyService::class);

        $plain = $this->userAtTier(LoyaltyTier::FRIEND, 0);
        $insider = $this->userAtTier(LoyaltyTier::INSIDER, 700);

        $service->awardForBooking($this->makeBooking($plain, $schedule, 3500));
        $service->awardForBooking($this->makeBooking($insider, $schedule, 3500));

        // 3,500 บาท ÷ 100 = 35 แต้มพื้นฐาน; คนกันเองได้ ×1.5 = 52
        $this->assertSame(35, LoyaltyAccount::forUser($plain->id)->points);
        $this->assertSame(52, LoyaltyAccount::forUser($insider->id)->points - 700);
    }

    public function test_bonus_points_are_explained_in_the_history(): void
    {
        $schedule = $this->makeSchedule();
        $user = $this->userAtTier(LoyaltyTier::COMRADE, 300);

        app(LoyaltyService::class)->awardForBooking($this->makeBooking($user, $schedule, 3500));

        $description = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/loyalty/account')
            ->assertOk()
            ->json('data.transactions.0.description');

        $this->assertStringContainsString('โบนัสระดับสมาชิก', $description);
    }

    // ── สิทธิ์: ล็อกที่นั่งนานขึ้น ──

    public function test_seat_lock_lasts_longer_for_higher_tiers(): void
    {
        $plain = $this->userAtTier(LoyaltyTier::FRIEND, 0);
        $insider = $this->userAtTier(LoyaltyTier::INSIDER, 700);

        $base = SeatLockService::lockTtlSeconds(1, $plain->id);

        $this->assertSame(600, $base);
        $this->assertSame(600 + 15 * 60, SeatLockService::lockTtlSeconds(1, $insider->id));
        // ไม่ส่ง user มาก็ต้องได้ค่าฐานเท่าเดิม (ของเดิมที่เรียกอยู่ต้องไม่เปลี่ยน)
        $this->assertSame(600, SeatLockService::lockTtlSeconds());
    }

    // ── สิทธิ์: มัดจำน้อยลง ──

    public function test_deposit_is_reduced_for_higher_tiers(): void
    {
        $schedule = $this->makeSchedule([
            'deposit_enabled' => true,
            'deposit_type' => 'percent',
            'deposit_percent' => 50,
        ]);

        $plain = $this->userAtTier(LoyaltyTier::FRIEND, 0);
        $insider = $this->userAtTier(LoyaltyTier::INSIDER, 700);

        // มัดจำ 50% ของ 4,000 = 2,000; คนกันเองลดอีก 15% = 1,700
        $this->assertSame(2000.0, $schedule->resolveDepositAmount(4000, 1, $plain->id));
        $this->assertSame(1700.0, $schedule->resolveDepositAmount(4000, 1, $insider->id));
        // ส่วนลดมัดจำต้องไม่ไปแตะราคาทริป
        $this->assertSame(2000.0, $schedule->resolveDepositAmount(4000, 1));
    }

    // ── สิทธิ์: คิวรอที่นั่ง ──

    public function test_higher_tier_jumps_ahead_in_the_waitlist(): void
    {
        $schedule = $this->makeSchedule(['total_seats' => 0, 'booked_seats' => 0]);
        $service = app(WaitlistService::class);

        $early = $this->userAtTier(LoyaltyTier::FRIEND, 0);
        $late = $this->userAtTier(LoyaltyTier::INSIDER, 700);

        $earlyEntry = $service->join($early->id, $schedule->id, 1);
        $lateEntry = $service->join($late->id, $schedule->id, 1);

        $this->assertSame(1, $service->positionInQueue($lateEntry));
        $this->assertSame(2, $service->positionInQueue($earlyEntry));
    }

    public function test_same_tier_still_queues_first_come_first_served(): void
    {
        $schedule = $this->makeSchedule(['total_seats' => 0, 'booked_seats' => 0]);
        $service = app(WaitlistService::class);

        $first = $this->userAtTier(LoyaltyTier::COMRADE, 300);
        $second = $this->userAtTier(LoyaltyTier::COMRADE, 400);

        $firstEntry = $service->join($first->id, $schedule->id, 1);
        // เว้นระยะเวลาให้ created_at ต่างกันแน่นอน
        $this->travel(1)->seconds();
        $secondEntry = $service->join($second->id, $schedule->id, 1);

        $this->assertSame(1, $service->positionInQueue($firstEntry));
        $this->assertSame(2, $service->positionInQueue($secondEntry));
    }

    public function test_queue_position_is_locked_in_at_join_time(): void
    {
        $schedule = $this->makeSchedule(['total_seats' => 0, 'booked_seats' => 0]);
        $service = app(WaitlistService::class);

        $early = $this->userAtTier(LoyaltyTier::FRIEND, 0);
        $earlyEntry = $service->join($early->id, $schedule->id, 1);

        $later = $this->userAtTier(LoyaltyTier::FRIEND, 0);
        $laterEntry = $service->join($later->id, $schedule->id, 1);

        // คนที่มาทีหลังเพิ่งขึ้นระดับ — ต้องไม่แซงคิวคนที่ต่อไว้ก่อนแล้ว
        $account = LoyaltyAccount::forUser($later->id);
        $account->update(['lifetime_points' => 700, 'tier' => LoyaltyTier::INSIDER]);

        $this->assertSame(1, $service->positionInQueue($earlyEntry->fresh()));
        $this->assertSame(2, $service->positionInQueue($laterEntry->fresh()));
    }

    public function test_accounts_stuck_on_a_legacy_tier_are_recalculated(): void
    {
        // บัญชีที่ค้างระดับเก่าไว้ ต้องถูกคำนวณใหม่เมื่อมีการอัปเดตระดับ
        $user = $this->userAtTier('silver', 350, trips: 6);

        $account = LoyaltyAccount::forUser($user->id);
        $account->updateTier();

        $this->assertSame(LoyaltyTier::COMRADE, $account->fresh()->tier);
    }

    public function test_tier_travels_with_the_author_where_others_can_see_it(): void
    {
        $author = $this->userAtTier(LoyaltyTier::INSIDER, 700);

        // ป้ายจะมีความหมายก็ต่อเมื่อคนอื่นเห็น — API ที่คนอื่นอ่านต้องส่งระดับมาด้วย
        $badge = $author->fresh()->tierBadge();

        $this->assertSame(LoyaltyTier::INSIDER, $badge['tier']);
        $this->assertSame('คนกันเอง', $badge['tier_label']);
    }

    public function test_a_user_without_a_loyalty_account_still_has_a_badge(): void
    {
        $user = User::factory()->create();

        $this->assertSame(LoyaltyTier::FRIEND, $user->tierBadge()['tier']);
    }

    public function test_waitlist_entries_default_to_no_priority(): void
    {
        $schedule = $this->makeSchedule();
        $entry = WaitlistEntry::create([
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'seat_count' => 1,
            'status' => 'waiting',
        ]);

        $this->assertSame(0, $entry->fresh()->priority);
    }
}
