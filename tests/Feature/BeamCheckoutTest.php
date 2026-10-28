<?php

namespace Tests\Feature;

use App\Jobs\ReconcileBeamChargesJob;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BeamPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * หน้าชำระเงินของ Beam (Payment Link) — บัตร / Apple Pay / Google Pay / ผ่อนบัตร /
 * e-Wallet / โมบายแบงก์กิ้ง ในปุ่มเดียว
 *
 * ต่างจาก QR ตรงที่ตอนออกใบเรามีแค่ id ของลิงก์ charge จริงเกิดทีหลังบนหน้าของ Beam
 * (และเกิดได้หลายใบ) เทสต์ชุดนี้คุมว่าเงินที่เข้าทางนี้หาแถวของตัวเองเจอเสมอ และบัตร
 * ที่ถูกปฏิเสธหนึ่งใบไม่ทำให้หน้าจอเราประกาศว่า "จ่ายไม่สำเร็จ" ทั้งที่ลิงก์ยังจ่ายต่อได้
 */
class BeamCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'c2VjcmV0LWtleS1mb3ItYmVhbS10ZXN0cw==';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.provider' => 'beam',
            'payment.beam.merchant_id' => 'merchant_test',
            'payment.beam.api_key' => 'key_test',
            'payment.beam.base_url' => 'https://playground.api.beamcheckout.com',
            'payment.beam.webhook_secret' => self::SECRET,
            'payment.beam.return_url' => 'https://luilaykhao.com/payment/return',
        ]);
    }

    private function pendingBooking(float $total = 4000): Booking
    {
        $user = User::factory()->create();

        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว', 'slug' => 'checkout-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Chiang Mai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => $total, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => $total,
            'paid_amount' => 0,
            'payment_type' => 'full',
        ]);
    }

    private function fakeLinkCreated(string $linkId = 'pl_test_1'): void
    {
        Http::fake([
            '*/api/v1/payment-links' => Http::response([
                'id' => $linkId,
                'url' => 'https://playground-pay.beamcheckout.com/merchant_test/'.$linkId,
            ], 200),
        ]);
    }

    private function checkoutPayment(Booking $booking, string $linkId = 'pl_test_1'): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'purpose' => Payment::PURPOSE_FULL,
            'provider' => 'beam',
            'provider_link_id' => $linkId,
            'reference_id' => $booking->booking_ref.'-1',
            'amount' => $booking->total_amount,
            'currency' => 'THB',
            'status' => Payment::STATUS_PENDING,
            'payment_method_type' => Payment::METHOD_CHECKOUT,
            'user_id' => $booking->user_id,
            'expires_at' => now()->addMinutes(9),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, string $event): TestResponse
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

    public function test_clients_are_told_the_checkout_page_is_available(): void
    {
        $this->assertContains(Payment::METHOD_CHECKOUT, PaymentGateway::publicConfig()['methods']);
    }

    public function test_checkout_creates_a_payment_link_with_every_payment_group_open(): void
    {
        $this->fakeLinkCreated();
        $booking = $this->pendingBooking(4000.50);

        $response = $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
                'payment_method_type' => 'CHECKOUT',
            ])
            ->assertStatus(201);

        // client ใช้ทางเดียวกับแอปธนาคาร: REDIRECT + redirect_url
        $response->assertJsonPath('data.action_required', 'REDIRECT');
        $response->assertJsonPath('data.redirect_url', 'https://playground-pay.beamcheckout.com/merchant_test/pl_test_1');
        $response->assertJsonPath('data.payment_method_type', 'CHECKOUT');

        $payment = Payment::firstOrFail();
        $this->assertSame('pl_test_1', $payment->provider_link_id);
        $this->assertNull($payment->provider_charge_id);
        $this->assertSame('pending', $booking->fresh()->status);

        Http::assertSent(function (Request $request) use ($payment) {
            if (! str_ends_with($request->url(), '/api/v1/payment-links')) {
                return false;
            }

            $settings = $request['linkSettings'];

            return $request['order']['netAmount'] === 400050
                && $request['order']['orderItems'][0]['price'] === 400050
                && $request['order']['orderItems'][0]['quantity'] === 1
                && $request['order']['referenceId'] === $payment->reference_id
                && $request['order']['currency'] === 'THB'
                && $settings['card']['isEnabled'] === true
                && $settings['eWallets']['isEnabled'] === true
                && $settings['mobileBanking']['isEnabled'] === true
                && $settings['qrPromptPay']['isEnabled'] === true
                && $settings['cardInstallments']['isEnabled'] === true
                && $settings['cardInstallments']['installments3m']['isEnabled'] === true
                && $settings['cardInstallments']['installments10m']['isEnabled'] === true
                && $request['redirectUrl'] === 'https://luilaykhao.com/payment/return?payment='.$payment->id
                && $request['cancelUrl'] === 'https://luilaykhao.com/payment/return?payment='.$payment->id.'&cancelled=1'
                && isset($request['expiresAt']);
        });
    }

    /**
     * แอปออก QR ใหม่ทุกครั้งที่ลูกค้าสลับเต็ม/มัดจำ/ผ่อน — เคยใช้ throttle 'payment'
     * (5/นาที ร่วมกับการอัปสลิป) สลับไม่กี่ทีแล้วกดจ่ายด้วยบัตรก็โดน "Too Many Attempts"
     */
    public function test_switching_payment_options_a_few_times_does_not_lock_out_checkout(): void
    {
        Http::fake([
            '*/api/v1/charges' => Http::response([
                'chargeId' => 'ch_qr',
                'actionRequired' => 'ENCODED_IMAGE',
                'encodedImage' => ['imageBase64Encoded' => 'iVBORw0KGgo=', 'expiry' => now()->addMinutes(10)->toIso8601ZuluString()],
            ], 200),
            '*/api/v1/payment-links' => Http::response(['id' => 'pl_test_1', 'url' => 'https://pay.example/pl_test_1'], 200),
        ]);
        $booking = $this->pendingBooking();

        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($booking->user)
                ->postJson('/api/v1/payments/beam/charge', [
                    'booking_ref' => $booking->booking_ref,
                    'purpose' => 'full',
                    'payment_method_type' => 'QR_PROMPT_PAY',
                ])
                ->assertStatus(201);
        }

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
                'payment_method_type' => 'CHECKOUT',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.redirect_url', 'https://pay.example/pl_test_1');
    }

    /**
     * Beam ปฏิเสธทั้งลิงก์ถ้าเปิดงวดผ่อนให้ยอดที่ต่ำกว่าขั้นต่ำใน Lighthouse — บัตรและ
     * e-Wallet หายไปด้วย (เกิดจริงกับยอด 1,290 บาท)
     */
    public function test_instalments_are_left_off_below_the_minimum_but_cards_and_wallets_stay(): void
    {
        $this->fakeLinkCreated();
        $booking = $this->pendingBooking(1290);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
                'payment_method_type' => 'CHECKOUT',
            ])
            ->assertStatus(201);

        Http::assertSent(function (Request $request) {
            $settings = $request['linkSettings'];

            return $settings['cardInstallments']['isEnabled'] === false
                && $settings['cardInstallments']['installments3m']['isEnabled'] === false
                && $settings['cardInstallments']['installments10m']['isEnabled'] === false
                && $settings['card']['isEnabled'] === true
                && $settings['eWallets']['isEnabled'] === true;
        });
    }

    public function test_a_higher_minimum_from_beam_is_learned_and_the_link_still_opens(): void
    {
        Http::fake([
            '*/api/v1/payment-links' => Http::sequence()
                ->push([
                    'code' => 400,
                    'message' => 'inputs are failing validation; card installments (period=10) minimum threshold in payment settings is 10000.00, but amount is 4000.00',
                    'error' => [
                        'errorCode' => 'API_VALIDATION_ERROR',
                        'errorMessage' => 'inputs are failing validation; card installments (period=10) minimum threshold in payment settings is 10000.00, but amount is 4000.00',
                    ],
                ], 400)
                ->push(['id' => 'pl_test_1', 'url' => 'https://pay.example/pl_test_1'], 200),
        ]);
        $booking = $this->pendingBooking(4000);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
                'payment_method_type' => 'CHECKOUT',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.redirect_url', 'https://pay.example/pl_test_1');

        // ใบที่สองปิดเฉพาะ 10 เดือน งวดอื่นยังเปิด
        $sent = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(2, $sent);
        $this->assertTrue($sent[1]['linkSettings']['cardInstallments']['installments3m']['isEnabled']);
        $this->assertFalse($sent[1]['linkSettings']['cardInstallments']['installments10m']['isEnabled']);

        // จำไว้แล้ว — ครั้งหน้าไม่ต้องโดนปฏิเสธก่อน
        $this->assertSame(10000.0, PaymentGateway::installmentMinimum(10));
    }

    public function test_an_unrelated_beam_rejection_is_not_retried(): void
    {
        Http::fake([
            '*/api/v1/payment-links' => Http::response([
                'code' => 400,
                'message' => 'inputs are failing validation; expiresAt must be in the future',
            ], 400),
        ]);
        $booking = $this->pendingBooking(4000);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
                'payment_method_type' => 'CHECKOUT',
            ])
            ->assertStatus(502);

        Http::assertSentCount(1);
    }

    public function test_clients_learn_the_lowest_amount_that_can_be_paid_in_instalments(): void
    {
        $this->assertSame(3000.0, PaymentGateway::publicConfig()['installment_min_amount']);

        config(['payment.beam.checkout.groups.cardInstallments' => false]);
        $this->assertNull(PaymentGateway::publicConfig()['installment_min_amount']);
    }

    public function test_a_group_switched_off_in_config_is_sent_as_disabled(): void
    {
        config(['payment.beam.checkout.groups.mobileBanking' => false]);
        $this->fakeLinkCreated();
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
                'payment_method_type' => 'CHECKOUT',
            ])
            ->assertStatus(201);

        // ส่ง linkSettings แล้ว กลุ่มที่ "ไม่ส่ง" Beam ก็ถือว่าปิด — แต่ส่ง false ให้ชัดดีกว่า
        Http::assertSent(fn (Request $request) => $request['linkSettings']['mobileBanking']['isEnabled'] === false
            && $request['linkSettings']['card']['isEnabled'] === true);
    }

    public function test_checkout_link_never_outlives_the_seat_hold(): void
    {
        config(['payment.beam.checkout.ttl_minutes' => 30]);
        $this->fakeLinkCreated();
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
                'payment_method_type' => 'CHECKOUT',
            ])
            ->assertStatus(201);

        $seatDeadline = $booking->created_at->copy()->addMinutes(Booking::PENDING_TTL_MINUTES);
        $this->assertTrue(Payment::firstOrFail()->expires_at->lte($seatDeadline));
    }

    public function test_payment_link_paid_confirms_the_booking(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);

        $this->postWebhook([
            'paymentLinkId' => 'pl_test_1',
            'status' => 'PAID',
            'order' => ['netAmount' => 400000, 'currency' => 'THB', 'referenceId' => $payment->reference_id],
        ], 'payment_link.paid')->assertOk();

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('beam_checkout', $booking->fresh()->payment_method);
    }

    public function test_a_link_charge_without_our_reference_is_matched_by_its_link_and_keeps_the_charge_id(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);

        $this->postWebhook([
            'chargeId' => 'ch_card_1',
            'referenceId' => 'something-beam-made-up',
            'status' => 'SUCCEEDED',
            'source' => 'PAYMENT_LINK',
            'sourceId' => 'pl_test_1',
            'paymentMethod' => ['paymentMethodType' => 'CARD', 'card' => ['brand' => 'VISA', 'last4' => '4242']],
        ], 'charge.succeeded')->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame(Payment::STATUS_SUCCEEDED, $fresh->status);
        // ต้องใช้ตอนคืนเงิน — ตอนออกใบเรามีแค่ id ของลิงก์
        $this->assertSame('ch_card_1', $fresh->provider_charge_id);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('beam_card', $booking->fresh()->payment_method);
    }

    public function test_both_events_arriving_confirm_only_once_and_still_record_the_charge(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);

        $this->postWebhook([
            'paymentLinkId' => 'pl_test_1',
            'status' => 'PAID',
            'order' => ['referenceId' => $payment->reference_id],
        ], 'payment_link.paid')->assertOk();

        $paidAt = $booking->fresh()->paid_at;

        $this->postWebhook([
            'chargeId' => 'ch_wallet_1',
            'referenceId' => $payment->reference_id,
            'status' => 'SUCCEEDED',
            'source' => 'PAYMENT_LINK',
            'sourceId' => 'pl_test_1',
            'paymentMethod' => ['paymentMethodType' => 'LINE_PAY'],
        ], 'charge.succeeded')->assertOk();

        $this->assertSame('ch_wallet_1', $payment->fresh()->provider_charge_id);
        $this->assertEquals(4000.0, (float) $booking->fresh()->paid_amount);
        $this->assertEquals($paidAt->toISOString(), $booking->fresh()->paid_at->toISOString());
    }

    public function test_a_declined_card_on_the_link_does_not_close_the_payment(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);

        $this->postWebhook([
            'chargeId' => 'ch_declined',
            'referenceId' => $payment->reference_id,
            'status' => 'FAILED',
            'source' => 'PAYMENT_LINK',
            'sourceId' => 'pl_test_1',
            'failureCode' => 'CH_CARD_DECLINED',
        ], 'charge.failed')->assertOk();

        // ลิงก์ยัง ACTIVE — ลูกค้าลองบัตรอื่นต่อในหน้าเดิมได้
        $fresh = $payment->fresh();
        $this->assertSame(Payment::STATUS_PENDING, $fresh->status);
        $this->assertSame('CH_CARD_DECLINED', $fresh->failure_code);
        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_the_status_poll_asks_beam_about_the_link(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);

        Http::fake([
            '*/api/v1/payment-links/pl_test_1' => Http::response([
                'paymentLinkId' => 'pl_test_1',
                'status' => 'PAID',
                'order' => ['referenceId' => $payment->reference_id],
            ], 200),
        ]);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/beam/'.$payment->id.'?sync=1')
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED);

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_reconcile_disables_a_link_that_outlived_our_deadline(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);
        Payment::whereKey($payment->id)->update([
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(ReconcileBeamChargesJob::GRACE_MINUTES + 5),
        ]);

        Http::fake([
            '*/api/v1/payment-links/pl_test_1/disable' => Http::response(null, 204),
            '*/api/v1/payment-links/pl_test_1' => Http::response(['paymentLinkId' => 'pl_test_1', 'status' => 'ACTIVE'], 200),
        ]);

        (new ReconcileBeamChargesJob)->handle(app(BeamPaymentService::class));

        $this->assertSame(Payment::STATUS_EXPIRED, $payment->fresh()->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/api/v1/payment-links/pl_test_1/disable'));
    }

    public function test_reconcile_settles_a_link_that_was_paid_while_we_tried_to_disable_it(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);
        Payment::whereKey($payment->id)->update([
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(ReconcileBeamChargesJob::GRACE_MINUTES + 5),
        ]);

        Http::fake([
            '*/api/v1/payment-links/pl_test_1/disable' => Http::response(['error' => 'link is not active'], 409),
            '*/api/v1/payment-links/pl_test_1' => Http::sequence()
                ->push(['paymentLinkId' => 'pl_test_1', 'status' => 'ACTIVE'], 200)
                ->push(['paymentLinkId' => 'pl_test_1', 'status' => 'PAID'], 200),
        ]);

        (new ReconcileBeamChargesJob)->handle(app(BeamPaymentService::class));

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_a_refunded_link_never_confirms_the_seat(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);

        Http::fake([
            '*/api/v1/payment-links/pl_test_1' => Http::response(['paymentLinkId' => 'pl_test_1', 'status' => 'REFUNDED'], 200),
        ]);

        app(BeamPaymentService::class)->syncFromProvider($payment);

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_cancelling_on_the_checkout_page_lands_on_a_way_back(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);

        $this->get('/payment/return?payment='.$payment->id.'&cancelled=1')
            ->assertOk()
            ->assertSee('ยกเลิกการชำระเงินแล้ว')
            ->assertSee('กลับไปเลือกวิธีชำระเงิน')
            ->assertSee('/payment/'.$booking->booking_ref, false)
            // ไม่ต้องนั่งรอผลที่ไม่มีวันมา
            ->assertDontSee('กำลังรอผลจากธนาคาร');
    }

    public function test_a_cancel_url_hit_after_the_payment_went_through_still_shows_the_wait(): void
    {
        $booking = $this->pendingBooking();
        $payment = $this->checkoutPayment($booking);
        $payment->update(['status' => Payment::STATUS_SUCCEEDED]);

        $this->get('/payment/return?payment='.$payment->id.'&cancelled=1')
            ->assertOk()
            ->assertDontSee('ยกเลิกการชำระเงินแล้ว');
    }
}
