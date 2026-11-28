<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ระบบแต้มเฟส 2 — ใช้แต้มลดค่าทริปตอนจอง (1 แต้ม = 1 บาท ไม่เกิน 50% ของยอด
 * ขั้นต่ำ 10 แต้ม) คืนเป็นล็อตเดิมเมื่อยกเลิก และไทม์ไลน์แต้มหมดอายุ
 */
class PayWithPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-08 20:00:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function schedule(float $price = 1500): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'points-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => $price,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-06-10',
            'return_date' => '2026-06-11',
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function givePoints(User $user, int $points, ?Carbon $expiresAt = null): LoyaltyTransaction
    {
        app(LoyaltyService::class)->credit($user->id, $points, 'ทดสอบ');
        $lot = LoyaltyTransaction::where('user_id', $user->id)->where('type', 'earn')->latest('id')->first();

        if ($expiresAt) {
            $lot->update(['expires_at' => $expiresAt]);
        }

        return $lot;
    }

    private function book(User $user, TripSchedule $schedule, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'schedule_id' => $schedule->id,
            'passengers' => [[
                'title' => 'นาย',
                'name' => 'ผู้เดินทาง ทดสอบ',
                'nickname' => 'ทด',
                'id_card' => '1234567890121',
                'phone' => '0812345678',
                'blood_group' => 'O',
                'halal_food' => false,
                'emergency_contact' => 'แม่',
                'emergency_phone' => '0898765432',
            ]],
        ], $extra));
    }

    public function test_points_lower_the_price_up_to_half_and_are_spent_from_the_oldest_lot(): void
    {
        $user = User::factory()->create();
        $soon = $this->givePoints($user, 300, now()->addMonths(2));
        $later = $this->givePoints($user, 700, now()->addMonths(20));

        $res = $this->book($user, $this->schedule(1500), ['points_to_use' => 5000])->assertCreated();

        // เพดาน 50% ของ 1,500 = 750 แต้ม
        $this->assertSame(750, $res->json('data.points_used'));
        $this->assertSame(750.0, (float) $res->json('data.points_discount'));
        $booking = Booking::where('booking_ref', $res->json('data.booking_ref'))->first();
        $this->assertSame(750.0, (float) $booking->total_amount);
        $this->assertSame(750.0, (float) $booking->discount_amount);
        $this->assertSame(250, (int) LoyaltyAccount::forUser($user->id)->points);

        // ล็อตที่ใกล้หมดอายุถูกตัดก่อน
        $this->assertSame(0, (int) $soon->fresh()->points_remaining);
        $this->assertSame(250, (int) $later->fresh()->points_remaining);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $user->id,
            'type' => 'redeem',
            'points' => -750,
            'reference_type' => Booking::class,
            'reference_id' => $booking->id,
        ]);
    }

    public function test_coins_and_points_stack_with_points_capped_on_what_is_left(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['coin_balance' => 500])->save();
        $this->givePoints($user, 2000);

        $res = $this->book($user, $this->schedule(1000), ['coins_to_use' => 500, 'points_to_use' => 2000])
            ->assertCreated();

        // Coin 10% ของ 1,000 = 100 → เหลือ 900, แต้ม 50% ของ 900 = 450
        $this->assertSame(100, $res->json('data.coins_used'));
        $this->assertSame(450, $res->json('data.points_used'));
        $this->assertSame(450.0, (float) $res->json('data.total_amount'));
    }

    public function test_fewer_points_than_the_minimum_are_not_used(): void
    {
        $user = User::factory()->create();
        $this->givePoints($user, 5);

        $res = $this->book($user, $this->schedule(1500), ['points_to_use' => 5])->assertCreated();

        $this->assertSame(0, $res->json('data.points_used'));
        $this->assertSame(1500.0, (float) $res->json('data.total_amount'));
        $this->assertSame(5, (int) LoyaltyAccount::forUser($user->id)->points);
    }

    public function test_cancelling_gives_the_points_back_once_with_their_old_expiry(): void
    {
        $user = User::factory()->create();
        $expiry = now()->addMonths(6);
        $this->givePoints($user, 200, $expiry);
        $ref = $this->book($user, $this->schedule(1500), ['points_to_use' => 200])->json('data.booking_ref');
        $this->assertSame(0, (int) LoyaltyAccount::forUser($user->id)->points);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$ref}/cancel", ['reason' => 'เปลี่ยนใจ'])
            ->assertOk();

        $this->assertSame(200, (int) LoyaltyAccount::forUser($user->id)->points);
        $restored = LoyaltyTransaction::where('user_id', $user->id)
            ->where('type', 'earn')
            ->where('reference_type', LoyaltyTransaction::class)
            ->first();
        $this->assertNotNull($restored);
        $this->assertSame(200, (int) $restored->points_remaining);
        $this->assertSame($expiry->toDateString(), $restored->expires_at->toDateString());

        // เรียกซ้ำไม่คืนเกิน และคืนแล้วไม่ถูกนับเป็นแต้มที่ได้จากทริป
        app(LoyaltyService::class)->restoreForBooking(Booking::where('booking_ref', $ref)->first());
        $this->assertSame(200, (int) LoyaltyAccount::forUser($user->id)->points);
        $this->assertSame(200, (int) LoyaltyAccount::forUser($user->id)->lifetime_points);
    }

    public function test_restored_points_that_already_expired_get_thirty_days(): void
    {
        $user = User::factory()->create();
        $this->givePoints($user, 100, now()->addDays(5));
        $ref = $this->book($user, $this->schedule(1500), ['points_to_use' => 100])->json('data.booking_ref');

        Carbon::setTestNow(now()->addDays(10));
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$ref}/cancel", ['reason' => 'เปลี่ยนใจ'])
            ->assertOk();

        $restored = LoyaltyTransaction::where('reference_type', LoyaltyTransaction::class)->first();
        $this->assertTrue($restored->expires_at->greaterThanOrEqualTo(now()->addDays(29)));
    }

    public function test_paying_with_points_earns_only_on_the_cash_part(): void
    {
        $user = User::factory()->create();
        $this->givePoints($user, 500);
        $ref = $this->book($user, $this->schedule(2000), ['points_to_use' => 500])->json('data.booking_ref');
        $booking = Booking::where('booking_ref', $ref)->first();

        $booking->update(['status' => 'confirmed', 'paid_amount' => $booking->total_amount]);

        // จ่ายเงินจริง 1,500 → ได้ 15 แต้ม (ส่วนที่แต้มจ่ายไม่ได้แต้มซ้ำ)
        $earned = LoyaltyTransaction::where('reference_type', Booking::class)
            ->where('reference_id', $booking->id)
            ->where('type', 'earn')
            ->value('points');
        $this->assertSame(15, (int) $earned);
    }

    public function test_account_sends_the_pay_rules_and_the_expiry_timeline(): void
    {
        $user = User::factory()->create();
        $this->givePoints($user, 40, Carbon::parse('2026-07-03 10:00', 'Asia/Bangkok'));
        $this->givePoints($user, 60, Carbon::parse('2026-07-25 10:00', 'Asia/Bangkok'));
        $this->givePoints($user, 25, Carbon::parse('2027-01-10 10:00', 'Asia/Bangkok'));

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/loyalty/account')->assertOk();

        $res->assertJsonPath('data.pay_with_points.enabled', true)
            ->assertJsonPath('data.pay_with_points.max_percent', 50)
            ->assertJsonPath('data.pay_with_points.min_points', 10)
            ->assertJsonPath('data.expiry_timeline.0.month', '2026-07')
            ->assertJsonPath('data.expiry_timeline.0.points', 100)
            ->assertJsonPath('data.expiry_timeline.1.month', '2027-01')
            ->assertJsonPath('data.expiry_timeline.1.points', 25);
    }
}
