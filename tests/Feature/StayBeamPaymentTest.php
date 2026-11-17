<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\SmartNotification;
use App\Models\Stay;
use App\Models\StayBooking;
use App\Models\User;
use App\Services\StayBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ค่าที่พักจ่ายผ่าน Beam — ออก QR จากยอดหลังบ้าน แล้วให้ webhook เป็นคนยืนยันใบจอง
 * เหมือนทริปทุกอย่าง ต่างกันแค่ปลายทางคือ stay_bookings
 */
class StayBeamPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'c2VjcmV0LWtleS1mb3ItYmVhbS10ZXN0cw==';

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('operator', 'web');

        config([
            'payment.provider' => 'beam',
            'payment.beam.merchant_id' => 'merchant_test',
            'payment.beam.api_key' => 'key_test',
            'payment.beam.webhook_secret' => self::SECRET,
            'payment.beam.base_url' => 'https://playground.api.beamcheckout.com',
            'payment.beam.qr_ttl_minutes' => 15,
        ]);
    }

    private function fakeBeamOk(string $chargeId = 'ch_stay_1'): void
    {
        Http::fake([
            '*/api/v1/charges' => Http::response([
                'chargeId' => $chargeId,
                'actionRequired' => 'ENCODED_IMAGE',
                'paymentMethodType' => 'QR_PROMPT_PAY',
                'encodedImage' => [
                    'imageBase64Encoded' => 'iVBORw0KGgo=',
                    'rawData' => '00020101021229',
                    'expiry' => now()->addMinutes(10)->toIso8601ZuluString(),
                ],
            ], 200),
        ]);
    }

    private function awaitingBooking(array $overrides = []): StayBooking
    {
        $user = User::factory()->create();

        $stay = Stay::create([
            'name' => 'บ้านดินริมดอย',
            'slug' => 'baan-din-'.uniqid(),
            'type' => 'homestay',
            'province' => 'เชียงใหม่',
            'booking_mode' => Stay::MODE_INSTANT,
            'cancellation_policy' => 'moderate',
            'commission_percent' => 10,
            'status' => Stay::STATUS_ACTIVE,
            'host_token' => Stay::newHostToken(),
        ]);

        $room = $stay->roomTypes()->create([
            'name' => 'ห้องคู่', 'units' => 2, 'base_guests' => 2, 'max_guests' => 3,
            'price_per_night' => 1000,
        ]);

        $in = now('Asia/Bangkok')->addWeeks(4)->startOfWeek(Carbon::MONDAY);

        return StayBooking::create(array_merge([
            'ref' => StayBooking::generateRef(),
            'user_id' => $user->id,
            'stay_id' => $stay->id,
            'room_type_id' => $room->id,
            'room_name' => $room->name,
            'check_in' => $in->toDateString(),
            'check_out' => $in->copy()->addDays(2)->toDateString(),
            'nights' => 2,
            'units' => 1,
            'guests' => 2,
            'guest_name' => 'สมหญิง ใจดี',
            'guest_phone' => '0899999999',
            'status' => StayBooking::STATUS_AWAITING_PAYMENT,
            'booking_mode' => Stay::MODE_INSTANT,
            'room_amount' => 2000,
            'total_amount' => 2000,
            'commission_percent' => 10,
            'commission_amount' => 200,
            'host_amount' => 1800,
            'cancellation_policy' => 'moderate',
            'payment_due_at' => now()->addMinutes(30),
        ], $overrides));
    }

    private function charge(StayBooking $booking, array $body = []): TestResponse
    {
        return $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/stay-bookings/{$booking->ref}/beam", $body);
    }

    private function postWebhook(array $payload, string $event = 'charge.succeeded'): TestResponse
    {
        $body = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $body, base64_decode(self::SECRET), true));

        return $this->call('POST', '/api/v1/payments/beam/webhook', [], [], [], [
            'HTTP_X-Beam-Signature' => $signature,
            'HTTP_X-Beam-Event' => $event,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_the_stay_ref_uses_the_llk_stay_prefix(): void
    {
        $this->assertMatchesRegularExpression('/^LLK-STAY-\d{6}-[A-Z0-9]{4}$/', StayBooking::generateRef());
        $this->assertLessThanOrEqual(20, strlen(StayBooking::generateRef()));
    }

    public function test_payment_info_tells_the_client_beam_is_available(): void
    {
        $booking = $this->awaitingBooking();

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/stay-bookings/{$booking->ref}/payment")
            ->assertOk()
            ->assertJsonPath('data.payment_gateway.provider', 'beam');
    }

    public function test_a_qr_is_issued_for_the_full_stay_amount_without_confirming(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking();

        $this->charge($booking)
            ->assertCreated()
            ->assertJsonPath('data.purpose', Payment::PURPOSE_STAY)
            ->assertJsonPath('data.amount', 2000)
            ->assertJsonPath('data.qr_image_base64', 'iVBORw0KGgo=');

        $payment = Payment::sole();
        $this->assertSame($booking->id, $payment->stay_booking_id);
        $this->assertNull($payment->booking_id);
        $this->assertSame($booking->ref.'-'.$payment->id, $payment->reference_id);
        $this->assertSame(StayBooking::STATUS_AWAITING_PAYMENT, $booking->fresh()->status);

        Http::assertSent(fn ($request) => $request['amount'] === 200000
            && $request['referenceId'] === $payment->reference_id);
    }

    public function test_the_qr_never_outlives_the_room_hold(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking(['payment_due_at' => now()->addMinutes(5)]);

        $this->charge($booking)->assertCreated();

        $this->assertTrue(Payment::sole()->expires_at->lte(now()->addMinutes(4)->addSecond()));
    }

    public function test_pressing_again_returns_the_same_live_qr(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking();

        $first = $this->charge($booking)->json('data.payment_id');
        $second = $this->charge($booking)->json('data.payment_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::count());
    }

    public function test_only_the_owner_can_issue_or_watch_a_charge(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/stay-bookings/{$booking->ref}/beam")
            ->assertNotFound();

        $paymentId = $this->charge($booking)->json('data.payment_id');

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/payments/beam/{$paymentId}")->assertForbidden();
        $this->actingAs($booking->user, 'sanctum')->getJson("/api/v1/payments/beam/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING);
    }

    public function test_a_booking_that_is_not_awaiting_payment_cannot_get_a_qr(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking(['status' => StayBooking::STATUS_REQUESTED, 'payment_due_at' => null]);

        $this->charge($booking)->assertStatus(422);
        $this->assertSame(0, Payment::count());
    }

    public function test_the_endpoint_says_so_while_the_provider_is_manual(): void
    {
        config(['payment.provider' => 'manual']);
        $booking = $this->awaitingBooking();

        $this->charge($booking)->assertStatus(503);
    }

    public function test_the_webhook_confirms_the_stay_booking(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking();
        $this->charge($booking)->assertCreated();
        $payment = Payment::sole();

        $this->postWebhook(['referenceId' => $payment->reference_id, 'chargeId' => 'ch_stay_1', 'status' => 'SUCCEEDED'])
            ->assertOk();
        // Beam retry — ต้องไม่ยืนยันซ้ำหรือแจ้งเตือนซ้ำ
        $this->postWebhook(['referenceId' => $payment->reference_id, 'chargeId' => 'ch_stay_1', 'status' => 'SUCCEEDED'])
            ->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame(StayBooking::STATUS_CONFIRMED, $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertEquals(2000, (float) $fresh->paid_amount);
        $this->assertSame('beam_qr_prompt_pay', $fresh->payment_method);
        $this->assertSame($payment->reference_id, $fresh->payment_ref);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(1, SmartNotification::where('user_id', $booking->user_id)
            ->where('type', 'stay_booking_confirmed')->count());
    }

    public function test_money_arriving_after_the_booking_expired_is_flagged_for_refund(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking();
        $this->charge($booking)->assertCreated();
        $payment = Payment::sole();
        $booking->update(['status' => StayBooking::STATUS_EXPIRED]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->postWebhook(['referenceId' => $payment->reference_id, 'status' => 'SUCCEEDED'])->assertOk();

        $this->assertSame(StayBooking::STATUS_EXPIRED, $booking->fresh()->status);
        $this->assertSame('stay_not_payable', $payment->fresh()->failure_code);
        $this->assertTrue(SmartNotification::where('user_id', $admin->id)->where('type', 'payment_needs_refund')->exists());
    }

    public function test_paying_twice_flags_the_second_payment_for_refund(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking();
        $this->charge($booking)->assertCreated();
        $payment = Payment::sole();

        // จ่ายด้วยการโอน+สลิปไปก่อนแล้ว
        app(StayBookingService::class)->confirmPayment($booking);

        $this->postWebhook(['referenceId' => $payment->reference_id, 'status' => 'SUCCEEDED'])->assertOk();

        $this->assertSame('stay_not_payable', $payment->fresh()->failure_code);
        $this->assertSame('promptpay', $booking->fresh()->payment_method);
    }

    public function test_a_live_qr_keeps_the_room_past_the_payment_deadline(): void
    {
        $booking = $this->awaitingBooking(['payment_due_at' => now()->subMinute()]);
        Payment::create([
            'stay_booking_id' => $booking->id,
            'purpose' => Payment::PURPOSE_STAY,
            'provider' => 'beam',
            'reference_id' => $booking->ref.'-1',
            'amount' => 2000,
            'status' => Payment::STATUS_PENDING,
            'payment_method_type' => 'QR_PROMPT_PAY',
            'expires_at' => now()->addMinutes(2),
        ]);

        $this->assertSame(0, app(StayBookingService::class)->expireUnpaid());
        $this->assertSame(StayBooking::STATUS_AWAITING_PAYMENT, $booking->fresh()->status);

        Payment::query()->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(StayBookingService::class)->expireUnpaid());
        $this->assertSame(StayBooking::STATUS_EXPIRED, $booking->fresh()->status);
    }

    public function test_the_return_page_points_back_to_the_stay_booking(): void
    {
        $this->fakeBeamOk();
        $booking = $this->awaitingBooking();
        $this->charge($booking)->assertCreated();
        $payment = Payment::sole();

        $this->get('/payment/return?payment='.$payment->id)
            ->assertOk()
            ->assertSee($booking->ref)
            ->assertSee('/stays/bookings/'.$booking->ref, false);
    }
}
