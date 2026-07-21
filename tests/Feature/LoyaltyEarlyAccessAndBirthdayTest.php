<?php

namespace Tests\Feature;

use App\Jobs\IssueBirthdayCouponsJob;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Support\LoyaltyTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyEarlyAccessAndBirthdayTest extends TestCase
{
    use RefreshDatabase;

    private function userAtTier(string $tier, array $userAttributes = []): User
    {
        $user = User::factory()->create($userAttributes);

        LoyaltyAccount::create([
            'user_id' => $user->id,
            'points' => 0,
            'lifetime_points' => 0,
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
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 3000,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(30)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(31)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function book(User $user, TripSchedule $schedule, ?string $code = null)
    {
        return app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [['name' => 'ผู้เดินทาง', 'phone' => '0812345678']],
            promotionCode: $code,
            verifySeatLocks: false,
        );
    }

    // ── สิทธิ์จองก่อนใคร ──

    public function test_a_schedule_without_an_opening_time_is_open_to_everyone(): void
    {
        $schedule = $this->makeSchedule();
        $user = $this->userAtTier(LoyaltyTier::FRIEND);

        // รอบเดิมทั้งหมดไม่ได้ตั้งเวลาเปิดจอง จึงต้องจองได้เหมือนเดิมทุกประการ
        $this->assertTrue($schedule->isBookableBy($user->id));
        $this->assertNotNull($this->book($user, $schedule));
    }

    public function test_a_plain_member_cannot_book_before_the_public_opening(): void
    {
        $schedule = $this->makeSchedule([
            'booking_opens_at' => now()->addHours(6),
        ]);
        $user = $this->userAtTier(LoyaltyTier::FRIEND);

        $this->assertFalse($schedule->isBookableBy($user->id));

        $this->expectExceptionMessageMatches('/ยังไม่เปิดจอง/');
        $this->book($user, $schedule);
    }

    public function test_a_high_tier_member_gets_in_during_the_early_window(): void
    {
        // เปิดสาธารณะอีก 6 ชม. — คนกันเองได้ก่อน 24 ชม. จึงเข้าได้แล้ว
        $schedule = $this->makeSchedule([
            'booking_opens_at' => now()->addHours(6),
        ]);

        $insider = $this->userAtTier(LoyaltyTier::INSIDER);
        $frequent = $this->userAtTier(LoyaltyTier::FREQUENT);

        $this->assertTrue($schedule->isBookableBy($insider->id));
        // ขาประจำไม่มีสิทธิ์จองก่อน จึงยังเข้าไม่ได้
        $this->assertFalse($schedule->isBookableBy($frequent->id));

        $this->assertNotNull($this->book($insider, $schedule));
    }

    public function test_early_window_is_not_wide_enough_for_a_distant_opening(): void
    {
        // เปิดสาธารณะอีก 3 วัน — ไกลเกินหน้าต่าง 24 ชม. ของระดับสูงสุด
        $schedule = $this->makeSchedule([
            'booking_opens_at' => now()->addDays(3),
        ]);

        $this->assertFalse($schedule->isBookableBy($this->userAtTier(LoyaltyTier::INSIDER)->id));
    }

    public function test_seat_lock_is_refused_before_the_window_opens(): void
    {
        $schedule = $this->makeSchedule(['booking_opens_at' => now()->addHours(6)]);
        $user = $this->userAtTier(LoyaltyTier::FRIEND);

        // ต้องกันตั้งแต่ล็อกที่นั่ง ไม่งั้นคนที่ยังจองไม่ได้จะยึดที่นั่งกันคนอื่นไว้
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/seats/lock", ['seat_ids' => ['A1']])
            ->assertStatus(422);
    }

    // ── ส่วนลดวันเกิด ──

    public function test_birthday_coupon_is_issued_to_eligible_members_only(): void
    {
        $today = now('Asia/Bangkok');

        $comrade = $this->userAtTier(LoyaltyTier::COMRADE, [
            'birth_date' => $today->copy()->subYears(30)->toDateString(),
        ]);
        $friend = $this->userAtTier(LoyaltyTier::FRIEND, [
            'birth_date' => $today->copy()->subYears(25)->toDateString(),
        ]);
        $notToday = $this->userAtTier(LoyaltyTier::INSIDER, [
            'birth_date' => $today->copy()->subYears(40)->addDays(3)->toDateString(),
        ]);

        (new IssueBirthdayCouponsJob)->handle();

        $this->assertDatabaseHas('loyalty_redemptions', [
            'user_id' => $comrade->id,
            'source' => LoyaltyRedemption::SOURCE_BIRTHDAY,
            'discount_value' => 200,
        ]);
        // ระดับเริ่มต้นไม่มีของขวัญวันเกิด
        $this->assertDatabaseMissing('loyalty_redemptions', ['user_id' => $friend->id]);
        $this->assertDatabaseMissing('loyalty_redemptions', ['user_id' => $notToday->id]);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $comrade->id,
            'type' => 'birthday_coupon',
        ]);
    }

    public function test_birthday_coupon_is_not_issued_twice_in_the_same_year(): void
    {
        $user = $this->userAtTier(LoyaltyTier::INSIDER, [
            'birth_date' => now('Asia/Bangkok')->copy()->subYears(30)->toDateString(),
        ]);

        (new IssueBirthdayCouponsJob)->handle();
        (new IssueBirthdayCouponsJob)->handle();

        $this->assertSame(1, LoyaltyRedemption::where('user_id', $user->id)->count());
        $this->assertSame(1, SmartNotification::where('user_id', $user->id)->count());
    }

    // ── คูปองใช้ได้จริงตอนจอง ──

    public function test_a_birthday_coupon_actually_reduces_the_booking_total(): void
    {
        $user = $this->userAtTier(LoyaltyTier::INSIDER, [
            'birth_date' => now('Asia/Bangkok')->copy()->subYears(30)->toDateString(),
        ]);
        (new IssueBirthdayCouponsJob)->handle();

        $coupon = LoyaltyRedemption::where('user_id', $user->id)->firstOrFail();
        $booking = $this->book($user, $this->makeSchedule(), $coupon->coupon_code);

        // 3,000 − 300 = 2,700
        $this->assertSame('2700.00', $booking->total_amount);
        $this->assertSame('300.00', $booking->discount_amount);

        $coupon->refresh();
        $this->assertTrue($coupon->is_used);
        $this->assertSame($booking->id, $coupon->booking_id);
    }

    public function test_a_points_reward_coupon_is_redeemable_too(): void
    {
        // เดิมคูปองที่แลกด้วยแต้มใช้ตอนจองไม่ได้เลย เพราะ BookingService ดูแค่
        // ตาราง promotions
        $user = $this->userAtTier(LoyaltyTier::FRIEND);
        $reward = LoyaltyReward::create([
            'name' => 'ส่วนลด 500',
            'type' => 'discount_fixed',
            'points_required' => 50,
            'discount_value' => 500,
            'is_active' => true,
        ]);

        $coupon = LoyaltyRedemption::create([
            'user_id' => $user->id,
            'reward_id' => $reward->id,
            'points_used' => 50,
            'coupon_code' => LoyaltyRedemption::generateCoupon(),
            'expires_at' => now()->addDays(30),
        ]);

        $booking = $this->book($user, $this->makeSchedule(), $coupon->coupon_code);

        $this->assertSame('2500.00', $booking->total_amount);
        $this->assertTrue($coupon->fresh()->is_used);
    }

    public function test_a_coupon_cannot_be_used_twice(): void
    {
        $user = $this->userAtTier(LoyaltyTier::INSIDER, [
            'birth_date' => now('Asia/Bangkok')->copy()->subYears(30)->toDateString(),
        ]);
        (new IssueBirthdayCouponsJob)->handle();

        $coupon = LoyaltyRedemption::where('user_id', $user->id)->firstOrFail();
        $this->book($user, $this->makeSchedule(), $coupon->coupon_code);

        $this->expectExceptionMessageMatches('/คูปองนี้ใช้ไม่ได้/');
        $this->book($user, $this->makeSchedule(), $coupon->coupon_code);
    }

    public function test_someone_elses_coupon_is_refused(): void
    {
        $owner = $this->userAtTier(LoyaltyTier::INSIDER, [
            'birth_date' => now('Asia/Bangkok')->copy()->subYears(30)->toDateString(),
        ]);
        (new IssueBirthdayCouponsJob)->handle();

        $coupon = LoyaltyRedemption::where('user_id', $owner->id)->firstOrFail();
        $stranger = $this->userAtTier(LoyaltyTier::FRIEND);

        $this->expectExceptionMessageMatches('/คูปองนี้ใช้ไม่ได้/');
        $this->book($stranger, $this->makeSchedule(), $coupon->coupon_code);
    }

    public function test_an_expired_coupon_is_refused(): void
    {
        $user = $this->userAtTier(LoyaltyTier::FRIEND);
        $coupon = LoyaltyRedemption::create([
            'user_id' => $user->id,
            'reward_id' => null,
            'source' => LoyaltyRedemption::SOURCE_BIRTHDAY,
            'points_used' => 0,
            'discount_value' => 300,
            'coupon_code' => LoyaltyRedemption::generateCoupon(),
            'expires_at' => now()->subDay(),
        ]);

        $this->expectExceptionMessageMatches('/คูปองนี้ใช้ไม่ได้/');
        $this->book($user, $this->makeSchedule(), $coupon->coupon_code);
    }
}
