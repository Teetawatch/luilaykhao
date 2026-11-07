<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CoinTransaction;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\CoinService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Coin จากการรีวิว (ข้อความ ≥100 ตัวอักษร +4, รูป +3, วิดีโอ +3) และการใช้ Coin
 * ลดค่าทริปตอนจอง (ไม่เกิน 10% ของยอดหลังส่วนลด)
 */
class ReviewCoinTest extends TestCase
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

    // ── helpers ─────────────────────────────────────────────────────

    private function trip(float $price = 1500): Trip
    {
        return Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'coin-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => $price,
            'status' => 'active',
        ]);
    }

    /** ใบจองที่จบทริปแล้ว (รีวิวได้ตั้งแต่ 20:00 วันกลับ) */
    private function finishedBooking(User $owner): Booking
    {
        $schedule = TripSchedule::create([
            'trip_id' => $this->trip()->id,
            'departure_date' => '2026-05-07',
            'return_date' => '2026-05-08',
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
    }

    private function upcomingSchedule(float $price = 1500): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $this->trip($price)->id,
            'departure_date' => '2026-06-10',
            'return_date' => '2026-06-11',
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function longText(): string
    {
        return str_repeat('ทริปนี้ดีมาก ทีมงานดูแลทั่วถึง ', 5); // > 100 ตัวอักษร
    }

    private function photo(): string
    {
        return MediaDisk::url('reviews/'.uniqid().'.jpg');
    }

    private function video(): string
    {
        return MediaDisk::url('reviews/videos/'.uniqid().'.mp4');
    }

    private function review(User $user, Booking $booking, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/reviews', array_merge([
            'booking_id' => $booking->id,
            'rating' => 5,
        ], $extra));
    }

    private function giveCoins(User $user, int $coins): void
    {
        $user->forceFill(['coin_balance' => $coins])->save();
    }

    private function passengers(): array
    {
        return [[
            'title' => 'นาย',
            'name' => 'ผู้เดินทาง ทดสอบ',
            'nickname' => 'ทด',
            'id_card' => '1234567890121',
            'phone' => '0812345678',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0898765432',
        ]];
    }

    private function book(User $user, TripSchedule $schedule, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'schedule_id' => $schedule->id,
            'passengers' => $this->passengers(),
        ], $extra));
    }

    // ── ได้ Coin จากรีวิว ───────────────────────────────────────────

    public function test_a_long_review_with_photo_and_video_earns_the_full_ten_coins(): void
    {
        $user = User::factory()->create();
        $booking = $this->finishedBooking($user);

        $this->review($user, $booking, [
            'comment' => $this->longText(),
            'images' => [$this->photo()],
            'videos' => [$this->video()],
        ])->assertCreated()
            ->assertJsonPath('data.coins_earned', 10)
            ->assertJsonPath('data.coin_breakdown.text', 4)
            ->assertJsonPath('data.coin_breakdown.image', 3)
            ->assertJsonPath('data.coin_breakdown.video', 3)
            ->assertJsonPath('data.coin_balance', 10);

        $this->assertSame(10, (int) $user->fresh()->coin_balance);
        $this->assertDatabaseHas('coin_transactions', [
            'user_id' => $user->id,
            'type' => CoinTransaction::TYPE_REVIEW,
            'coins' => 10,
            'reference_type' => Booking::class,
            'reference_id' => $booking->id,
        ]);
    }

    public function test_each_part_earns_on_its_own(): void
    {
        $user = User::factory()->create();

        $this->review($user, $this->finishedBooking($user), ['comment' => $this->longText()])
            ->assertJsonPath('data.coins_earned', 4);

        $this->review($user, $this->finishedBooking($user), ['comment' => 'ดีมาก', 'images' => [$this->photo()]])
            ->assertJsonPath('data.coins_earned', 3);

        $this->assertSame(7, (int) $user->fresh()->coin_balance);
    }

    public function test_short_text_and_no_media_earns_nothing(): void
    {
        $user = User::factory()->create();

        $this->review($user, $this->finishedBooking($user), [
            // 99 ตัวอักษรพอดี — ยังไม่ถึง
            'comment' => str_repeat('ก', 99),
        ])->assertCreated()->assertJsonPath('data.coins_earned', 0);

        $this->assertSame(0, (int) $user->fresh()->coin_balance);
        $this->assertSame(0, CoinTransaction::count());
    }

    public function test_padding_with_spaces_does_not_count_toward_the_length(): void
    {
        $this->assertSame(3, CoinService::textLength("  ก   \n\n  ข  "));
        $this->assertSame(100, CoinService::textLength(str_repeat('ก', 100)));
    }

    public function test_media_not_uploaded_through_our_endpoint_does_not_count(): void
    {
        $user = User::factory()->create();

        $this->review($user, $this->finishedBooking($user), [
            'comment' => 'สวย',
            'images' => ['https://evil.example.com/a.jpg'],
            'videos' => ['x'],
        ])->assertCreated()->assertJsonPath('data.coins_earned', 0);
    }

    public function test_adding_a_photo_later_tops_up_only_the_missing_part(): void
    {
        $user = User::factory()->create();
        $booking = $this->finishedBooking($user);

        $id = $this->review($user, $booking, ['comment' => $this->longText()])
            ->assertJsonPath('data.coins_earned', 4)
            ->json('data.id');

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/reviews/{$id}", [
            'comment' => $this->longText(),
            'images' => [$this->photo()],
        ])->assertOk()->assertJsonPath('data.coins_earned', 3)->assertJsonPath('data.coins_awarded', 7);

        // แก้อีกรอบโดยไม่เพิ่มอะไร — ไม่ได้ซ้ำ
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/reviews/{$id}", ['rating' => 4])
            ->assertJsonPath('data.coins_earned', 0);

        $this->assertSame(7, (int) $user->fresh()->coin_balance);
    }

    public function test_removing_content_later_does_not_take_coins_back(): void
    {
        $user = User::factory()->create();
        $id = $this->review($user, $this->finishedBooking($user), [
            'comment' => $this->longText(),
            'images' => [$this->photo()],
        ])->json('data.id');

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/reviews/{$id}", [
            'comment' => 'สั้นลง',
            'images' => [],
        ])->assertOk();

        $this->assertSame(7, (int) $user->fresh()->coin_balance);
    }

    public function test_deleting_and_rewriting_a_review_does_not_earn_twice(): void
    {
        $user = User::factory()->create();
        $booking = $this->finishedBooking($user);
        $payload = ['comment' => $this->longText(), 'images' => [$this->photo()], 'videos' => [$this->video()]];

        $id = $this->review($user, $booking, $payload)->json('data.id');
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/reviews/{$id}")->assertOk();

        $this->review($user, $booking, $payload)->assertCreated()->assertJsonPath('data.coins_earned', 0);

        $this->assertSame(10, (int) $user->fresh()->coin_balance);
    }

    public function test_hiding_a_review_takes_the_coins_back_and_showing_it_gives_them_again(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $user = User::factory()->create();
        $id = $this->review($user, $this->finishedBooking($user), [
            'comment' => $this->longText(),
            'images' => [$this->photo()],
        ])->json('data.id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/reviews/{$id}/toggle-approval")->assertOk();
        $this->assertSame(0, (int) $user->fresh()->coin_balance);
        $this->assertSame(0, (int) Review::find($id)->coins_awarded);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/reviews/{$id}/toggle-approval")->assertOk();
        $this->assertSame(7, (int) $user->fresh()->coin_balance);
    }

    public function test_admin_deleting_a_review_revokes_only_what_is_left(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $user = User::factory()->create();
        $id = $this->review($user, $this->finishedBooking($user), [
            'comment' => $this->longText(),
            'images' => [$this->photo()],
        ])->json('data.id');

        // ลูกค้าใช้ไปแล้ว 5 — ถอนได้แค่ 2 ที่เหลือ ยอดไม่ติดลบ
        $user->forceFill(['coin_balance' => 2])->save();

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/reviews/{$id}")->assertOk();

        $this->assertSame(0, (int) $user->fresh()->coin_balance);
        $this->assertDatabaseHas('coin_transactions', ['type' => CoinTransaction::TYPE_REVIEW_REVOKE, 'coins' => -2]);
    }

    public function test_a_review_written_by_an_admin_on_behalf_of_a_customer_earns_nothing(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $booking = $this->finishedBooking($admin);
        $booking->passengers()->create(['name' => 'สมหญิง ใจดี']);

        $this->review($admin, $booking, ['comment' => $this->longText(), 'images' => [$this->photo()]])
            ->assertCreated()
            ->assertJsonPath('data.coins_earned', 0);

        $this->assertSame(0, (int) $admin->fresh()->coin_balance);
    }

    // ── ปุ่มคำรีวิว ────────────────────────────────────────────────

    public function test_compose_options_list_the_tags_and_coin_rules(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/reviews/compose-options')
            ->assertOk()
            ->assertJsonPath('data.coins.min_chars', 100)
            ->assertJsonPath('data.coins.max', 10)
            ->assertJsonPath('data.coins.spend_max_percent', 10)
            ->assertJsonPath('data.tags.positive.0', 'ทีมงานดูแลดีมาก');
    }

    public function test_tags_are_saved_shown_and_do_not_count_toward_the_text_reward(): void
    {
        $user = User::factory()->create();
        $tags = ['ทีมงานดูแลดีมาก', 'วิวสวยเกินคาด', 'อาหารอร่อย', 'คุ้มค่ามาก', 'อยากไปอีก', 'ตรงเวลา', 'จัดทริปเป็นระบบ', 'ได้เพื่อนใหม่'];

        $this->review($user, $this->finishedBooking($user), ['comment' => 'ดีมาก', 'tags' => $tags])
            ->assertCreated()
            ->assertJsonPath('data.tags', $tags)
            ->assertJsonPath('data.coins_earned', 0);

        $this->getJson('/api/v1/reviews')->assertJsonPath('data.0.tags', $tags);
    }

    public function test_unknown_tags_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->review($user, $this->finishedBooking($user), ['tags' => ['รีวิวปลอม 5 ดาว']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tags.0');
    }

    // ── ใช้ Coin ลดค่าทริป ──────────────────────────────────────────

    public function test_coins_lower_the_price_up_to_ten_percent(): void
    {
        $user = User::factory()->create();
        $this->giveCoins($user, 500);

        $res = $this->book($user, $this->upcomingSchedule(1500), ['coins_to_use' => 500])->assertCreated();

        // เพดาน 10% ของ 1,500 = 150
        $this->assertSame(150, $res->json('data.coins_used'));
        $booking = Booking::where('booking_ref', $res->json('data.booking_ref'))->first();
        $this->assertSame(1350.0, (float) $booking->total_amount);
        $this->assertSame(150.0, (float) $booking->discount_amount);
        $this->assertSame(350, (int) $user->fresh()->coin_balance);
        $this->assertDatabaseHas('coin_transactions', [
            'user_id' => $user->id,
            'type' => CoinTransaction::TYPE_SPEND,
            'coins' => -150,
            'balance_after' => 350,
        ]);
    }

    public function test_asking_for_more_coins_than_owned_uses_what_is_there(): void
    {
        $user = User::factory()->create();
        $this->giveCoins($user, 40);

        $res = $this->book($user, $this->upcomingSchedule(1500), ['coins_to_use' => 150])->assertCreated();

        $this->assertSame(40, $res->json('data.coins_used'));
        $this->assertSame(0, (int) $user->fresh()->coin_balance);
    }

    public function test_cancelling_a_booking_gives_the_coins_back_once(): void
    {
        $user = User::factory()->create();
        $this->giveCoins($user, 100);
        $ref = $this->book($user, $this->upcomingSchedule(1500), ['coins_to_use' => 100])->json('data.booking_ref');
        $this->assertSame(0, (int) $user->fresh()->coin_balance);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$ref}/cancel", ['reason' => 'เปลี่ยนใจ'])->assertOk();
        $this->assertSame(100, (int) $user->fresh()->coin_balance);

        // เรียกซ้ำไม่คืนเกิน
        app(CoinService::class)->restoreForBooking(Booking::where('booking_ref', $ref)->first());
        $this->assertSame(100, (int) $user->fresh()->coin_balance);
    }

    public function test_admin_skip_payment_bookings_do_not_spend_coins(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->giveCoins($admin, 100);

        $res = $this->book($admin, $this->upcomingSchedule(1500), ['coins_to_use' => 100, 'skip_payment' => true])
            ->assertCreated();

        $this->assertSame(0, $res->json('data.coins_used'));
        $this->assertSame(100, (int) $admin->fresh()->coin_balance);
    }

    public function test_wallet_shows_balance_rules_and_history(): void
    {
        $user = User::factory()->create();
        $this->review($user, $this->finishedBooking($user), ['comment' => $this->longText()]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/coins')
            ->assertOk()
            ->assertJsonPath('data.balance', 4)
            ->assertJsonPath('data.rules.text', 4)
            ->assertJsonPath('data.transactions.0.type', 'review')
            ->assertJsonPath('data.transactions.0.coins', 4);
    }
}
