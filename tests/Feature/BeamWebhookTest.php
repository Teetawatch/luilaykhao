<?php

namespace Tests\Feature;

use App\Jobs\ReconcileBeamChargesJob;
use App\Models\Booking;
use App\Models\BookingSplitShare;
use App\Models\InstallmentPayment;
use App\Models\Payment;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * webhook คือ "คนเดียว" ที่ยืนยันการจองในโหมด Beam — จุดที่ผิดพลาดแล้วเสียหายที่สุด
 * ในระบบทั้งหมด เทสต์ชุดนี้จึงคุมสามอย่าง: ลายเซ็นต้องถูก, ยิงซ้ำต้องไม่เกิดผลซ้ำ,
 * และเงินที่มาช้ากว่าที่นั่งต้องไม่ถูกยืนยันเงียบๆ
 */
class BeamWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'c2VjcmV0LWtleS1mb3ItYmVhbS10ZXN0cw=='; // base64 เหมือนที่ Lighthouse ให้มา

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.provider' => 'beam',
            'payment.beam.merchant_id' => 'merchant_test',
            'payment.beam.api_key' => 'key_test',
            'payment.beam.webhook_secret' => self::SECRET,
        ]);
    }

    private function pendingPayment(string $purpose = Payment::PURPOSE_FULL, float $amount = 4000): Payment
    {
        $user = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Beam Hook Trip', 'slug' => 'beam-hook-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => $amount, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'payment_type' => 'full',
        ]);

        return Payment::create([
            'booking_id' => $booking->id,
            'purpose' => $purpose,
            'provider' => 'beam',
            'provider_charge_id' => 'ch_'.uniqid(),
            'reference_id' => $booking->booking_ref.'-1',
            'amount' => $amount,
            'currency' => 'THB',
            'status' => Payment::STATUS_PENDING,
            'payment_method_type' => 'QR_PROMPT_PAY',
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(9),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, string $event = 'charge.succeeded', ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);
        $signature ??= base64_encode(hash_hmac('sha256', $body, base64_decode(self::SECRET), true));

        return $this->call('POST', '/api/v1/payments/beam/webhook', [], [], [], [
            'HTTP_X-Beam-Signature' => $signature,
            'HTTP_X-Beam-Event' => $event,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /** created_at ถูก guard ไว้บนโมเดล ต้องเขียนผ่าน query builder ถึงจะเปลี่ยนจริง. */
    private function ageBooking(int $bookingId): void
    {
        Booking::whereKey($bookingId)->update([
            'created_at' => now()->subMinutes(Booking::PENDING_TTL_MINUTES + 5),
        ]);
    }

    private function agePayment(int $paymentId): void
    {
        Payment::whereKey($paymentId)->update([
            'created_at' => now()->subMinutes(ReconcileBeamChargesJob::GRACE_MINUTES + 5),
        ]);
    }

    public function test_the_endpoint_is_disabled_without_a_configured_secret(): void
    {
        config(['payment.beam.webhook_secret' => null]);

        $this->postWebhook(['referenceId' => 'nope'])->assertStatus(503);
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $this->postWebhook(['referenceId' => 'nope'], signature: '')->assertStatus(401);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $payment = $this->pendingPayment();

        $this->postWebhook(['referenceId' => $payment->reference_id], signature: 'ZGVhZGJlZWY=')
            ->assertStatus(401);

        $this->assertSame('pending', $payment->booking->fresh()->status);
    }

    public function test_a_signature_over_different_content_is_rejected(): void
    {
        $payment = $this->pendingPayment();
        $signature = base64_encode(hash_hmac('sha256', '{"referenceId":"something-else"}', base64_decode(self::SECRET), true));

        $this->postWebhook(['referenceId' => $payment->reference_id], signature: $signature)
            ->assertStatus(401);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_charge_succeeded_confirms_the_booking(): void
    {
        $payment = $this->pendingPayment();

        $this->postWebhook([
            'chargeId' => $payment->provider_charge_id,
            'referenceId' => $payment->reference_id,
            'status' => 'SUCCEEDED',
            'amount' => 400000,
        ])->assertOk();

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->succeeded_at);

        $booking = $payment->booking->fresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertEquals(4000.0, (float) $booking->paid_amount);
        $this->assertNotNull($booking->paid_at);
    }

    public function test_replaying_the_same_event_confirms_only_once(): void
    {
        Mail::fake();
        $payment = $this->pendingPayment();

        $payload = [
            'chargeId' => $payment->provider_charge_id,
            'referenceId' => $payment->reference_id,
            'status' => 'SUCCEEDED',
        ];

        $this->postWebhook($payload)->assertOk();
        $paidAt = $payment->booking->fresh()->paid_at;

        // Beam retry ได้ถึง 10 ครั้ง — สองครั้งหลังต้องไม่แตะอะไรอีก
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $booking = $payment->booking->fresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertEquals(4000.0, (float) $booking->paid_amount);
        $this->assertEquals($paidAt->toISOString(), $booking->paid_at->toISOString());
        $this->assertSame(1, Payment::where('status', Payment::STATUS_SUCCEEDED)->count());
    }

    public function test_an_unknown_reference_is_acknowledged_so_beam_stops_retrying(): void
    {
        $this->postWebhook(['referenceId' => 'LLK-NOPE-0001-99'])->assertOk();
    }

    public function test_an_unhandled_event_is_acknowledged(): void
    {
        $payment = $this->pendingPayment();

        $this->postWebhook(['referenceId' => $payment->reference_id], event: 'transaction.created')
            ->assertOk();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_charge_failed_leaves_the_seat_alone(): void
    {
        $payment = $this->pendingPayment();

        $this->postWebhook([
            'referenceId' => $payment->reference_id,
            'failureCode' => 'EXPIRED',
        ], event: 'charge.failed')->assertOk();

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame('EXPIRED', $payment->fresh()->failure_code);
        $this->assertSame('pending', $payment->booking->fresh()->status);
    }

    public function test_money_arriving_after_the_booking_was_cancelled_is_flagged_for_refund(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('operator', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payment = $this->pendingPayment();
        $payment->booking->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->postWebhook([
            'referenceId' => $payment->reference_id,
            'status' => 'SUCCEEDED',
        ])->assertOk();

        // ห้ามยืนยันย้อนหลัง — ที่นั่งอาจถูกคนอื่นจองไปแล้ว
        $this->assertSame('cancelled', $payment->booking->fresh()->status);
        $this->assertSame('booking_cancelled', $payment->fresh()->failure_code);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $admin->id,
            'type' => 'payment_needs_refund',
        ]);
    }

    public function test_a_live_gateway_charge_keeps_the_seat_past_the_normal_timeout(): void
    {
        $payment = $this->pendingPayment();
        // การจองเก่ากว่า TTL แล้ว แต่ QR ยังไม่หมดอายุ — ลูกค้าอาจกำลังสแกนจ่ายอยู่
        $this->ageBooking($payment->booking_id);

        $expired = app(BookingService::class)->expireStalePendingBookings();

        $this->assertSame(0, $expired);
        $this->assertSame('pending', $payment->booking->fresh()->status);
    }

    public function test_an_expired_gateway_charge_no_longer_holds_the_seat(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['expires_at' => now()->subMinute()]);
        $this->ageBooking($payment->booking_id);

        $expired = app(BookingService::class)->expireStalePendingBookings();

        $this->assertSame(1, $expired);
        $this->assertSame('cancelled', $payment->booking->fresh()->status);
    }

    public function test_reconcile_settles_a_charge_whose_webhook_never_arrived(): void
    {
        $payment = $this->pendingPayment();
        $this->agePayment($payment->id);

        Http::fake(['*/api/v1/charges/*' => Http::response([
            'chargeId' => $payment->provider_charge_id,
            'status' => 'SUCCEEDED',
        ], 200)]);

        ReconcileBeamChargesJob::dispatchSync();

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame('confirmed', $payment->booking->fresh()->status);
    }

    public function test_reconcile_leaves_a_charge_that_is_still_waiting(): void
    {
        $payment = $this->pendingPayment();
        $this->agePayment($payment->id);

        Http::fake(['*/api/v1/charges/*' => Http::response(['status' => 'PENDING'], 200)]);

        ReconcileBeamChargesJob::dispatchSync();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame('pending', $payment->booking->fresh()->status);
    }

    public function test_reconcile_does_nothing_while_the_provider_is_manual(): void
    {
        config(['payment.provider' => 'manual']);
        $payment = $this->pendingPayment();
        $this->agePayment($payment->id);

        Http::fake();
        ReconcileBeamChargesJob::dispatchSync();

        Http::assertNothingSent();
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_paying_the_balance_settles_it_without_touching_the_seat(): void
    {
        $payment = $this->pendingPayment(Payment::PURPOSE_BALANCE, 3000);
        $payment->booking->update([
            'status' => 'confirmed',
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 3000,
            'paid_amount' => 1000,
        ]);

        $this->postWebhook([
            'referenceId' => $payment->reference_id,
            'status' => 'SUCCEEDED',
        ])->assertOk();

        $booking = $payment->booking->fresh();
        $this->assertNotNull($booking->balance_paid_at);
        $this->assertEquals(4000.0, (float) $booking->paid_amount);
    }

    public function test_paying_a_later_instalment_marks_only_that_row_paid(): void
    {
        $payment = $this->pendingPayment(Payment::PURPOSE_INSTALLMENT_DUE, 2500);
        $booking = $payment->booking;
        $booking->update([
            'status' => 'confirmed',
            'payment_type' => 'installment',
            'installment_count' => 2,
            'paid_amount' => 2500,
        ]);

        $first = InstallmentPayment::create([
            'booking_id' => $booking->id, 'installment_no' => 1, 'amount' => 2500,
            'due_date' => now()->subMonth()->toDateString(), 'status' => 'paid', 'paid_at' => now()->subMonth(),
        ]);
        $second = InstallmentPayment::create([
            'booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 2500,
            'due_date' => now()->addMonth()->toDateString(), 'status' => 'pending',
        ]);

        $payment->update(['purpose_id' => $second->id]);

        $this->postWebhook([
            'referenceId' => $payment->reference_id,
            'status' => 'SUCCEEDED',
        ])->assertOk();

        $this->assertSame('paid', $second->fresh()->status);
        $this->assertSame('paid', $first->fresh()->status);
        $this->assertEquals(5000.0, (float) $booking->fresh()->paid_amount);
    }

    public function test_a_friend_paying_their_share_settles_only_that_share(): void
    {
        $payment = $this->pendingPayment(Payment::PURPOSE_SPLIT_SHARE, 1500);
        $booking = $payment->booking;
        $booking->update([
            'status' => 'confirmed',
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 3000,
            'paid_amount' => 1000,
        ]);

        $mine = BookingSplitShare::create([
            'booking_id' => $booking->id, 'label' => 'เพื่อน A', 'amount' => 1500,
            'status' => BookingSplitShare::STATUS_PENDING, 'pay_token' => Str::lower(Str::random(32)),
        ]);
        $other = BookingSplitShare::create([
            'booking_id' => $booking->id, 'label' => 'เพื่อน B', 'amount' => 1500,
            'status' => BookingSplitShare::STATUS_PENDING, 'pay_token' => Str::lower(Str::random(32)),
        ]);

        $payment->update(['purpose_id' => $mine->id]);

        $this->postWebhook([
            'referenceId' => $payment->reference_id,
            'status' => 'SUCCEEDED',
        ])->assertOk();

        $this->assertSame(BookingSplitShare::STATUS_PAID, $mine->fresh()->status);
        $this->assertSame(BookingSplitShare::STATUS_PENDING, $other->fresh()->status);
        $this->assertEquals(1500.0, (float) $booking->fresh()->balance_amount);
    }

    public function test_settling_notifies_the_customer_exactly_once(): void
    {
        $payment = $this->pendingPayment();

        $payload = ['referenceId' => $payment->reference_id, 'status' => 'SUCCEEDED'];
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, SmartNotification::where('user_id', $payment->user_id)
            ->where('type', 'payment_confirmed')
            ->count());
    }
}
