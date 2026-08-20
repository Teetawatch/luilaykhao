<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSplitShare;
use App\Models\InstallmentPayment;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ลิงก์ชำระเงินสาธารณะ (อีเมล/แชร์ให้เพื่อน) ในโหมดเกตเวย์
 *
 * สามหน้านี้คือทางที่ลูกค้าที่ "ไม่มีแอปและไม่ได้ล็อกอิน" ใช้จ่ายเงิน ถ้ามันพัง
 * เราไม่ได้เงิน — จึงต้องคุมสองอย่าง: โหมดเกตเวย์ต้องโชว์ QR ของ Beam และไม่มี
 * ช่องอัปสลิป ส่วนเวลา Beam ล่ม ต้องตกกลับไปทางเดิมได้โดยหน้าไม่พัง
 */
class BeamPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.provider' => 'beam',
            'payment.beam.merchant_id' => 'merchant_test',
            'payment.beam.api_key' => 'key_test',
        ]);
    }

    private function fakeBeamOk(): void
    {
        Http::fake([
            '*/api/v1/charges' => Http::response([
                'chargeId' => 'ch_public_'.Str::random(6),
                'actionRequired' => 'ENCODED_IMAGE',
                'encodedImage' => [
                    'imageBase64Encoded' => 'iVBORw0KGgo=',
                    'expiry' => now()->addMinutes(15)->toIso8601ZuluString(),
                ],
            ], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function confirmedBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        $trip = Trip::create([
            'title' => 'Public Beam Trip', 'slug' => 'public-beam-'.uniqid(), 'type' => 'trekking',
            'location' => 'Loei', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 5000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1, 'transport_type' => 'van', 'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 5000,
            'paid_amount' => 2000,
            'payment_token' => Str::lower(Str::random(24)),
        ] + $overrides);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'ผู้เดินทาง',
            'phone' => '0800000001',
        ]);

        return $booking->fresh();
    }

    public function test_balance_link_shows_the_gateway_qr_and_no_slip_form(): void
    {
        $this->fakeBeamOk();
        $booking = $this->confirmedBooking([
            'payment_type' => 'deposit',
            'deposit_amount' => 2000,
            'balance_amount' => 3000,
            'balance_due_at' => now()->addMonth(),
        ]);

        $this->get('/pay/'.$booking->payment_token)
            ->assertOk()
            ->assertSee('data:image/png;base64,iVBORw0KGgo=', false)
            ->assertSee('ไม่ต้องแนบสลิป')
            ->assertDontSee('name="slip_image"', false);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'purpose' => Payment::PURPOSE_BALANCE,
            'amount' => '3000.00',
        ]);
    }

    public function test_refreshing_the_link_reuses_the_same_charge(): void
    {
        $this->fakeBeamOk();
        $booking = $this->confirmedBooking([
            'payment_type' => 'deposit',
            'deposit_amount' => 2000,
            'balance_amount' => 3000,
            'balance_due_at' => now()->addMonth(),
        ]);

        $this->get('/pay/'.$booking->payment_token)->assertOk();
        $this->get('/pay/'.$booking->payment_token)->assertOk();
        $this->get('/pay/'.$booking->payment_token)->assertOk();

        // กด refresh สามครั้งต้องไม่กลายเป็น charge สามใบค้างอยู่ที่ Beam
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
    }

    public function test_installment_link_charges_the_next_unpaid_instalment(): void
    {
        $this->fakeBeamOk();
        $booking = $this->confirmedBooking([
            'payment_type' => 'installment',
            'installment_count' => 2,
        ]);

        InstallmentPayment::create([
            'booking_id' => $booking->id, 'installment_no' => 1, 'amount' => 2500,
            'due_date' => now()->subMonth()->toDateString(), 'status' => 'paid', 'paid_at' => now()->subMonth(),
        ]);
        $second = InstallmentPayment::create([
            'booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 2500,
            'due_date' => now()->addMonth()->toDateString(), 'status' => 'pending',
        ]);

        $this->get('/pay/'.$booking->payment_token)
            ->assertOk()
            ->assertDontSee('name="slip_image"', false);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'purpose' => Payment::PURPOSE_INSTALLMENT_DUE,
            'purpose_id' => $second->id,
            'amount' => '2500.00',
        ]);
    }

    public function test_share_link_charges_that_share_only(): void
    {
        $this->fakeBeamOk();
        $booking = $this->confirmedBooking([
            'payment_type' => 'deposit',
            'deposit_amount' => 2000,
            'balance_amount' => 3000,
        ]);

        $share = BookingSplitShare::create([
            'booking_id' => $booking->id,
            'label' => 'เพื่อน',
            'amount' => 1500,
            'status' => BookingSplitShare::STATUS_PENDING,
            'pay_token' => Str::lower(Str::random(32)),
        ]);

        $this->get('/pay-share/'.$share->pay_token)
            ->assertOk()
            ->assertSee('data:image/png;base64,', false)
            ->assertDontSee('name="slip_image"', false);

        $this->assertDatabaseHas('payments', [
            'purpose' => Payment::PURPOSE_SPLIT_SHARE,
            'purpose_id' => $share->id,
            'amount' => '1500.00',
        ]);
    }

    public function test_a_gateway_outage_falls_back_to_the_slip_form(): void
    {
        Http::fake(['*/api/v1/charges' => Http::response(['message' => 'boom'], 500)]);

        $booking = $this->confirmedBooking([
            'payment_type' => 'deposit',
            'deposit_amount' => 2000,
            'balance_amount' => 3000,
            'balance_due_at' => now()->addMonth(),
        ]);

        // หน้าต้องไม่พัง ลูกค้าที่กดลิงก์จากอีเมลต้องจ่ายได้เสมอ
        $this->get('/pay/'.$booking->payment_token)
            ->assertOk()
            ->assertSee('name="slip_image"', false);
    }

    public function test_the_slip_form_is_still_there_while_the_provider_is_manual(): void
    {
        config(['payment.provider' => 'manual']);
        Http::fake();

        $booking = $this->confirmedBooking([
            'payment_type' => 'deposit',
            'deposit_amount' => 2000,
            'balance_amount' => 3000,
            'balance_due_at' => now()->addMonth(),
        ]);

        $this->get('/pay/'.$booking->payment_token)
            ->assertOk()
            ->assertSee('name="slip_image"', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_return_page_reports_the_payment_status(): void
    {
        $booking = $this->confirmedBooking();

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'purpose' => Payment::PURPOSE_BALANCE,
            'provider' => 'beam',
            'reference_id' => $booking->booking_ref.'-9',
            'amount' => 3000,
            'status' => Payment::STATUS_PENDING,
            'payment_method_type' => 'KPLUS',
        ]);

        $this->get('/payment/return?payment='.$payment->id)
            ->assertOk()
            ->assertSee('กำลังรอผลจากธนาคาร');

        $this->getJson('/payment/return/'.$payment->id.'/status')
            ->assertOk()
            ->assertJsonPath('status', Payment::STATUS_PENDING);

        $payment->update(['status' => Payment::STATUS_SUCCEEDED]);

        $this->getJson('/payment/return/'.$payment->id.'/status')
            ->assertOk()
            ->assertJsonPath('status', Payment::STATUS_SUCCEEDED);
    }

    public function test_the_return_page_survives_an_unknown_payment(): void
    {
        $this->get('/payment/return?payment=999999')
            ->assertOk()
            ->assertSee('ไม่พบรายการชำระเงินนี้');
    }

    /**
     * หน้ารอผลต้องบอกได้ว่า "ตอนนี้อยู่ตรงไหน" และรออะไรอยู่
     *
     * ก่อนหน้านี้มีแค่อีโมจิ ⏳ นิ่งๆ ซึ่งแยกไม่ออกจากหน้าที่ค้าง คนที่เพิ่งจ่ายเงิน
     * หลักพันแล้วเห็นหน้าจอไม่ขยับจะสรุปเองว่าจ่ายไม่ผ่าน แล้วไปจ่ายซ้ำ
     */
    public function test_the_return_page_spells_out_what_it_is_waiting_for(): void
    {
        $booking = $this->confirmedBooking();

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'purpose' => Payment::PURPOSE_INSTALLMENT_DUE,
            'provider' => 'beam',
            'reference_id' => $booking->booking_ref.'-11',
            'amount' => 1500,
            'status' => Payment::STATUS_PENDING,
            'payment_method_type' => 'KPLUS',
        ]);

        $this->get('/payment/return?payment='.$payment->id)
            ->assertOk()
            ->assertSee('ส่งรายการชำระเงินให้ธนาคารแล้ว')
            ->assertSee('รอธนาคารยืนยันว่าเงินเข้า')
            // บรรทัดสุดท้ายเปลี่ยนตามว่าจ่ายเพื่ออะไร — ค่างวดไม่ได้ยืนยันที่นั่งอะไรใหม่
            ->assertSee('ตัดงวดที่ชำระให้อัตโนมัติ')
            ->assertSee('ไม่ต้องจ่ายซ้ำ');
    }

    /**
     * คนที่ poll หน้ารอผลคือคนที่เพิ่งกดจ่ายเสร็จและกำลังนั่งดูหน้าจอ — ถาม Beam ให้เลย
     * ไม่ใช่รอ webhook หรือรอ reconcile ที่แตะแถวนี้ตอนค้างครบ 10 นาที
     */
    public function test_the_return_page_status_asks_beam_directly(): void
    {
        $booking = $this->confirmedBooking(['paid_amount' => 2000]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'purpose' => Payment::PURPOSE_BALANCE,
            'provider' => 'beam',
            'provider_charge_id' => 'ch_watch_1',
            'reference_id' => $booking->booking_ref.'-12',
            'amount' => 3000,
            'status' => Payment::STATUS_PENDING,
            'payment_method_type' => 'KPLUS',
        ]);

        Http::fake([
            '*/api/v1/charges/ch_watch_1' => Http::response(['status' => 'SUCCEEDED'], 200),
        ]);

        $this->getJson('/payment/return/'.$payment->id.'/status')
            ->assertOk()
            ->assertJsonPath('status', Payment::STATUS_SUCCEEDED);

        Http::assertSentCount(1);
    }
}
