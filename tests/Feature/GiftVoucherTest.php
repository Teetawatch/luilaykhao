<?php

namespace Tests\Feature;

use App\Mail\GiftVoucherIssuedMail;
use App\Mail\PaymentConfirmedMail;
use App\Models\Booking;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherTransaction;
use App\Models\Promotion;
use App\Models\Receipt;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\GiftVoucherService;
use App\Services\ScheduleFinanceService;
use App\Services\SlipOcrService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GiftVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake(MediaDisk::slipDisk());
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('operator', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function fakeOcr(string $status, string $reason): void
    {
        $this->mock(SlipOcrService::class, function ($mock) use ($status, $reason) {
            $mock->shouldReceive('verify')->andReturn([
                'status' => $status,
                'reason' => $reason,
                'raw' => ['status' => 'success', 'amount' => 1000],
            ]);
        });
    }

    private function activeVoucher(array $overrides = []): GiftVoucher
    {
        $amount = $overrides['amount'] ?? 1000;

        $voucher = GiftVoucher::create(array_merge([
            'code' => GiftVoucher::generateCode(),
            'purchaser_user_id' => User::factory()->create()->id,
            'amount' => $amount,
            'balance' => $amount,
            'status' => GiftVoucher::STATUS_ACTIVE,
            'paid_at' => now(),
            'expires_at' => now()->addYear(),
        ], $overrides));

        GiftVoucherTransaction::create([
            'gift_voucher_id' => $voucher->id,
            'type' => GiftVoucherTransaction::TYPE_PURCHASE,
            'amount' => $amount,
            'balance_after' => $voucher->balance,
        ]);

        return $voucher;
    }

    private function makeSchedule(float $price = 1500, int $departInDays = 30): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปทดสอบบัตร',
            'slug' => 'voucher-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => $price,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($departInDays)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($departInDays)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
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

    // ── ซื้อ ─────────────────────────────────────────────────────────

    public function test_buying_creates_an_unpaid_voucher_with_a_promptpay_qr_and_no_code_yet(): void
    {
        $buyer = User::factory()->create();

        $response = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', [
            'amount' => 2000,
            'recipient_name' => 'น้องมายด์',
            'from_name' => 'พี่หมี',
            'message' => 'สุขสันต์วันเกิด',
            'design' => 'ocean',
        ])->assertCreated();

        $response->assertJsonPath('data.voucher.status', 'pending')
            ->assertJsonPath('data.voucher.code', null)
            ->assertJsonPath('data.voucher.can_pay', true)
            ->assertJsonPath('data.payment.amount', 2000);
        $this->assertNotEmpty($response->json('data.payment.qr_payload'));
        $this->assertStringStartsWith('data:image', $response->json('data.payment.qr_data_uri'));
        $this->assertSame(0.0, (float) GiftVoucher::first()->balance);
    }

    public function test_amount_must_be_within_the_configured_range(): void
    {
        $buyer = User::factory()->create();

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 100])->assertUnprocessable();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 999999])->assertUnprocessable();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 500.5])->assertUnprocessable();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 500, 'design' => 'pink'])->assertUnprocessable();
    }

    public function test_a_slip_the_ocr_verifies_activates_the_voucher_immediately(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_VERIFIED, 'auto_verified');
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 1000])->json('data.voucher.id');

        $response = $this->actingAs($buyer, 'sanctum')->post("/api/v1/gift-vouchers/{$id}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $response->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.balance', 1000);
        $this->assertNotNull($response->json('data.code'));
        $this->assertNotNull($response->json('data.share_url'));

        $voucher = GiftVoucher::find($id);
        $this->assertTrue($voucher->expires_at->between(now()->addDays(364), now()->addDays(366)));
        $this->assertSame(1, $voucher->transactions()->where('type', 'purchase')->count());
        Storage::disk(MediaDisk::slipDisk())->assertExists($voucher->slip_path);
        Mail::assertQueued(GiftVoucherIssuedMail::class, fn ($mail) => $mail->hasTo('buyer@example.com'));
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $buyer->id, 'type' => 'gift_voucher_active']);
    }

    public function test_an_unreadable_slip_waits_for_the_team_and_an_admin_can_approve_it(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_FAILED, 'ocr_failed');
        $buyer = User::factory()->create();
        $admin = $this->admin();
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 1000])->json('data.voucher.id');

        $this->actingAs($buyer, 'sanctum')->post("/api/v1/gift-vouchers/{$id}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.code', null);

        $this->assertDatabaseHas('smart_notifications', ['user_id' => $admin->id, 'type' => 'gift_voucher_slip_review']);
        Mail::assertNotQueued(GiftVoucherIssuedMail::class);

        // ส่งสลิปซ้ำระหว่างรอตรวจไม่ได้
        $this->actingAs($buyer, 'sanctum')->post("/api/v1/gift-vouchers/{$id}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip2.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        // ลูกค้าเรียก API หลังบ้านไม่ได้
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/admin/gift-vouchers/{$id}/approve")->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/gift-vouchers/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.balance', 1000);

        // อนุมัติซ้ำไม่เติมยอดซ้ำ
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/gift-vouchers/{$id}/approve")->assertUnprocessable();
        $this->assertSame(1, GiftVoucherTransaction::where('gift_voucher_id', $id)->where('type', 'purchase')->count());
        $this->assertSame(SlipOcrService::STATUS_APPROVED, GiftVoucher::find($id)->slip_ocr_status);
    }

    public function test_a_rejected_slip_tells_the_buyer_and_can_be_sent_again(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_FAILED, 'amount_mismatch');
        $buyer = User::factory()->create();
        $admin = $this->admin();
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 1000])->json('data.voucher.id');
        $this->actingAs($buyer, 'sanctum')->post("/api/v1/gift-vouchers/{$id}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/gift-vouchers/{$id}/reject")->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/gift-vouchers/{$id}/reject", ['note' => 'ยอดโอน 100 บาท'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/gift-vouchers/{$id}")
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_note', 'ยอดโอน 100 บาท')
            ->assertJsonPath('data.can_pay', true);
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $buyer->id, 'type' => 'gift_voucher_rejected']);

        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/gift-vouchers/{$id}/payment")->assertOk();
        $this->actingAs($buyer, 'sanctum')->post("/api/v1/gift-vouchers/{$id}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip2.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'under_review');
    }

    public function test_someone_else_cannot_see_or_pay_my_voucher(): void
    {
        $buyer = User::factory()->create();
        $stranger = User::factory()->create();
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 1000])->json('data.voucher.id');

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/gift-vouchers/{$id}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/gift-vouchers/{$id}/payment")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/v1/gift-vouchers/{$id}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->post("/api/v1/gift-vouchers/{$id}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_buyer_can_cancel_an_unpaid_voucher_but_not_one_under_review(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_FAILED, 'ocr_failed');
        $buyer = User::factory()->create();
        $unpaid = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 1000])->json('data.voucher.id');
        $sent = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 1000])->json('data.voucher.id');
        $this->actingAs($buyer, 'sanctum')->post("/api/v1/gift-vouchers/{$sent}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($buyer, 'sanctum')->deleteJson("/api/v1/gift-vouchers/{$unpaid}")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($buyer, 'sanctum')->deleteJson("/api/v1/gift-vouchers/{$sent}")->assertUnprocessable();

        // บัตรที่ยกเลิกแล้วไม่โผล่ในรายการ
        $purchased = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/gift-vouchers')->json('data.purchased');
        $this->assertSame([$sent], array_column($purchased, 'id'));
    }

    public function test_unpaid_vouchers_are_capped(): void
    {
        $buyer = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 500])->assertCreated();
        }

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 500])->assertUnprocessable();
    }

    public function test_index_separates_my_wallet_from_vouchers_i_bought_for_others(): void
    {
        $me = User::factory()->create();
        $mine = $this->activeVoucher(['purchaser_user_id' => $me->id, 'owner_user_id' => $me->id]);
        $forFriend = $this->activeVoucher(['purchaser_user_id' => $me->id]);
        $received = $this->activeVoucher(['owner_user_id' => $me->id]);

        $data = $this->actingAs($me, 'sanctum')->getJson('/api/v1/gift-vouchers')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$mine->id, $received->id], array_column($data['wallet'], 'id'));
        $this->assertSame([$forFriend->id], array_column($data['purchased'], 'id'));
        $this->assertSame(300, $data['config']['min_amount']);
    }

    // ── ผู้รับ ──────────────────────────────────────────────────────

    public function test_lookup_hides_unpaid_vouchers_and_other_peoples_balances(): void
    {
        $viewer = User::factory()->create();
        $unpaid = GiftVoucher::create([
            'code' => GiftVoucher::generateCode(), 'amount' => 1000, 'balance' => 0, 'status' => GiftVoucher::STATUS_PENDING,
        ]);
        $free = $this->activeVoucher(['from_name' => 'พี่หมี']);
        $taken = $this->activeVoucher(['owner_user_id' => User::factory()->create()->id]);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/gift-vouchers/lookup', ['code' => $unpaid->code])->assertNotFound();
        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/gift-vouchers/lookup', ['code' => 'GVXXXXXXXXXX'])->assertNotFound();

        // พิมพ์รหัสแบบมีขีด/ตัวเล็กก็ต้องเจอ
        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/gift-vouchers/lookup', ['code' => strtolower($free->displayCode())])
            ->assertOk()
            ->assertJsonPath('data.balance', 1000)
            ->assertJsonPath('data.from_name', 'พี่หมี')
            ->assertJsonPath('data.usable', true);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/gift-vouchers/lookup', ['code' => $taken->code])
            ->assertOk()
            ->assertJsonPath('data.balance', null)
            ->assertJsonPath('data.owned_by_other', true)
            ->assertJsonPath('data.usable', false);
    }

    public function test_claiming_binds_the_voucher_to_one_account(): void
    {
        $voucher = $this->activeVoucher();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first, 'sanctum')->postJson('/api/v1/gift-vouchers/claim', ['code' => $voucher->displayCode()])
            ->assertOk()
            ->assertJsonPath('data.is_owner', true);
        // ซ้ำโดยเจ้าของเดิมได้ ไม่ error
        $this->actingAs($first, 'sanctum')->postJson('/api/v1/gift-vouchers/claim', ['code' => $voucher->code])->assertOk();
        $this->actingAs($second, 'sanctum')->postJson('/api/v1/gift-vouchers/claim', ['code' => $voucher->code])->assertUnprocessable();

        $this->assertSame($first->id, $voucher->fresh()->owner_user_id);
        $this->assertNotNull($voucher->fresh()->claimed_at);
    }

    // ── ใช้ตอนจอง ──────────────────────────────────────────────────

    public function test_a_voucher_smaller_than_the_trip_leaves_the_rest_to_pay(): void
    {
        $user = User::factory()->create();
        $voucher = $this->activeVoucher(['amount' => 1000]);
        $schedule = $this->makeSchedule(1500);

        $response = $this->book($user, $schedule, ['gift_voucher_code' => $voucher->displayCode()])->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.voucher_amount', 1000)
            ->assertJsonPath('data.gift_voucher.amount', 1000);
        $this->assertEquals(500, $response->json('data.total_amount'));

        $voucher->refresh();
        $this->assertSame(0.0, (float) $voucher->balance);
        // ใช้ครั้งแรก = ผูกเข้าบัญชีผู้จอง
        $this->assertSame($user->id, $voucher->owner_user_id);
        $redeem = $voucher->transactions()->where('type', 'redeem')->first();
        $this->assertEquals(-1000, $redeem->amount);
        $this->assertSame(Booking::where('booking_ref', $response->json('data.booking_ref'))->value('id'), $redeem->booking_id);

        // ยอดที่ต้องโอนตอนจ่ายมาจาก total_amount ที่หักบัตรแล้ว
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/payments/'.$response->json('data.booking_ref').'/promptpay')
            ->assertOk()
            ->assertJsonPath('data.amount', 500);
    }

    public function test_a_voucher_that_covers_the_whole_trip_confirms_the_booking_at_once(): void
    {
        $user = User::factory()->create(['email' => 'traveller@example.com']);
        $voucher = $this->activeVoucher(['amount' => 5000, 'owner_user_id' => $user->id]);
        $schedule = $this->makeSchedule(1500);

        $response = $this->book($user, $schedule, ['gift_voucher_code' => $voucher->code])->assertCreated();

        $response->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_method', Booking::PAYMENT_METHOD_GIFT_VOUCHER);
        $this->assertEquals(0, $response->json('data.total_amount'));
        $this->assertEquals(0, $response->json('data.paid_amount'));
        $this->assertSame(3500.0, (float) $voucher->fresh()->balance);

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->first();
        $this->assertTrue($booking->isFullyPaid());
        Mail::assertQueued(PaymentConfirmedMail::class);
        // ไม่ใช่อีเมล "สร้างการจองแล้ว กรุณาชำระเงิน"
        $this->assertDatabaseMissing('smart_notifications', ['user_id' => $user->id, 'type' => 'booking_created']);

        $receipt = Receipt::where('booking_id', $booking->id)->first();
        $this->assertNotNull($receipt);
        $this->assertEquals(1500, $receipt->snapshot['summary']['total']);
        $this->assertEquals(1500, $receipt->snapshot['summary']['gift_voucher']);
        $this->assertEquals(1500, $receipt->snapshot['summary']['subtotal']);
        $this->assertEquals(0, $receipt->snapshot['summary']['balance']);
    }

    public function test_web_and_liff_can_send_the_voucher_in_the_promotion_code_field(): void
    {
        $user = User::factory()->create();
        $voucher = $this->activeVoucher(['amount' => 1000]);
        $schedule = $this->makeSchedule(1500);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/promotions/validate', ['code' => $voucher->displayCode(), 'trip_id' => $schedule->trip_id])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('kind', 'gift_voucher')
            ->assertJsonPath('promotion.type', 'fixed')
            ->assertJsonPath('promotion.value', 1000);

        $response = $this->book($user, $schedule, ['promotion_code' => $voucher->displayCode()])->assertCreated();

        $this->assertEquals(500, $response->json('data.total_amount'));
        $this->assertEquals(1000, $response->json('data.voucher_amount'));
        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->first();
        $this->assertNull($booking->promotion_code);
        $this->assertEquals(0, $booking->discount_amount);
    }

    public function test_a_promotion_and_a_voucher_work_together_discount_first(): void
    {
        $user = User::factory()->create();
        $voucher = $this->activeVoucher(['amount' => 1000]);
        $schedule = $this->makeSchedule(1500);
        Promotion::create([
            'code' => 'SAVE200', 'name' => 'ลด 200', 'type' => 'fixed', 'value' => 200, 'is_active' => true,
        ]);

        $response = $this->book($user, $schedule, [
            'promotion_code' => 'SAVE200',
            'gift_voucher_code' => $voucher->code,
        ])->assertCreated();

        $this->assertEquals(300, $response->json('data.total_amount'));
        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->first();
        $this->assertEquals(200, $booking->discount_amount);
        $this->assertEquals(1000, $booking->voucher_amount);
    }

    public function test_a_voucher_that_cannot_be_used_blocks_the_booking_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule(1500);
        $someoneElses = $this->activeVoucher(['owner_user_id' => User::factory()->create()->id]);
        $expired = $this->activeVoucher(['expires_at' => now()->subDay()]);
        $usedUp = $this->activeVoucher(['balance' => 0]);

        foreach ([$someoneElses, $expired, $usedUp] as $voucher) {
            $this->book($user, $schedule, ['gift_voucher_code' => $voucher->code])->assertUnprocessable();
        }
        $this->book($user, $schedule, ['gift_voucher_code' => 'GVNOTAREALONE'])->assertUnprocessable();

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, (int) $schedule->fresh()->booked_seats);
        $this->assertSame(1000.0, (float) $someoneElses->fresh()->balance);
        $this->assertSame(0, GiftVoucherTransaction::where('type', 'redeem')->count());
    }

    public function test_admin_skip_payment_cannot_spend_a_voucher(): void
    {
        $voucher = $this->activeVoucher();

        $this->book($this->admin(), $this->makeSchedule(), [
            'skip_payment' => true,
            'gift_voucher_code' => $voucher->code,
        ])->assertUnprocessable();

        $this->assertSame(1000.0, (float) $voucher->fresh()->balance);
    }

    // ── คืนยอด ─────────────────────────────────────────────────────

    public function test_an_unpaid_booking_that_expires_gives_the_voucher_back_in_full(): void
    {
        $user = User::factory()->create();
        $voucher = $this->activeVoucher(['amount' => 1000]);
        $ref = $this->book($user, $this->makeSchedule(1500), ['gift_voucher_code' => $voucher->code])->json('data.booking_ref');
        $this->assertSame(0.0, (float) $voucher->fresh()->balance);

        $this->travel(Booking::PENDING_TTL_MINUTES + 2)->minutes();
        app(BookingService::class)->expireStalePendingBookings();

        $booking = Booking::where('booking_ref', $ref)->first();
        $this->assertSame('cancelled', $booking->status);
        $this->assertEquals(1000, $booking->voucher_restored_amount);
        $this->assertSame(1000.0, (float) $voucher->fresh()->balance);
        $this->assertSame(1, $voucher->transactions()->where('type', 'restore')->count());
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $user->id, 'type' => 'gift_voucher_restored']);

        // ซ้ำไม่ได้ — คืนเกินยอดที่ใช้ไปไม่ได้
        app(GiftVoucherService::class)->restoreForBooking($booking, 1000, 'ลองคืนซ้ำ');
        $this->assertSame(1000.0, (float) $voucher->fresh()->balance);
    }

    public function test_cancelling_my_unpaid_booking_gives_the_voucher_back(): void
    {
        $user = User::factory()->create();
        $voucher = $this->activeVoucher(['amount' => 1000]);
        $ref = $this->book($user, $this->makeSchedule(1500), ['gift_voucher_code' => $voucher->code])->json('data.booking_ref');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$ref}/cancel", ['reason' => 'เปลี่ยนใจ'])->assertOk();

        $this->assertSame(1000.0, (float) $voucher->fresh()->balance);
    }

    public function test_refunding_a_confirmed_booking_restores_the_voucher_by_the_cancellation_policy(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();
        $voucher = $this->activeVoucher(['amount' => 5000, 'owner_user_id' => $user->id]);
        // 30 วันก่อนเดินทาง = นโยบายคืน 80%
        $ref = $this->book($user, $this->makeSchedule(1500, 30), ['gift_voucher_code' => $voucher->code])
            ->assertJsonPath('data.status', 'confirmed')
            ->json('data.booking_ref');
        $this->assertSame(3500.0, (float) $voucher->fresh()->balance);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/bookings/{$ref}/refund-preview")
            ->assertOk()
            ->assertJsonPath('data.voucher_amount', 1500)
            ->assertJsonPath('data.voucher_restore_percent', 80)
            ->assertJsonPath('data.voucher_restore_amount', 1200);

        // ทีมงานใส่เกินยอดที่ใช้ไปไม่ได้
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/bookings/{$ref}/refund", [
            'refund_amount' => 0,
            'voucher_restore_amount' => 2000,
        ])->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/bookings/{$ref}/refund", ['refund_amount' => 0])
            ->assertOk()
            ->assertJsonPath('data.voucher_restored_amount', 1200);

        $this->assertSame(4700.0, (float) $voucher->fresh()->balance);
        $restore = $voucher->transactions()->where('type', 'restore')->first();
        $this->assertSame($admin->id, $restore->actor_user_id);
    }

    public function test_the_team_can_choose_a_different_restore_amount(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();
        $voucher = $this->activeVoucher(['amount' => 1500, 'owner_user_id' => $user->id]);
        $ref = $this->book($user, $this->makeSchedule(1500, 2), ['gift_voucher_code' => $voucher->code])->json('data.booking_ref');

        // 2 วันก่อนเดินทาง นโยบายไม่คืน แต่ทีมงานเลือกคืนเต็มได้ (เช่น ลูกค้าป่วย)
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/bookings/{$ref}/refund-preview")
            ->assertJsonPath('data.voucher_restore_amount', 0);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/bookings/{$ref}/refund", [
            'refund_amount' => 0,
            'voucher_restore_amount' => 1500,
        ])->assertOk();

        $this->assertSame(1500.0, (float) $voucher->fresh()->balance);
    }

    public function test_restored_value_on_an_expiring_voucher_gets_time_to_be_used(): void
    {
        $user = User::factory()->create();
        $voucher = $this->activeVoucher(['amount' => 1000, 'expires_at' => now()->addDays(3)]);
        $ref = $this->book($user, $this->makeSchedule(1500), ['gift_voucher_code' => $voucher->code])->json('data.booking_ref');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$ref}/cancel", ['reason' => 'x'])->assertOk();

        $this->assertTrue($voucher->fresh()->expires_at->gte(now()->addDays(89)));
    }

    public function test_trip_finance_counts_the_voucher_paid_part_as_revenue(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule(1500);
        $voucher = $this->activeVoucher(['amount' => 5000, 'owner_user_id' => $user->id]);
        $this->book($user, $schedule, ['gift_voucher_code' => $voucher->code])->assertJsonPath('data.status', 'confirmed');

        $row = app(ScheduleFinanceService::class)->revenueRow($schedule->id);

        $this->assertEquals(1500, $row->paid);
        $this->assertEquals(1500, $row->booked);
    }

    // ── หลังบ้าน ────────────────────────────────────────────────────

    public function test_admin_can_issue_adjust_and_cancel_vouchers_with_a_full_ledger(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/gift-vouchers', [
            'amount' => 800,
            'recipient_name' => 'ลูกค้าทริปฝนตก',
            'note' => 'ชดเชยทริป LLK-20261001-0001',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_complimentary', true)
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/gift-vouchers/{$id}/adjust", ['amount' => 500, 'note' => 'x'])
            ->assertUnprocessable(); // เกินมูลค่าหน้าบัตร
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/gift-vouchers/{$id}/adjust", ['amount' => -300, 'note' => 'ใช้หน้างาน'])
            ->assertOk()
            ->assertJsonPath('data.balance', 500);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/gift-vouchers/{$id}/cancel", ['note' => 'ลูกค้าขอเงินคืน'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.balance', 0);

        $ledger = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/gift-vouchers/{$id}")->assertOk()->json('data.transactions');
        $this->assertSame([-500.0, -300.0, 800.0], array_map('floatval', array_column($ledger, 'amount')));
        $this->assertSame([0.0, 500.0, 800.0], array_map('floatval', array_column($ledger, 'balance_after')));
    }

    public function test_admin_list_puts_slips_to_check_first_and_sums_only_real_sales(): void
    {
        $admin = $this->admin();
        $this->activeVoucher(['amount' => 1000]);
        $this->activeVoucher(['amount' => 500, 'is_complimentary' => true]);
        $review = GiftVoucher::create([
            'code' => GiftVoucher::generateCode(), 'amount' => 2000, 'balance' => 0,
            'status' => GiftVoucher::STATUS_UNDER_REVIEW, 'slip_path' => 'slips/vouchers/x.jpg',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/gift-vouchers')->assertOk();

        $response->assertJsonPath('data.0.id', $review->id)
            ->assertJsonPath('meta.summary.sold_total', 1000)
            ->assertJsonPath('meta.summary.complimentary_total', 500)
            ->assertJsonPath('meta.summary.outstanding_balance', 1500)
            ->assertJsonPath('meta.summary.under_review_count', 1);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/gift-vouchers?status=under_review')
            ->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/gift-vouchers?q='.substr($review->code, 2, 6))
            ->assertJsonPath('data.0.id', $review->id);
    }

    // ── หน้าเว็บสาธารณะ ─────────────────────────────────────────────

    public function test_public_voucher_page_shows_active_vouchers_only(): void
    {
        $voucher = $this->activeVoucher(['amount' => 2000, 'from_name' => 'พี่หมี', 'recipient_name' => 'น้องมายด์']);
        $unpaid = GiftVoucher::create([
            'code' => GiftVoucher::generateCode(), 'amount' => 1000, 'balance' => 0, 'status' => GiftVoucher::STATUS_PENDING,
        ]);

        $this->get('/voucher/'.$voucher->code)
            ->assertOk()
            ->assertSee('฿2,000')
            ->assertSee('พี่หมี')
            ->assertSee($voucher->displayCode())
            ->assertSee('luilaykhao://voucher/'.$voucher->code, false);

        $this->get('/voucher/'.$unpaid->code)->assertNotFound()->assertDontSee($unpaid->code);
        $this->get('/voucher/GVNOPE')->assertNotFound();
    }

    public function test_code_helpers(): void
    {
        $code = GiftVoucher::generateCode();

        $this->assertMatchesRegularExpression('/^GV[A-HJ-NP-Z2-9]{10}$/', $code);
        $this->assertTrue(GiftVoucher::looksLikeCode(strtolower(substr($code, 0, 7).'-'.substr($code, 7))));
        $this->assertFalse(GiftVoucher::looksLikeCode('SAVE200'));
        $this->assertSame('GVABCDEFGHJK', GiftVoucher::normalizeCode(' gv-abcde fghjk '));
    }

    public function test_voucher_notifications_do_not_crash_without_fcm(): void
    {
        $this->fakeOcr(SlipOcrService::STATUS_VERIFIED, 'auto_verified');
        $buyer = User::factory()->create();
        $id = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/gift-vouchers', ['amount' => 1000, 'for_self' => true])->json('data.voucher.id');

        $this->actingAs($buyer, 'sanctum')->post("/api/v1/gift-vouchers/{$id}/slip", [
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.for_self', true);

        $this->assertSame(1, SmartNotification::where('type', 'gift_voucher_active')->count());
        $wallet = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/gift-vouchers')->json('data');
        $this->assertSame([$id], array_column($wallet['wallet'], 'id'));
        $this->assertSame([], $wallet['purchased']);
    }

    public function test_admin_cannot_hard_delete_a_booking_still_holding_voucher_value(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $voucher = $this->activeVoucher(['amount' => 1000]);
        $ref = $this->book($user, $this->makeSchedule(1500), ['gift_voucher_code' => $voucher->code])->json('data.booking_ref');

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/bookings/{$ref}")->assertUnprocessable();
        $this->assertNotNull(Booking::where('booking_ref', $ref)->first());

        // ยกเลิก (คืนยอดเข้าบัตร) แล้วค่อยลบได้
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$ref}/cancel", ['reason' => 'x'])->assertOk();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/bookings/{$ref}")->assertOk();
        $this->assertSame(1000.0, (float) $voucher->fresh()->balance);
    }
}
