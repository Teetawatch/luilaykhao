<?php

namespace Tests\Feature;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\BookingExtraPayment;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BeamPaymentService;
use App\Services\HomeWidgetService;
use App\Services\OutstandingPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ยอดเพิ่มเติมบนใบที่ยืนยันแล้ว — ใบที่แอดมินข้ามการชำระเงินให้ลูกค้าจ่ายทีหลัง
 * และใบที่แอดมินเพิ่มของ/ปรับยอดหลังยืนยัน ต้องจ่ายได้จริง (สลิปหรือ Beam)
 * ไม่ใช่โชว์ "ยังต้องชำระ" แล้วพาไปหน้าเช็คอิน
 */
class ExtraPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Extra Trip', 'slug' => 'extra-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Loei', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function booking(array $overrides = [], ?User $user = null): Booking
    {
        $booking = Booking::create($overrides + [
            'booking_ref' => Booking::generateRef(),
            'user_id' => ($user ?? User::factory()->create())->id,
            'schedule_id' => $this->schedule()->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'payment_type' => 'full',
        ]);

        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'ผู้เดินทาง', 'phone' => '0800000001']);

        return $booking->fresh();
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    // ── ยอดที่ต้องจ่าย ────────────────────────────────────────────────

    public function test_a_fully_paid_booking_owes_nothing(): void
    {
        $this->assertSame(0.0, $this->booking()->extraDueAmount());
    }

    public function test_extras_added_after_confirmation_become_the_extra_due(): void
    {
        $booking = $this->booking(['total_amount' => 3600]);

        $this->assertSame(600.0, $booking->extraDueAmount());
        $this->assertFalse($booking->isFullyPaid());
    }

    public function test_the_deposit_balance_is_not_counted_twice(): void
    {
        // มัดจำ 1000 ยอดคงเหลือ 2000 มีทางจ่ายของมันแล้ว — ไม่ใช่ยอดเพิ่มเติม
        $booking = $this->booking([
            'payment_type' => 'deposit', 'paid_amount' => 1000,
            'deposit_amount' => 1000, 'balance_amount' => 2000,
        ]);
        $this->assertSame(0.0, $booking->extraDueAmount());

        // แอดมินเพิ่มเต็นท์ 400 ทีหลัง — ส่วนนั้นเท่านั้นที่เป็นยอดเพิ่มเติม
        $booking->update(['total_amount' => 3400]);
        $this->assertSame(400.0, $booking->fresh()->extraDueAmount());
    }

    public function test_unpaid_installments_are_not_counted_as_extra(): void
    {
        $booking = $this->booking(['payment_type' => 'installment', 'paid_amount' => 1000, 'installment_count' => 3]);
        foreach ([1 => 'paid', 2 => 'pending', 3 => 'pending'] as $no => $status) {
            InstallmentPayment::create([
                'booking_id' => $booking->id, 'installment_no' => $no, 'amount' => 1000,
                'due_date' => now()->addDays($no * 10)->toDateString(), 'status' => $status,
            ]);
        }

        $this->assertSame(0.0, $booking->fresh()->extraDueAmount());
    }

    public function test_pending_and_cancelled_bookings_never_owe_extra(): void
    {
        $this->assertSame(0.0, $this->booking(['status' => 'pending', 'paid_amount' => 0])->extraDueAmount());
        $this->assertSame(0.0, $this->booking(['status' => 'cancelled', 'paid_amount' => 0])->extraDueAmount());
    }

    public function test_rounding_crumbs_below_one_baht_are_not_chased(): void
    {
        $this->assertSame(0.0, $this->booking(['total_amount' => 3000.67])->extraDueAmount());
    }

    public function test_the_waived_part_is_never_asked_for(): void
    {
        $booking = $this->booking(['paid_amount' => 0, 'waived_amount' => 3000]);
        $this->assertSame(0.0, $booking->extraDueAmount());

        // แอดมินยกเว้นค่าทริปให้ แต่เพิ่มของ 500 ทีหลัง — ส่วนที่เพิ่มยังต้องจ่าย
        $booking->update(['total_amount' => 3500]);
        $this->assertSame(500.0, $booking->fresh()->extraDueAmount());
    }

    public function test_the_api_tells_the_app_how_much_extra_is_due(): void
    {
        $booking = $this->booking(['total_amount' => 3600]);

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.extra_due.amount', 600);
    }

    // ── แอดมินข้ามการชำระเงิน ─────────────────────────────────────────

    /** @return array<string, mixed> */
    private function skipPaymentPayload(TripSchedule $schedule, ?string $mode): array
    {
        return array_filter([
            'schedule_id' => $schedule->id,
            'passengers' => [[
                'title' => 'นาย', 'name' => 'ผู้เดินทาง หนึ่ง', 'nickname' => 'หนึ่ง',
                'id_card' => '1234567890121', 'phone' => '0812345678', 'blood_group' => 'O',
                'halal_food' => false, 'emergency_contact' => 'แม่', 'emergency_phone' => '0898765432',
            ]],
            'skip_payment' => true,
            'skip_payment_mode' => $mode,
        ], fn ($v) => $v !== null);
    }

    public function test_admin_can_let_the_customer_pay_later_in_the_app(): void
    {
        Mail::fake();
        $schedule = $this->schedule();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->skipPaymentPayload($schedule, 'collect_later'))
            ->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        $this->assertEquals(0.0, (float) $booking->paid_amount);
        $this->assertSame((float) $booking->total_amount, $booking->extraDueAmount());
    }

    public function test_older_app_builds_without_a_mode_keep_collecting_later(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->skipPaymentPayload($this->schedule(), null))
            ->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertGreaterThan(0, $booking->extraDueAmount());
    }

    public function test_admin_can_waive_payment_without_inflating_revenue(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->skipPaymentPayload($this->schedule(), 'waive'))
            ->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertEquals(0.0, (float) $booking->paid_amount);
        $this->assertEquals((float) $booking->total_amount, (float) $booking->waived_amount);
        $this->assertSame(0.0, $booking->extraDueAmount());
    }

    public function test_a_customer_cannot_waive_their_own_payment(): void
    {
        Mail::fake();
        $customer = User::factory()->create();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/bookings', $this->skipPaymentPayload($this->schedule(), 'waive'))
            ->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertSame('pending', $booking->status);
        $this->assertEquals(0.0, (float) $booking->waived_amount);
    }

    // ── จ่ายด้วยสลิป ────────────────────────────────────────────────

    public function test_customer_pays_the_extra_with_a_slip(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::fake('local');
        Queue::fake();

        $booking = $this->booking(['total_amount' => 3600]);

        $this->actingAs($booking->user, 'sanctum')
            ->post('/api/v1/payments/charge-extra', [
                'booking_ref' => $booking->booking_ref,
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.amount', 600)
            ->assertJsonPath('data.booking.extra_due.amount', 0);

        $booking->refresh();
        $this->assertEquals(3600.0, (float) $booking->paid_amount);
        $this->assertTrue($booking->isFullyPaid());

        $payment = BookingExtraPayment::where('booking_id', $booking->id)->sole();
        $this->assertEquals(600.0, (float) $payment->amount);
        $this->assertNotNull($payment->slip_path);
        Queue::assertPushed(VerifySlipJob::class);
    }

    public function test_the_amount_comes_from_the_server_not_the_client(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::fake('local');
        Queue::fake();

        $booking = $this->booking(['total_amount' => 3600]);

        $this->actingAs($booking->user, 'sanctum')
            ->post('/api/v1/payments/charge-extra', [
                'booking_ref' => $booking->booking_ref,
                'amount' => 1,
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertEquals(600.0, (float) BookingExtraPayment::sole()->amount);
    }

    public function test_nothing_to_pay_is_refused(): void
    {
        $booking = $this->booking();

        $this->actingAs($booking->user, 'sanctum')
            ->post('/api/v1/payments/charge-extra', [
                'booking_ref' => $booking->booking_ref,
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_someone_else_cannot_pay_into_the_booking(): void
    {
        $booking = $this->booking(['total_amount' => 3600]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->post('/api/v1/payments/charge-extra', [
                'booking_ref' => $booking->booking_ref,
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_the_promptpay_qr_carries_the_extra_amount(): void
    {
        config(['payment.promptpay_id' => '0812345678']);
        $booking = $this->booking(['total_amount' => 3600]);

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/payments/{$booking->booking_ref}/promptpay?purpose=extra")
            ->assertOk()
            ->assertJsonPath('data.amount', 600);
    }

    // ── Beam ──────────────────────────────────────────────────────

    private function enableBeam(): void
    {
        config([
            'payment.provider' => 'beam',
            'payment.beam.merchant_id' => 'merchant_test',
            'payment.beam.api_key' => 'key_test',
            'payment.beam.base_url' => 'https://playground.api.beamcheckout.com',
            'payment.beam.qr_ttl_minutes' => 15,
        ]);

        Http::fake([
            '*/api/v1/charges' => Http::response([
                'chargeId' => 'ch_extra_1',
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

    public function test_beam_charges_the_extra_and_settles_it_on_payment(): void
    {
        $this->enableBeam();
        $booking = $this->booking(['total_amount' => 3600]);

        $response = $this->actingAs($booking->user, 'sanctum')
            ->postJson('/api/v1/payments/beam/charge', [
                'booking_ref' => $booking->booking_ref,
                'purpose' => 'extra',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 600)
            ->assertJsonPath('data.purpose', 'extra');

        Http::assertSent(fn ($request) => $request['amount'] === 60000);

        $payment = Payment::findOrFail($response->json('data.payment_id'));
        app(BeamPaymentService::class)->settle($payment);
        // webhook ซ้ำต้องไม่ลงเงินซ้ำ
        app(BeamPaymentService::class)->settle($payment->fresh());

        $booking->refresh();
        $this->assertEquals(3600.0, (float) $booking->paid_amount);
        $this->assertSame(0.0, $booking->extraDueAmount());
        $this->assertSame('beam_qr_prompt_pay', BookingExtraPayment::sole()->payment_method);
    }

    public function test_beam_settles_the_amount_on_the_qr_even_if_the_total_changed_since(): void
    {
        $this->enableBeam();
        $booking = $this->booking(['total_amount' => 3600]);

        $response = $this->actingAs($booking->user, 'sanctum')
            ->postJson('/api/v1/payments/beam/charge', ['booking_ref' => $booking->booking_ref, 'purpose' => 'extra'])
            ->assertCreated();

        // ระหว่างลูกค้าสแกน แอดมินเพิ่มของอีก 200
        $booking->update(['total_amount' => 3800]);

        app(BeamPaymentService::class)->settle(Payment::findOrFail($response->json('data.payment_id')));

        $booking->refresh();
        $this->assertEquals(3600.0, (float) $booking->paid_amount);
        $this->assertSame(200.0, $booking->extraDueAmount());
    }

    public function test_beam_refuses_to_charge_when_nothing_is_due(): void
    {
        $this->enableBeam();
        $booking = $this->booking();

        $this->actingAs($booking->user, 'sanctum')
            ->postJson('/api/v1/payments/beam/charge', ['booking_ref' => $booking->booking_ref, 'purpose' => 'extra'])
            ->assertStatus(422);
    }

    // ── หลังบ้าน / วิดเจ็ต ──────────────────────────────────────────

    public function test_admin_outstanding_list_includes_extra_dues(): void
    {
        $booking = $this->booking(['total_amount' => 3600]);
        $this->booking(); // จ่ายครบ ต้องไม่ติดมา

        $rows = app(OutstandingPaymentService::class)->rows();

        $this->assertCount(1, $rows);
        $this->assertSame($booking->booking_ref, $rows[0]['booking_ref']);
        $this->assertSame('extra', $rows[0]['type']);
        $this->assertEquals(600.0, $rows[0]['amount_due']);
    }

    public function test_reminding_an_extra_due_sends_an_in_app_notice(): void
    {
        $booking = $this->booking(['total_amount' => 3600]);

        $row = app(OutstandingPaymentService::class)->sendLink($booking, ['email']);

        $this->assertSame('extra', $row['type']);
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $booking->user_id, 'type' => 'extra_due']);
    }

    public function test_home_widget_shows_an_extra_due(): void
    {
        $booking = $this->booking(['total_amount' => 3600]);

        $snapshot = app(HomeWidgetService::class)->snapshotFor($booking->user_id);

        $this->assertSame('ยอดเพิ่มเติม', data_get($snapshot, 'payment.label'));
        $this->assertEquals(600.0, data_get($snapshot, 'payment.amount'));
    }

    public function test_staff_can_show_a_qr_for_the_extra_due(): void
    {
        config(['payment.provider' => 'manual', 'payment.promptpay_id' => '0812345678']);
        $booking = $this->booking(['total_amount' => 3600]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/payments/{$booking->booking_ref}/qr")
            ->assertOk()
            ->assertJsonPath('data.amount', 600);
    }

    public function test_a_live_beam_qr_is_replaced_when_the_extra_grows(): void
    {
        $this->enableBeam();
        $booking = $this->booking(['total_amount' => 3600]);
        $beam = app(BeamPaymentService::class);

        $first = $beam->ensureCharge($booking, Payment::PURPOSE_EXTRA, 'QR_PROMPT_PAY');
        $this->assertSame($first->id, $beam->ensureCharge($booking, Payment::PURPOSE_EXTRA, 'QR_PROMPT_PAY')->id);

        $booking->update(['total_amount' => 3800]);
        $second = $beam->ensureCharge($booking->fresh(), Payment::PURPOSE_EXTRA, 'QR_PROMPT_PAY');

        $this->assertNotSame($first->id, $second->id);
        $this->assertEquals(800.0, (float) $second->amount);
    }

    public function test_admin_can_waive_an_extra_from_the_edit_page(): void
    {
        $booking = $this->booking(['total_amount' => 3600]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", ['waived_amount' => 600])
            ->assertOk();

        $this->assertSame(0.0, $booking->fresh()->extraDueAmount());
    }
}
