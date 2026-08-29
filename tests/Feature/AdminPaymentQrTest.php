<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * QR ที่ทีมงานออกให้ลูกค้าสแกนจ่าย หลังเปิดการจองแทนลูกค้าจากข้อมูลที่กรอกมาทางลิงก์
 *
 * ลูกค้ากลุ่มนี้คุยอยู่ในแชท ไม่ได้ล็อกอิน และมักไม่ได้ลงแอป — ไม่มีใครกดขอ QR
 * ฝั่งลูกค้าได้ ทีมงานจึงต้องออกให้แล้วส่งต่อเอง
 */
class AdminPaymentQrTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        config([
            'payment.provider' => 'beam',
            'payment.beam.merchant_id' => 'merchant_test',
            'payment.beam.api_key' => 'key_test',
            'payment.beam.base_url' => 'https://playground.api.beamcheckout.com',
            'payment.beam.qr_ttl_minutes' => 15,
        ]);
    }

    private function fakeBeamOk(): void
    {
        Http::fake([
            '*/api/v1/charges' => Http::response([
                'chargeId' => 'ch_admin_test',
                'actionRequired' => 'ENCODED_IMAGE',
                'paymentMethodType' => 'QR_PROMPT_PAY',
                'encodedImage' => [
                    'imageBase64Encoded' => 'iVBORw0KGgo=',
                    'rawData' => '00020101021229',
                    'expiry' => now()->addMinutes(15)->toIso8601ZuluString(),
                ],
            ], 200),
        ]);
    }

    private function pendingBooking(array $overrides = []): Booking
    {
        $trip = Trip::create([
            'title' => 'ทริปทดสอบ QR', 'slug' => 'qr-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 4000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonths(2)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonths(2)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create($overrides + [
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => 8000,
            'paid_amount' => 0,
            'payment_type' => 'full',
        ]);
    }

    public function test_admin_gets_a_beam_qr_for_a_booking_awaiting_payment(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$booking->booking_ref}/qr");

        $response->assertOk()
            ->assertJsonPath('data.provider', 'beam')
            ->assertJsonPath('data.purpose_label', 'ยอดเต็ม')
            ->assertJsonPath('data.amount', 8000)
            ->assertJsonPath('data.qr_image_base64', 'iVBORw0KGgo=');

        // ใบชำระเงินเป็นของลูกค้า ไม่ใช่ของแอดมินที่กดปุ่ม
        $payment = Payment::findOrFail($response->json('data.payment_id'));
        $this->assertSame($booking->user_id, $payment->user_id);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
    }

    public function test_pressing_the_button_twice_reuses_the_same_charge(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();

        $first = $this->actingAs($this->admin)->postJson("/api/v1/admin/payments/{$booking->booking_ref}/qr");
        $second = $this->actingAs($this->admin)->postJson("/api/v1/admin/payments/{$booking->booking_ref}/qr");

        $this->assertSame($first->json('data.payment_id'), $second->json('data.payment_id'));
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
    }

    /**
     * ใบที่ทีมงานกันที่นั่งไว้ให้ไม่ได้เดินนาฬิกาสิบนาที — ถ้า QR ยังยึด created_at
     * ใบที่กันไว้ตั้งแต่เมื่อวานจะได้ QR ที่หมดอายุไปแล้วตั้งแต่วินาทีที่ออก
     */
    public function test_a_held_booking_gets_a_qr_that_is_still_valid(): void
    {
        $this->fakeBeamOk();
        $booking = $this->pendingBooking();
        $booking->forceFill([
            'created_at' => now()->subDay(),
            'hold_until' => now()->addDays(2),
        ])->save();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$booking->booking_ref}/qr");

        $response->assertOk();
        $this->assertTrue(now()->addMinutes(10)->lt($response->json('data.expires_at')));
    }

    public function test_it_falls_back_to_our_own_promptpay_qr_when_the_gateway_is_off(): void
    {
        config(['payment.provider' => 'manual']);
        $booking = $this->pendingBooking();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$booking->booking_ref}/qr");

        $response->assertOk()
            ->assertJsonPath('data.provider', 'promptpay')
            ->assertJsonPath('data.payment_id', null)
            ->assertJsonPath('data.amount', 8000);

        $this->assertStringStartsWith('data:image/svg+xml', $response->json('data.qr_data_uri'));
    }

    /**
     * ใบมัดจำต้องได้ QR ยอดมัดจำ ไม่ใช่ยอดเต็ม — ทีมงานเลือกยอดเองไม่ได้เลยตั้งใจ
     */
    public function test_a_deposit_booking_gets_a_qr_for_the_deposit_only(): void
    {
        config(['payment.provider' => 'manual']);
        $booking = $this->pendingBooking(['payment_type' => 'deposit']);
        $booking->schedule->update([
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 1000,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$booking->booking_ref}/qr")
            ->assertOk()
            ->assertJsonPath('data.purpose_label', 'ค่ามัดจำ')
            ->assertJsonPath('data.amount', 1000);
    }

    public function test_a_cancelled_booking_has_nothing_to_charge(): void
    {
        $booking = $this->pendingBooking(['status' => 'cancelled']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$booking->booking_ref}/qr")
            ->assertStatus(422);
    }

    public function test_status_refuses_a_payment_from_another_booking(): void
    {
        $this->fakeBeamOk();
        $mine = $this->pendingBooking();
        $theirs = $this->pendingBooking();

        $paymentId = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$theirs->booking_ref}/qr")
            ->json('data.payment_id');

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/payments/{$mine->booking_ref}/qr/{$paymentId}")
            ->assertStatus(404);
    }
}
