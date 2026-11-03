<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\PromotionController;
use App\Models\Promotion;
use App\Models\SavedPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * คูปองที่ลูกค้ากด "เก็บ" — ผูกกับบัญชี ส่งกลับเฉพาะโค้ดที่ยังใช้ได้ ใหม่สุดก่อน
 */
class SavedPromotionsTest extends TestCase
{
    use RefreshDatabase;

    private function promo(string $code, array $extra = []): Promotion
    {
        return Promotion::create(array_merge([
            'code' => $code,
            'name' => 'Promo '.$code,
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ], $extra));
    }

    private function codes(User $user): array
    {
        return collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/promotions/saved')->assertOk()->json('data')
        )->pluck('code')->all();
    }

    public function test_guests_cannot_use_saved_coupons(): void
    {
        $this->getJson('/api/v1/promotions/saved')->assertUnauthorized();
        $this->postJson('/api/v1/promotions/saved', ['code' => 'X'])->assertUnauthorized();
        $this->deleteJson('/api/v1/promotions/saved/X')->assertUnauthorized();
    }

    public function test_save_lists_newest_first_and_resaving_moves_to_top(): void
    {
        $user = User::factory()->create();
        $this->promo('FIRST');
        $this->promo('SECOND', ['trip_ids' => [7]]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/promotions/saved', ['code' => 'FIRST'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'FIRST')
            ->assertJsonPath('data.trip_ids', null);
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/promotions/saved', ['code' => 'SECOND'])
            ->assertCreated()
            ->assertJsonPath('data.trip_ids', [7]);

        $this->assertSame(['SECOND', 'FIRST'], $this->codes($user));

        $this->travel(1)->minutes();
        $this->postJson('/api/v1/promotions/saved', ['code' => 'FIRST'])->assertCreated();

        $this->assertSame(['FIRST', 'SECOND'], $this->codes($user));
        $this->assertSame(2, SavedPromotion::count()); // เก็บซ้ำไม่เพิ่มแถว
    }

    public function test_unknown_inactive_or_expired_codes_cannot_be_saved(): void
    {
        $user = User::factory()->create();
        $this->promo('OFF', ['is_active' => false]);
        $this->promo('OLD', ['end_date' => now()->subDay()->toDateString()]);
        $this->promo('USEDUP', ['max_uses' => 5, 'used_count' => 5]);
        $this->promo('LAPSED', ['is_flash_sale' => true, 'ends_at' => now()->subMinute()]);

        foreach (['NOPE', 'OFF', 'OLD', 'USEDUP', 'LAPSED'] as $code) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/promotions/saved', ['code' => $code])
                ->assertNotFound()
                ->assertJsonPath('message', 'ไม่พบโค้ดนี้ หรือโค้ดหมดอายุแล้ว');
        }
        $this->assertSame(0, SavedPromotion::count());
    }

    public function test_a_coupon_that_expires_after_saving_drops_out_of_the_list(): void
    {
        $user = User::factory()->create();
        $promo = $this->promo('WINTER');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/promotions/saved', ['code' => 'WINTER'])->assertCreated();

        $promo->update(['is_active' => false]);
        $this->assertSame([], $this->codes($user));

        // แอดมินเปิดกลับ — กลับมาในรายการเอง
        $promo->update(['is_active' => true]);
        $this->assertSame(['WINTER'], $this->codes($user));
    }

    public function test_unsave_removes_only_my_row(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->promo('SHARED');
        $this->actingAs($me, 'sanctum')->postJson('/api/v1/promotions/saved', ['code' => 'SHARED'])->assertCreated();
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/promotions/saved', ['code' => 'SHARED'])->assertCreated();

        $this->actingAs($me, 'sanctum')->deleteJson('/api/v1/promotions/saved/SHARED')->assertOk();
        // ลบซ้ำ / ลบโค้ดที่ไม่มี ก็ไม่พัง
        $this->deleteJson('/api/v1/promotions/saved/SHARED')->assertOk();
        $this->deleteJson('/api/v1/promotions/saved/NOPE')->assertOk();

        $this->assertSame([], $this->codes($me));
        $this->assertSame(['SHARED'], $this->codes($other));
    }

    public function test_bulk_save_moves_guest_coupons_in_keeping_their_order(): void
    {
        $user = User::factory()->create();
        $this->promo('NEWEST');
        $this->promo('MIDDLE');
        $this->promo('OLDEST');
        $this->promo('GONE', ['is_active' => false]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/promotions/saved', [
            'codes' => ['NEWEST', 'GONE', 'NOPE', 'MIDDLE', 'OLDEST'],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.code', 'NEWEST');

        $this->assertSame(['NEWEST', 'MIDDLE', 'OLDEST'], $this->codes($user));
        // โค้ดที่ใช้ไม่ได้ถูกข้าม ไม่มีแถวค้าง
        $this->assertSame(3, SavedPromotion::count());
    }

    public function test_saving_many_coupons_in_a_row_is_not_throttled_like_code_validation(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 15; $i++) {
            $this->promo('R'.$i);
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/promotions/saved', ['code' => 'R'.$i])
                ->assertCreated();
        }
    }

    public function test_each_account_keeps_at_most_the_limit_dropping_the_oldest(): void
    {
        $user = User::factory()->create();
        $limit = PromotionController::SAVED_LIMIT;
        for ($i = 0; $i <= $limit; $i++) {
            $this->promo('C'.$i);
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/promotions/saved', ['code' => 'C'.$i])->assertCreated();
            $this->travel(1)->seconds();
        }

        $codes = $this->codes($user);
        $this->assertCount($limit, $codes);
        $this->assertSame('C'.$limit, $codes[0]);
        $this->assertNotContains('C0', $codes);
    }
}
