<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Category;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\MissionCompletion;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MedalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ระบบแต้มเฟส 2 — ภารกิจ/ชาเลนจ์ที่ให้แต้ม (กดรับเอง ไม่มีการสุ่ม)
 */
class LoyaltyMissionTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelToBangkok('2026-12-20 12:00');
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

    private function trip(string $type = 'trekking'): Trip
    {
        return Trip::create([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'mission-trip-'.uniqid(),
            'type' => $type,
            'location' => 'เชียงใหม่',
            'region' => 'north',
            'difficulty' => 'hard',
            'duration_days' => 1,
            'distance_km' => 20,
            'elevation_gain_m' => 1500,
            'max_participants' => 12,
            'price_per_person' => 2000,
            'status' => 'active',
        ]);
    }

    private function booking(User $user, Trip $trip, string $date, string $status = 'confirmed', ?string $createdAt = null): Booking
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

        $booking = Booking::create([
            'booking_ref' => sprintf('LLK-20261201-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 2000,
            'paid_amount' => 2000,
        ]);

        if ($createdAt) {
            $booking->forceFill(['created_at' => CarbonImmutable::parse($createdAt, 'Asia/Bangkok')])->saveQuietly();
        }

        return $booking;
    }

    private function board(User $user): array
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/challenges')->assertOk()->json('data');
    }

    private function mission(array $board, string $key): array
    {
        return collect($board['missions'])->firstWhere('key', $key);
    }

    private function claim(User $user, string $kind, string $key, string $scope)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/challenges/claim', compact('kind', 'key', 'scope'));
    }

    private function points(User $user): int
    {
        return (int) LoyaltyAccount::forUser($user->id)->points;
    }

    public function test_comeback_counts_down_after_a_trip_and_pays_once_the_next_trip_is_booked(): void
    {
        $user = User::factory()->create();
        $trip = $this->trip();
        $this->booking($user, $trip, '2026-12-10', createdAt: '2026-11-01 10:00');

        $comeback = $this->mission($this->board($user), 'comeback');
        $this->assertSame('in_progress', $comeback['state']);
        $this->assertSame(50, $comeback['points']);
        $this->assertSame('2027-02-08', $comeback['deadline']);
        $this->assertSame(50, $comeback['days_left']);
        $this->assertSame('trips', $comeback['action']);

        $next = $this->booking($user, $trip, '2027-01-15');
        $comeback = $this->mission($this->board($user), 'comeback');
        $this->assertSame('claimable', $comeback['state']);
        $before = $this->points($user);

        $this->claim($user, 'mission', 'comeback', $comeback['scope'])
            ->assertOk()
            ->assertJsonPath('data.points', 50);
        $this->assertSame($before + 50, $this->points($user));
        $this->assertSame('claimed', $this->mission($this->board($user), 'comeback')['state']);

        // รับซ้ำไม่ได้
        $this->claim($user, 'mission', 'comeback', $comeback['scope'])->assertStatus(422);

        // ยกเลิกใบจองที่ทำให้สำเร็จ → หักแต้มคืนและเปิดภารกิจใหม่
        $next->update(['status' => 'cancelled']);
        $this->assertSame($before, $this->points($user));
        $this->assertSame(0, MissionCompletion::count());
        $this->assertSame('in_progress', $this->mission($this->board($user), 'comeback')['state']);
    }

    public function test_comeback_booked_after_the_window_does_not_count(): void
    {
        $user = User::factory()->create();
        $trip = $this->trip();
        $this->booking($user, $trip, '2026-09-01', createdAt: '2026-08-01 10:00');
        $this->booking($user, $trip, '2027-01-20', createdAt: '2026-12-01 10:00');

        $comeback = $this->mission($this->board($user), 'comeback');
        $this->assertSame('locked', $comeback['state']);
        $this->claim($user, 'mission', 'comeback', '1')->assertStatus(422);
    }

    public function test_first_trip_unlocks_nothing_but_a_new_kind_of_trip_pays(): void
    {
        Category::create(['name' => 'ดำน้ำตื้น', 'slug' => 'snorkel']);
        $user = User::factory()->create();
        $this->booking($user, $this->trip('trekking'), '2026-10-01');

        $this->assertSame('in_progress', $this->mission($this->board($user), 'new_activity')['state']);

        $this->booking($user, $this->trip('snorkel'), '2026-12-01');
        $item = $this->mission($this->board($user), 'new_activity');
        $this->assertSame('claimable', $item['state']);
        $this->assertSame('snorkel', $item['scope']);
        $this->assertStringContainsString('ดำน้ำตื้น', $item['title']);

        $this->claim($user, 'mission', 'new_activity', 'snorkel')->assertOk()->assertJsonPath('data.points', 30);
        $this->assertSame('in_progress', $this->mission($this->board($user), 'new_activity')['state']);
    }

    public function test_profile_and_first_review_missions(): void
    {
        $user = User::factory()->create(['phone' => '0812345678', 'birth_date' => null]);

        $profile = $this->mission($this->board($user), 'profile_complete');
        $this->assertSame('in_progress', $profile['state']);
        $this->assertSame(1, $profile['current']);
        $this->assertSame(4, $profile['target']);
        $this->claim($user, 'mission', 'profile_complete', 'once')->assertStatus(422);

        $user->update(['birth_date' => '1990-01-01', 'emergency_contact' => 'แม่', 'emergency_phone' => '0898765432']);
        $this->assertSame('claimable', $this->mission($this->board($user), 'profile_complete')['state']);
        $this->claim($user, 'mission', 'profile_complete', 'once')->assertOk();

        $this->assertSame('in_progress', $this->mission($this->board($user), 'first_review')['state']);
        $booking = $this->booking($user, $this->trip(), '2026-12-01');
        Review::create(['user_id' => $user->id, 'booking_id' => $booking->id, 'trip_id' => $booking->schedule->trip_id, 'rating' => 5]);
        $board = $this->board($user);
        $this->assertSame('claimable', $this->mission($board, 'first_review')['state']);
        // รีวิว 20 + ชาเลนจ์ที่ทริปนี้อาจทำให้สำเร็จ — ป้ายรวมต้องมีแต้มรีวิวอยู่ด้วย
        $this->assertGreaterThanOrEqual(20, $board['claimable_points']);
        $this->claim($user, 'mission', 'first_review', 'once')->assertOk();
        $this->assertSame(40, $this->points($user) - $this->earnedFromTrips($user));
    }

    public function test_challenges_completed_since_launch_pay_points_once(): void
    {
        $user = User::factory()->create();
        $trip = $this->trip();
        $this->booking($user, $trip, '2026-12-05');
        app(MedalService::class)->awardSchedule(Booking::first()->schedule);

        $month = collect($this->board($user)['month']['challenges'])->keyBy('key');
        $this->assertTrue($month['month_trip']['points_claimable']);
        $this->assertSame(20, $month['month_trip']['points']);
        $this->assertSame('2026-12', $month['month_trip']['period']);

        $before = $this->points($user);
        $this->claim($user, 'challenge', 'month_trip', '2026-12')->assertOk()->assertJsonPath('data.points', 20);
        $this->claim($user, 'challenge', 'month_trip', '2026-12')->assertStatus(422);
        $this->assertSame($before + 20, $this->points($user));

        $month = collect($this->board($user)['month']['challenges'])->keyBy('key');
        $this->assertFalse($month['month_trip']['points_claimable']);
        $this->assertTrue($month['month_trip']['points_claimed']);
    }

    public function test_challenges_completed_before_launch_have_nothing_to_claim(): void
    {
        $this->travelToBangkok('2026-10-20 12:00');
        $user = User::factory()->create();
        $this->booking($user, $this->trip(), '2026-10-05');
        app(MedalService::class)->awardSchedule(Booking::first()->schedule);

        $month = collect($this->board($user)['month']['challenges'])->keyBy('key');
        $this->assertTrue($month['month_trip']['completed']);
        $this->assertFalse($month['month_trip']['points_claimable']);
        $this->claim($user, 'challenge', 'month_trip', '2026-10')->assertStatus(422);
    }

    public function test_missions_can_be_switched_off(): void
    {
        config(['loyalty.missions.enabled' => false]);
        $user = User::factory()->create();

        $this->assertSame([], $this->board($user)['missions']);
        $this->assertSame(0, $this->board($user)['claimable_points']);
    }

    /** แต้มที่ได้จากการจอง (BookingObserver ให้แต้มตอนยืนยัน) ไม่ใช่จากภารกิจ */
    private function earnedFromTrips(User $user): int
    {
        return (int) LoyaltyTransaction::where('user_id', $user->id)
            ->where('reference_type', Booking::class)
            ->where('type', 'earn')
            ->sum('points');
    }
}
