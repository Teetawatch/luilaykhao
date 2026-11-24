<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Promotion;
use App\Models\SavedPromotion;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แจกคูปองจำนวนจำกัด ("แจก 100 ใบ ให้ลูกค้าเก็บไว้ใช้") + จำกัดครั้งต่อคน
 * + คืนสิทธิ์เมื่อใบจองถูกยกเลิก
 */
class PromotionClaimLimitTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริป '.uniqid(), 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 20, 'price_per_person' => 1000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(30)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(30)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function promo(array $extra = []): Promotion
    {
        return Promotion::create(array_merge([
            'code' => 'GIVE100', 'name' => 'แจก 100 ใบ', 'type' => 'fixed', 'value' => 100,
            'is_active' => true,
        ], $extra));
    }

    private function book(User $user, TripSchedule $schedule, string $code = 'GIVE100'): Booking
    {
        return app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: [['name' => 'ผู้เดินทาง', 'phone' => '0812345678']],
            promotionCode: $code,
            verifySeatLocks: false,
        );
    }

    private function save(User $user, string $code = 'GIVE100')
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/promotions/saved', ['code' => $code]);
    }

    public function test_only_as_many_customers_as_coupons_can_claim(): void
    {
        $promo = $this->promo(['claim_limit' => 2]);
        [$a, $b, $c] = User::factory()->count(3)->create()->all();

        $this->save($a)->assertCreated()->assertJsonPath('data.claims_left', 1)->assertJsonPath('data.is_claimed', true);
        $this->save($b)->assertCreated()->assertJsonPath('data.claims_left', 0);
        $this->save($c)->assertStatus(409)->assertJsonPath('message', 'คูปองนี้ถูกเก็บครบจำนวนแล้ว');

        // เก็บซ้ำไม่กินโควตาเพิ่ม
        $this->save($a)->assertCreated();
        $this->assertSame(2, $promo->fresh()->claimed_count);
        $this->assertSame(0, SavedPromotion::where('user_id', $c->id)->count());
    }

    public function test_a_fully_claimed_coupon_is_hidden_from_everyone_but_its_holders(): void
    {
        $promo = $this->promo(['claim_limit' => 1]);
        $holder = User::factory()->create();
        $other = User::factory()->create();
        app(PromotionService::class)->claim($promo, $holder->id);

        $this->assertEmpty($this->getJson('/api/v1/promotions/active')->json('data'));
        $this->assertEmpty($this->actingAs($other, 'sanctum')->getJson('/api/v1/promotions/active')->json('data'));

        $mine = $this->actingAs($holder, 'sanctum')->getJson('/api/v1/promotions/active')->json('data');
        $this->assertSame('GIVE100', $mine[0]['code']);
        $this->assertTrue($mine[0]['is_claimed']);
        $this->assertSame(0, $mine[0]['claims_left']);
    }

    public function test_holders_can_still_book_after_the_coupons_run_out(): void
    {
        $this->promo(['claim_limit' => 1]);
        $holder = User::factory()->create();
        $this->save($holder)->assertCreated();

        $booking = $this->book($holder, $this->schedule());

        $this->assertEquals(100, $booking->discount_amount);
    }

    public function test_typing_the_code_without_claiming_takes_a_coupon_if_any_left(): void
    {
        $promo = $this->promo(['claim_limit' => 1]);
        $schedule = $this->schedule();
        $first = User::factory()->create();

        $this->book($first, $schedule);
        $this->assertSame(1, $promo->fresh()->claimed_count);
        $this->assertNotNull(SavedPromotion::where('user_id', $first->id)->value('claimed_at'));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/promotions/validate', ['code' => 'GIVE100', 'trip_id' => $schedule->trip_id])
            ->assertStatus(400)
            ->assertJsonPath('message', 'คูปองนี้ถูกเก็บครบจำนวนแล้ว');

        $this->expectExceptionMessage('คูปองนี้ถูกเก็บครบจำนวนแล้ว');
        $this->book(User::factory()->create(), $schedule);
    }

    public function test_per_user_limit_blocks_a_second_booking_and_hides_the_used_coupon(): void
    {
        $this->promo(['per_user_limit' => 1]);
        $schedule = $this->schedule();
        $user = User::factory()->create();
        $this->save($user)->assertCreated();

        $this->book($user, $schedule);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/promotions/validate', ['code' => 'GIVE100', 'trip_id' => $schedule->trip_id])
            ->assertStatus(400)
            ->assertJsonPath('message', 'คุณใช้โค้ดส่วนลดนี้ครบสิทธิ์แล้ว');
        $this->assertEmpty($this->getJson('/api/v1/promotions/saved')->json('data'));

        $this->expectExceptionMessage('คุณใช้โค้ดส่วนลดนี้ครบสิทธิ์แล้ว');
        $this->book($user, $schedule);
    }

    public function test_a_cancelled_booking_gives_the_coupon_back(): void
    {
        $promo = $this->promo(['claim_limit' => 1, 'per_user_limit' => 1, 'max_uses' => 1]);
        $schedule = $this->schedule();
        $user = User::factory()->create();

        $booking = $this->book($user, $schedule);
        $this->assertSame(1, $promo->fresh()->used_count);

        $booking->update(['status' => 'cancelled']);
        $this->assertSame(0, $promo->fresh()->used_count);
        // ยกเลิก → คืนเงิน ไม่คืนซ้ำ
        $booking->update(['status' => 'refunded']);
        $this->assertSame(0, $promo->fresh()->used_count);

        // ใบยังอยู่กับลูกค้า ใช้ใหม่ได้ ไม่กินโควตาเพิ่ม
        $again = $this->book($user, $schedule);
        $this->assertEquals(100, $again->discount_amount);
        $this->assertSame(1, $promo->fresh()->claimed_count);
    }

    public function test_removing_an_unused_coupon_returns_it_to_the_pool(): void
    {
        $promo = $this->promo(['claim_limit' => 1]);
        $user = User::factory()->create();
        $this->save($user)->assertCreated();

        $this->deleteJson('/api/v1/promotions/saved/GIVE100')->assertOk();

        $this->assertSame(0, $promo->fresh()->claimed_count);
        $this->save(User::factory()->create())->assertCreated();
    }

    public function test_removing_a_used_coupon_keeps_it_counted(): void
    {
        $promo = $this->promo(['claim_limit' => 1]);
        $user = User::factory()->create();
        $this->save($user)->assertCreated();
        $this->book($user, $this->schedule());

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/promotions/saved/GIVE100')->assertOk();

        $this->assertSame(1, $promo->fresh()->claimed_count);
    }

    public function test_booking_rejects_a_lapsed_flash_sale(): void
    {
        $this->promo(['is_flash_sale' => true, 'ends_at' => now()->subMinute()]);

        $this->expectExceptionMessage('โค้ดส่วนลดนี้หมดอายุแล้ว');
        $this->book(User::factory()->create(), $this->schedule());
    }

    public function test_admin_cannot_shrink_the_pool_below_what_was_claimed(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $promo = $this->promo(['claim_limit' => 5]);
        $promo->forceFill(['claimed_count' => 3])->save();

        $payload = ['code' => 'GIVE100', 'name' => 'แจก', 'type' => 'fixed', 'value' => 100, 'claim_limit' => 2];
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/promotions/{$promo->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'ลูกค้าเก็บคูปองไปแล้ว 3 ใบ ตั้งจำนวนใบต่ำกว่านี้ไม่ได้');

        $payload['claim_limit'] = 3;
        $this->putJson("/api/v1/admin/promotions/{$promo->id}", $payload)->assertOk();

        // มีคนเก็บไว้แล้ว — ลบ = ปิดใช้งานแทน
        $this->deleteJson("/api/v1/admin/promotions/{$promo->id}")->assertJsonPath('deactivated', true);
        $this->assertFalse($promo->fresh()->is_active);
    }
}
