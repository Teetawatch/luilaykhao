<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ของรางวัลที่แลกด้วยแต้มต้องลดเงินได้จริงตามชนิดของมัน
 *
 * ก่อนหน้านี้มีแค่ discount_fixed ที่ทำงาน — ส่วนลดเปอร์เซ็นต์ถูกเอาไปลบเป็นบาท
 * (ตั้ง "ลด 10%" กลายเป็นลด 10 บาท) และของรางวัลที่ไม่ใช่ส่วนลดใช้ไม่ได้เลย
 */
class LoyaltyRewardRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['loyalty.baht_per_point' => 100]);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปเช่าของ', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'ตาก', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 20, 'price_per_person' => 3000, 'status' => 'active',
            'rental_items' => [
                ['name' => 'เต็นท์ 2 คน', 'price' => 300],
                ['name' => 'ถุงนอน', 'price' => 150],
            ],
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 20, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function userWithPoints(int $points): User
    {
        $user = User::factory()->create();
        app(LoyaltyService::class)->credit($user->id, $points, 'แต้มตั้งต้นสำหรับเทสต์');

        return $user;
    }

    private function redeem(User $user, LoyaltyReward $reward): string
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/loyalty/redeem', ['reward_id' => $reward->id])
            ->assertCreated()
            ->json('data.coupon_code');
    }

    private function book(User $user, TripSchedule $schedule, string $code, array $rentals = [])
    {
        return app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [[
                'title' => 'นาย', 'name' => 'ผู้เดินทาง', 'nickname' => 'เอ',
                'phone' => '0812345678', 'email' => 'a@example.test',
            ]],
            promotionCode: $code,
            selectedRentals: $rentals,
        );
    }

    public function test_a_percent_reward_discounts_a_percentage_not_baht(): void
    {
        $reward = LoyaltyReward::create([
            'name' => 'ส่วนลด 10%', 'type' => LoyaltyReward::TYPE_DISCOUNT_PERCENT,
            'points_required' => 100, 'discount_value' => 10, 'is_active' => true,
        ]);

        $user = $this->userWithPoints(200);
        $code = $this->redeem($user, $reward);

        $booking = $this->book($user, $this->makeSchedule(), $code);

        // ทริป 3,000 บาท ลด 10% = 300 บาท (ของเดิมลดไป 10 บาท)
        $this->assertEqualsWithDelta(300.0, (float) $booking->discount_amount, 0.01);
        $this->assertEqualsWithDelta(2700.0, (float) $booking->total_amount, 0.01);
    }

    public function test_a_free_rental_reward_waives_only_the_rental_cost(): void
    {
        $reward = LoyaltyReward::create([
            'name' => 'เช่าอุปกรณ์ฟรี ไม่เกิน 300 บาท', 'type' => LoyaltyReward::TYPE_FREE_RENTAL,
            'points_required' => 70, 'discount_value' => 300, 'is_active' => true,
        ]);

        $user = $this->userWithPoints(100);
        $code = $this->redeem($user, $reward);

        // เช่าเต็นท์ 300 + ถุงนอน 150 = 450 บาท เพดานคูปอง 300
        $booking = $this->book($user, $this->makeSchedule(), $code, [
            ['index' => 0, 'quantity' => 1],
            ['index' => 1, 'quantity' => 1],
        ]);

        $this->assertEqualsWithDelta(300.0, (float) $booking->discount_amount, 0.01);
        // 3,000 (ทริป) + 450 (ค่าเช่า) − 300 = 3,150
        $this->assertEqualsWithDelta(3150.0, (float) $booking->total_amount, 0.01);
    }

    public function test_a_free_rental_reward_never_discounts_the_trip_itself(): void
    {
        $reward = LoyaltyReward::create([
            'name' => 'เช่าอุปกรณ์ฟรี ไม่เกิน 500 บาท', 'type' => LoyaltyReward::TYPE_FREE_RENTAL,
            'points_required' => 70, 'discount_value' => 500, 'is_active' => true,
        ]);

        $user = $this->userWithPoints(100);
        $code = $this->redeem($user, $reward);

        // เช่าแค่ถุงนอน 150 บาท — ลดได้แค่ 150 ไม่ใช่ 500
        $booking = $this->book($user, $this->makeSchedule(), $code, [
            ['index' => 1, 'quantity' => 1],
        ]);

        $this->assertEqualsWithDelta(150.0, (float) $booking->discount_amount, 0.01);
    }

    public function test_using_a_rental_coupon_without_renting_says_why(): void
    {
        $reward = LoyaltyReward::create([
            'name' => 'เช่าอุปกรณ์ฟรี', 'type' => LoyaltyReward::TYPE_FREE_RENTAL,
            'points_required' => 70, 'discount_value' => 300, 'is_active' => true,
        ]);

        $user = $this->userWithPoints(100);
        $code = $this->redeem($user, $reward);

        $this->expectExceptionMessage('คูปองนี้ใช้กับค่าเช่าอุปกรณ์');
        $this->book($user, $this->makeSchedule(), $code);
    }

    public function test_redeeming_spends_points_and_respects_stock(): void
    {
        $reward = LoyaltyReward::create([
            'name' => 'ส่วนลด 200 บาท', 'type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
            'points_required' => 150, 'discount_value' => 200, 'is_active' => true, 'stock' => 1,
        ]);

        $user = $this->userWithPoints(400);
        $this->redeem($user, $reward);

        $this->assertSame(250, LoyaltyAccount::forUser($user->id)->points);
        $this->assertSame(0, (int) $reward->fresh()->stock);

        // ชิ้นสุดท้ายถูกแลกไปแล้ว คนต่อไปต้องไม่เสียแต้มฟรี
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/loyalty/redeem', ['reward_id' => $reward->id])
            ->assertStatus(422);

        $this->assertSame(250, LoyaltyAccount::forUser($user->id)->points);
    }

    public function test_redeeming_more_than_the_balance_is_refused(): void
    {
        $reward = LoyaltyReward::create([
            'name' => 'ส่วนลด 500 บาท', 'type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
            'points_required' => 400, 'discount_value' => 500, 'is_active' => true,
        ]);

        $user = $this->userWithPoints(100);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/loyalty/redeem', ['reward_id' => $reward->id])
            ->assertStatus(422);

        $this->assertSame(100, LoyaltyAccount::forUser($user->id)->points);
    }

    public function test_the_catalogue_explains_each_reward_value_in_thai(): void
    {
        LoyaltyReward::create([
            'name' => 'เช่าอุปกรณ์ฟรี', 'type' => LoyaltyReward::TYPE_FREE_RENTAL,
            'points_required' => 70, 'discount_value' => 300, 'is_active' => true,
        ]);

        $labels = collect(
            $this->actingAs($this->userWithPoints(0), 'sanctum')
                ->getJson('/api/v1/loyalty/rewards')
                ->assertOk()
                ->json('data')
        )->pluck('value_label');

        $this->assertContains('เช่าอุปกรณ์ฟรี มูลค่าไม่เกิน 300 บาท', $labels);
    }

    public function test_a_birthday_coupon_lists_its_own_value_in_my_coupons(): void
    {
        // คูปองวันเกิดไม่มีของรางวัลผูก มูลค่าอยู่บนตัวคูปอง — เดิมแอปเห็นแค่ "คูปอง" เปล่า ๆ
        $user = User::factory()->create();
        LoyaltyRedemption::create([
            'user_id' => $user->id,
            'reward_id' => null,
            'source' => LoyaltyRedemption::SOURCE_BIRTHDAY,
            'points_used' => 0,
            'discount_value' => 200,
            'coupon_code' => 'TRD-BDAY0001',
            'expires_at' => now()->addDays(30),
        ]);

        $coupon = $this->actingAs($user)->getJson('/api/v1/loyalty/coupons')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('birthday', $coupon['source']);
        $this->assertSame('ของขวัญวันเกิด', $coupon['reward_name']);
        $this->assertSame(LoyaltyReward::TYPE_DISCOUNT_FIXED, $coupon['reward_type']);
        $this->assertEquals(200, $coupon['discount_value']);
        $this->assertSame('ลด 200 บาท', $coupon['value_label']);
    }

    public function test_the_account_carries_what_the_member_card_prints(): void
    {
        $user = $this->userWithPoints(120);

        $data = $this->actingAs($user)->getJson('/api/v1/loyalty/account')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['member_code']);
        $this->assertNotEmpty($data['member_since']);
        $this->assertCount(4, $data['tiers']);
        // รหัสบนบัตรต้องเป็นรหัสเดียวกับหน้าโอนแต้ม ไม่งั้นเพื่อนสแกนแล้วโอนไม่ได้
        $this->assertSame(
            $data['member_code'],
            $this->actingAs($user)->getJson('/api/v1/loyalty/transfers')->json('data.member_code'),
        );
    }

    public function test_validating_a_rental_coupon_tells_the_screen_its_cap(): void
    {
        // หน้าจองคิดส่วนลดตัวอย่างเองจาก coupon_type + rental_cap — เดิมได้แค่ fixed 0
        // ลูกค้าเลยเห็นส่วนลด 0 บาททั้งที่จองจริงหักค่าเช่าให้
        $reward = LoyaltyReward::create([
            'name' => 'เช่าอุปกรณ์ฟรี ไม่เกิน 300 บาท', 'type' => LoyaltyReward::TYPE_FREE_RENTAL,
            'points_required' => 70, 'discount_value' => 300, 'is_active' => true,
        ]);
        $user = $this->userWithPoints(100);
        $code = $this->redeem($user, $reward);
        $schedule = $this->makeSchedule();

        $promotion = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/promotions/validate', ['code' => $code, 'trip_id' => $schedule->trip_id])
            ->assertOk()
            ->json('promotion');

        $this->assertSame(LoyaltyReward::TYPE_FREE_RENTAL, $promotion['coupon_type']);
        $this->assertEquals(300, $promotion['rental_cap']);
        // แอปรุ่นเก่าที่ไม่รู้จัก coupon_type ยังเห็นส่วนลด 0 ไม่ใช่ลดเกินจริง
        $this->assertSame('fixed', $promotion['type']);
        $this->assertEquals(0, $promotion['value']);
    }
}
