<?php

namespace Tests\Feature;

use App\Jobs\ExpireLoyaltyPointsJob;
use App\Jobs\WarnExpiringLoyaltyPointsJob;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Models\SmartNotification;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * แต้มมีอายุ 24 เดือนนับจากวันที่ได้รับ และต้องเตือนล่วงหน้า 30 วันเสมอ
 *
 * แต้มเก็บเป็นล็อต — ก้อนที่ใกล้หมดอายุที่สุดถูกตัดก่อนเวลาแลกของรางวัล ลูกค้า
 * จึงไม่เสียแต้มก้อนที่กำลังจะหมดอายุไปเปล่า ๆ
 */
class LoyaltyPointExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithLot(int $points, string $earnedAt): User
    {
        $user = User::factory()->create();

        $this->travelTo($earnedAt, function () use ($user, $points) {
            app(LoyaltyService::class)->credit($user->id, $points, 'แต้มทดสอบ');
        });

        return $user;
    }

    public function test_points_expire_two_years_after_they_were_earned(): void
    {
        $user = $this->userWithLot(120, '2026-01-15 10:00:00');

        $this->travelTo('2028-01-14 10:00:00');
        (new ExpireLoyaltyPointsJob)->handle(app(LoyaltyService::class));
        $this->assertSame(120, LoyaltyAccount::forUser($user->id)->points, 'ยังไม่ครบ 24 เดือน ต้องไม่หมดอายุ');

        $this->travelTo('2028-01-16 10:00:00');
        (new ExpireLoyaltyPointsJob)->handle(app(LoyaltyService::class));

        $account = LoyaltyAccount::forUser($user->id);
        $this->assertSame(0, $account->points);
        // แต้มสะสมตลอดชีพไม่ถูกลบ — เป็นประวัติ ไม่ใช่ยอดที่ใช้ได้
        $this->assertSame(120, $account->lifetime_points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $user->id,
            'type' => 'expire',
            'points' => -120,
        ]);
    }

    public function test_expiry_is_not_recorded_twice_when_the_job_runs_again(): void
    {
        $user = $this->userWithLot(50, '2026-01-15 10:00:00');

        $this->travelTo('2028-02-01 10:00:00');
        (new ExpireLoyaltyPointsJob)->handle(app(LoyaltyService::class));
        (new ExpireLoyaltyPointsJob)->handle(app(LoyaltyService::class));

        $this->assertSame(1, LoyaltyTransaction::where('user_id', $user->id)->where('type', 'expire')->count());
        $this->assertSame(0, LoyaltyAccount::forUser($user->id)->points);
    }

    public function test_redeeming_spends_the_soonest_expiring_points_first(): void
    {
        $user = User::factory()->create();
        $loyalty = app(LoyaltyService::class);

        $this->travelTo('2026-01-15 10:00:00', fn () => $loyalty->credit($user->id, 100, 'ก้อนเก่า'));
        $this->travelTo('2026-06-15 10:00:00', fn () => $loyalty->credit($user->id, 100, 'ก้อนใหม่'));

        $reward = LoyaltyReward::create([
            'name' => 'ส่วนลด 100 บาท', 'type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
            'points_required' => 100, 'discount_value' => 100, 'is_active' => true,
        ]);

        $this->travelTo('2026-07-01 10:00:00');
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/loyalty/redeem', ['reward_id' => $reward->id])
            ->assertCreated();

        $lots = LoyaltyTransaction::where('user_id', $user->id)
            ->where('type', 'earn')
            ->orderBy('id')
            ->get();

        $this->assertSame(0, (int) $lots[0]->points_remaining, 'ก้อนที่ใกล้หมดอายุต้องถูกตัดก่อน');
        $this->assertSame(100, (int) $lots[1]->points_remaining);

        // ก้อนเก่าถูกใช้ไปแล้ว พอถึงวันหมดอายุของมันจึงไม่มีอะไรให้ล้าง
        $this->travelTo('2028-01-16 10:00:00');
        (new ExpireLoyaltyPointsJob)->handle(app(LoyaltyService::class));
        $this->assertSame(100, LoyaltyAccount::forUser($user->id)->points);
    }

    public function test_customers_are_warned_thirty_days_before(): void
    {
        $user = $this->userWithLot(80, '2026-01-15 10:00:00');

        // 24 เดือนหลังได้แต้ม = 2028-01-15 → เตือนวันที่ 2027-12-16
        $this->travelTo('2027-12-16 09:00:00');
        (new WarnExpiringLoyaltyPointsJob)->handle();

        $notification = SmartNotification::where('user_id', $user->id)
            ->where('type', 'loyalty_points_expiring')
            ->first();

        $this->assertNotNull($notification, 'ต้องเตือนล่วงหน้า 30 วัน');
        $this->assertStringContainsString('80', $notification->body);

        // รันซ้ำวันเดียวกันต้องไม่เตือนซ้ำ
        (new WarnExpiringLoyaltyPointsJob)->handle();
        $this->assertSame(1, SmartNotification::where('user_id', $user->id)
            ->where('type', 'loyalty_points_expiring')->count());
    }

    public function test_tiny_balances_are_not_worth_a_notification(): void
    {
        $user = $this->userWithLot(3, '2026-01-15 10:00:00');

        $this->travelTo('2027-12-16 09:00:00');
        (new WarnExpiringLoyaltyPointsJob)->handle();

        $this->assertSame(0, SmartNotification::where('user_id', $user->id)->count());
    }

    public function test_the_account_endpoint_shows_what_is_about_to_expire(): void
    {
        $user = $this->userWithLot(60, '2026-01-15 10:00:00');

        $this->travelTo('2027-12-20 10:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/loyalty/account')
            ->assertOk()
            ->assertJsonPath('data.expiring_points', 60)
            ->assertJsonPath('data.points_valid_months', LoyaltyService::POINTS_VALID_MONTHS);
    }

    public function test_old_points_get_a_grace_period_when_backfilled(): void
    {
        $user = User::factory()->create();

        // แต้มยุคก่อนมีระบบล็อต — ไม่มีวันหมดอายุ และเก่าเกิน 24 เดือนไปแล้ว
        $account = LoyaltyAccount::forUser($user->id);
        $account->update(['points' => 90, 'lifetime_points' => 90]);
        $lot = LoyaltyTransaction::create([
            'user_id' => $user->id,
            'type' => 'earn',
            'points' => 90,
            'description' => 'แต้มเก่า',
            'balance_after' => 90,
        ]);
        // created_at ไม่ได้อยู่ใน fillable — ต้องเขียนตรง ๆ ให้เป็นแต้มเก่าจริง ๆ
        LoyaltyTransaction::whereKey($lot->id)->update(['created_at' => now()->subYears(3)]);

        $this->artisan('loyalty:backfill')->assertSuccessful();

        // ต้องไม่หายทันทีในวันรุ่งขึ้น — มีระยะผ่อนผันอย่างน้อย 90 วัน
        $this->travelTo(now()->addDays(30));
        (new ExpireLoyaltyPointsJob)->handle(app(LoyaltyService::class));
        $this->assertSame(90, LoyaltyAccount::forUser($user->id)->points);

        $this->travelTo(now()->addDays(70));
        (new ExpireLoyaltyPointsJob)->handle(app(LoyaltyService::class));
        $this->assertSame(0, LoyaltyAccount::forUser($user->id)->points);
    }
}
