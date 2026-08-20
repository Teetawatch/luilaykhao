<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSplitShare;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ออก QR ผ่าน Beam — ยอดต้องมาจาก PaymentQuote และที่นั่งต้อง "ยังไม่" ถูกยืนยัน
 * จนกว่า webhook จะเข้า (ดู BeamWebhookTest)
 */
class BeamPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.provider' => 'beam',
            'payment.beam.merchant_id' => 'merchant_test',
            'payment.beam.api_key' => 'key_test',
            'payment.beam.base_url' => 'https://playground.api.beamcheckout.com',
            'payment.beam.qr_ttl_minutes' => 15,
        ]);
    }

    private function fakeBeamOk(string $chargeId = 'ch_test_1'): void
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

    /**
     * @param  array<string, mixed>  $scheduleOverrides
     */
    private function pendingBooking(array $scheduleOverrides = [], int $passengers = 1, float $total = 4000): Booking
    {
        $user = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Beam Trip', 'slug' => 'beam-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Chiang Mai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 4000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ] + $scheduleOverrides);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => $total,
            'paid_amount' => 0,
            'payment_type' => 'full',
        ]);

        for ($i = 1; $i <= $passengers; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => 'ผู้เดินทาง '.$i,
                'phone' => '0800000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]);
        }

        return $booking->fresh();
    }

    /**
     * .env.example เว้น BEAM_RETURN_URL ไว้ว่าง และ env() ถือว่าสตริงว่าง = "ตั้งค่าแล้ว"
     * ไม่ใช้ค่า default ให้ ถ้า config ไม่กัน returnUrl จะกลายเป็น "?payment=123"
     * ซึ่งพาลูกค้าที่จ่ายผ่านแอปธนาคารกลับมาไม่ได้ — และจะรู้ตัวตอนมีคนจ่ายจริงเท่านั้น
     */
    public function test_an_empty_return_url_still_resolves_to_an_absolute_url(): void
    {
        $repository = Env::getRepository();
        $keys = ['BEAM_RETURN_URL', 'BEAM_BASE_URL', 'BEAM_QR_TTL_MINUTES', 'APP_URL'];
        $restore = array_combine($keys, array_map(fn ($k) => $repository->get($k), $keys));

        // เลียนแบบ .env ที่มีบรรทัดเหล่านี้อยู่จริงแต่เว้นค่าไว้ว่าง
        $repository->set('BEAM_RETURN_URL', '');
        $repository->set('BEAM_BASE_URL', '');
        $repository->set('BEAM_QR_TTL_MINUTES', '');
        $repository->set('APP_URL', 'https://luilaykhao.com/');

        try {
            $config = require config_path('payment.php');

            $this->assertSame('https://luilaykhao.com/payment/return', $config['beam']['return_url']);
            $this->assertSame('https://playground.api.beamcheckout.com', $config['beam']['base_url']);
            $this->assertSame(15, $config['beam']['qr_ttl_minutes']);
        } finally {
            foreach ($restore as $key => $value) {
                $value === null ? $repository->clear($key) : $repository->set($key, $value);
            }
        }
    }

    public function test_full_charge_uses_the_booking_total_and_leaves_the_seat_unconfirmed(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();

        $response = $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
                'payment_method_type' => 'QR_PROMPT_PAY',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.amount', 4000);
        $response->assertJsonPath('data.charge_id', 'ch_test_1');
        $response->assertJsonPath('data.action_required', 'ENCODED_IMAGE');
        $response->assertJsonPath('data.qr_image_base64', 'iVBORw0KGgo=');

        // เงินยังไม่เข้า — ที่นั่งต้องยังไม่ยืนยัน
        $this->assertSame('pending', $booking->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'purpose' => 'full',
            'status' => Payment::STATUS_PENDING,
            'amount' => '4000.00',
        ]);
    }

    public function test_charge_sends_the_amount_to_beam_in_satang(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking(total: 2500.50);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])
            ->assertStatus(201);

        Http::assertSent(function ($request) use ($booking) {
            return $request['amount'] === 250050
                && $request['currency'] === 'THB'
                && str_starts_with($request['referenceId'], $booking->booking_ref.'-')
                && $request['paymentMethod']['paymentMethodType'] === 'QR_PROMPT_PAY';
        });
    }

    public function test_deposit_charge_writes_the_deposit_intent_before_payment(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking([
            'deposit_enabled' => true,
            'deposit_type' => 'percent',
            'deposit_percent' => 30,
        ]);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'deposit',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.amount', 1200);

        // เจตนาต้องถูกเขียนไว้แล้ว เพราะ webhook ไม่มีทางรู้ว่าลูกค้าเลือกมัดจำ
        $fresh = $booking->fresh();
        $this->assertSame('deposit', $fresh->payment_type);
        $this->assertEquals(1200.0, (float) $fresh->deposit_amount);
        $this->assertEquals(2800.0, (float) $fresh->balance_amount);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_installment_charge_creates_the_schedule_and_quotes_the_first_instalment(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking([
            'installment_enabled' => true,
            'installment_count' => 4,
            'installment_interval_days' => 30,
        ]);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'installment',
                'installment_count' => 2,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.amount', 2000);

        $this->assertSame(2, $booking->fresh()->installmentPayments()->count());
        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_deposit_charge_is_rejected_when_the_round_does_not_offer_one(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'deposit',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'รอบเดินทางนี้ไม่รองรับการจ่ายมัดจำ');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_split_share_charge_uses_the_share_amount(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking(passengers: 2);
        $booking->update(['status' => 'confirmed', 'balance_amount' => 2000]);

        $share = BookingSplitShare::create([
            'booking_id' => $booking->id,
            'label' => 'เพื่อน',
            'amount' => 2000,
            'status' => BookingSplitShare::STATUS_PENDING,
            'pay_token' => Str::random(32),
        ]);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'split_share',
                'share_id' => $share->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.amount', 2000);

        $this->assertDatabaseHas('payments', [
            'purpose' => Payment::PURPOSE_SPLIT_SHARE,
            'purpose_id' => $share->id,
        ]);
    }

    public function test_a_share_from_another_booking_cannot_be_charged(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();
        $other = $this->pendingBooking();

        $share = BookingSplitShare::create([
            'booking_id' => $other->id,
            'label' => 'เพื่อน',
            'amount' => 500,
            'status' => BookingSplitShare::STATUS_PENDING,
            'pay_token' => Str::random(32),
        ]);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'split_share',
                'share_id' => $share->id,
            ])
            ->assertStatus(422);
    }

    public function test_someone_elses_booking_cannot_be_charged(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_endpoint_is_closed_while_the_provider_is_manual(): void
    {
        config(['payment.provider' => 'manual']);
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])
            ->assertStatus(503);
    }

    public function test_a_beam_outage_tells_the_customer_to_transfer_manually(): void
    {
        Http::fake(['*/api/v1/charges' => Http::response(['message' => 'boom'], 500)]);
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])
            ->assertStatus(502);

        // แถวยังอยู่ แต่ถูกปิดเป็น failed ไม่ค้างเป็น pending ให้ reconcile ไปไล่เปล่าๆ
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'status' => Payment::STATUS_FAILED,
            'failure_code' => 'create_failed',
        ]);
    }

    public function test_the_qr_never_outlives_the_seat_hold(): void
    {
        // Beam ไม่ส่ง expiry กลับมา เพื่อให้เห็นค่าที่เราคำนวณเอง
        Http::fake(['*/api/v1/charges' => Http::response([
            'chargeId' => 'ch_no_expiry',
            'actionRequired' => 'ENCODED_IMAGE',
            'encodedImage' => ['imageBase64Encoded' => 'iVBORw0KGgo='],
        ], 200)]);

        $booking = $this->pendingBooking();
        // TTL ของ QR (15 นาที) ยาวกว่าเวลาที่ที่นั่งเหลือ (10 นาที) มาก
        $seatDeadline = $booking->created_at->copy()->addMinutes(Booking::PENDING_TTL_MINUTES);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])
            ->assertStatus(201);

        $payment = Payment::latest('id')->first();
        $this->assertTrue(
            $payment->expires_at->lt($seatDeadline),
            'QR ต้องหมดอายุก่อนที่ timer จะคืนที่นั่ง',
        );
    }

    public function test_a_longer_expiry_from_beam_does_not_stretch_the_seat_hold(): void
    {
        // Beam ตอบว่า QR อยู่ได้อีกชั่วโมง — ยาวกว่าเวลาที่ที่นั่งเหลือ
        Http::fake(['*/api/v1/charges' => Http::response([
            'chargeId' => 'ch_long_expiry',
            'actionRequired' => 'ENCODED_IMAGE',
            'encodedImage' => [
                'imageBase64Encoded' => 'iVBORw0KGgo=',
                'expiry' => now()->addHour()->toIso8601ZuluString(),
            ],
        ], 200)]);

        $booking = $this->pendingBooking();
        $seatDeadline = $booking->created_at->copy()->addMinutes(Booking::PENDING_TTL_MINUTES);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])
            ->assertStatus(201);

        $payment = Payment::latest('id')->first();
        $this->assertTrue(
            $payment->expires_at->lt($seatDeadline),
            'อายุ QR ที่ Beam ส่งมาต้องยืดเกินเพดานของเราไม่ได้',
        );
    }

    public function test_an_expiry_without_a_timezone_is_read_as_thai_time(): void
    {
        // ไม่มี Z ไม่มี +07:00 — ถ้าอ่านเป็น UTC จะกลายเป็นบวกไปอีกเจ็ดชั่วโมง
        Http::fake(['*/api/v1/charges' => Http::response([
            'chargeId' => 'ch_naive_expiry',
            'actionRequired' => 'ENCODED_IMAGE',
            'encodedImage' => [
                'imageBase64Encoded' => 'iVBORw0KGgo=',
                'expiry' => now('Asia/Bangkok')->addMinutes(5)->format('Y-m-d\TH:i:s'),
            ],
        ], 200)]);

        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])
            ->assertStatus(201);

        $payment = Payment::latest('id')->first();
        $this->assertEqualsWithDelta(
            5 * 60,
            now()->diffInSeconds($payment->expires_at),
            60,
            'QR ต้องเหลืออีกราวๆ 5 นาที ไม่ใช่เจ็ดชั่วโมงกว่า',
        );
    }

    public function test_status_is_readable_by_the_owner_and_closed_to_everyone_else(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])->assertStatus(201);

        $payment = Payment::latest('id')->first();

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/beam/'.$payment->id)
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/payments/beam/'.$payment->id)
            ->assertStatus(403);
    }

    /**
     * เคยมีบั๊ก: หน้าจ่ายเงินเด้งไป "ชำระเงินเรียบร้อยแล้ว" เองตั้งแต่ poll รอบแรก
     * ทั้งที่ลูกค้ายังไม่ได้สแกน QR เลย เพราะ client เห็น booking_status = confirmed
     * ที่แถมมากับ payload — ส่วนแบ่งกลุ่ม/ยอดคงเหลือ/งวดที่ 2+ จ่ายบนการจองที่ยืนยัน
     * ไปแล้วเสมอ สถานะการจองจึงไม่มีทางบอกได้ว่าใบนี้จ่ายหรือยัง
     */
    public function test_status_of_a_charge_on_a_confirmed_booking_does_not_look_paid(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking(passengers: 2);
        $booking->update(['status' => 'confirmed', 'balance_amount' => 2000]);

        $share = BookingSplitShare::create([
            'booking_id' => $booking->id,
            'label' => 'เพื่อน',
            'amount' => 2000,
            'status' => BookingSplitShare::STATUS_PENDING,
            'pay_token' => Str::random(32),
        ]);

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'split_share',
                'share_id' => $share->id,
            ])->assertStatus(201);

        $payment = Payment::latest('id')->first();

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/beam/'.$payment->id)
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING)
            ->assertJsonMissingPath('data.booking_status');
    }

    /**
     * หน้าจอที่ลูกค้ากด "จ่ายเงินแล้ว" ต้องได้คำตอบภายในไม่กี่วินาที ไม่ใช่รอ webhook
     * อย่างเดียว — ตาข่ายที่มีอยู่ (ReconcileBeamChargesJob) แตะแถวหนึ่งก็ต่อเมื่อค้าง
     * มาแล้ว 10 นาที ซึ่งไม่มีใครนั่งจ้องหน้าจอรอได้นานขนาดนั้น
     */
    public function test_a_watcher_can_ask_beam_directly_instead_of_waiting_for_the_webhook(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])->assertStatus(201);

        $payment = Payment::latest('id')->first();

        // ยังไม่ถาม = ยังเห็นสถานะเดิมในฐานข้อมูล
        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/beam/'.$payment->id)
            ->assertJsonPath('data.status', Payment::STATUS_PENDING);

        Http::fake([
            '*/api/v1/charges/ch_test_1' => Http::response(['status' => 'SUCCEEDED'], 200),
        ]);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/beam/'.$payment->id.'?sync=1')
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED);

        $this->assertSame('confirmed', $booking->fresh()->status, 'เงินเข้าแล้วที่นั่งต้องถูกยืนยัน เหมือนตอน webhook เข้า');
    }

    /**
     * หน้าจอ poll ทุก 2 วินาที และเปิดซ้อนกันได้หลายแท็บ ถ้าปล่อยให้ทุกครั้งวิ่งออกไป
     * หา Beam จริง ใบเดียวจะยิงเกตเวย์เป็นสิบครั้งต่อนาที
     */
    public function test_asking_beam_is_throttled_to_one_call_per_payment(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])->assertStatus(201);

        $payment = Payment::latest('id')->first();

        Http::fake([
            '*/api/v1/charges/ch_test_1' => Http::response(['status' => 'PENDING'], 200),
        ]);

        foreach (range(1, 4) as $ignored) {
            $this->actingAs($booking->user)
                ->getJson('/api/v1/payments/beam/'.$payment->id.'?sync=1')
                ->assertOk();
        }

        Http::assertSentCount(1);
    }

    /**
     * Beam ล่มตอนที่ลูกค้ากำลังรออยู่ ไม่ใช่เรื่องที่ต้องเอาไปขึ้นหน้าจอเขา — poll รอบ
     * หน้ายังมีอยู่ และ webhook + reconcile ก็ยังเป็นตาข่ายรับอยู่ข้างหลัง
     */
    public function test_a_beam_outage_while_watching_still_answers_with_the_stored_status(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();

        $this->actingAs($booking->user)
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'full',
            ])->assertStatus(201);

        $payment = Payment::latest('id')->first();

        Http::fake([
            '*/api/v1/charges/ch_test_1' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/beam/'.$payment->id.'?sync=1')
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING);
    }
}
