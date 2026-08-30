<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSplitShare;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /payments/{ref}/promptpay — QR ของยอดที่ต้องโอน "ตอนนี้"
 *
 * มีไว้เพื่อให้ client ที่ไม่มี build step (LIFF) ไม่ต้องประกอบ EMVCo payload เอง
 * ยอดต้องมาจาก PaymentQuote เสมอ ไม่ใช่จากที่ client ส่งมา
 */
class PromptPayQrEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function pendingBooking(array $scheduleOverrides = [], int $passengers = 2, float $total = 6000): Booking
    {
        $trip = Trip::create([
            'title' => 'QR Trip', 'slug' => 'qr-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ] + $scheduleOverrides);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
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
                'phone' => '08000000'.$i,
            ]);
        }

        return $booking->fresh();
    }

    public function test_it_returns_a_qr_for_the_full_amount(): void
    {
        $booking = $this->pendingBooking();

        $res = $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay');

        $res->assertOk()
            ->assertJsonPath('data.amount', 6000)
            ->assertJsonPath('data.purpose', 'full')
            ->assertJsonPath('data.promptpay_id', config('payment.promptpay_id_display'));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $res->json('data.qr_data_uri'));
    }

    /** มัดจำแบบยอดคงที่คิด "ต่อคน" — กลุ่ม 2 คนต้องได้ QR ยอดสองเท่า ไม่ใช่ยอดคนเดียว */
    public function test_deposit_quote_is_per_passenger(): void
    {
        $booking = $this->pendingBooking([
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 1000,
        ]);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay?purpose=deposit')
            ->assertOk()
            ->assertJsonPath('data.amount', 2000);
    }

    public function test_it_rejects_a_purpose_the_round_does_not_offer(): void
    {
        $booking = $this->pendingBooking(['deposit_enabled' => false]);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay?purpose=deposit')
            ->assertStatus(422);
    }

    public function test_a_stranger_cannot_read_the_qr(): void
    {
        $booking = $this->pendingBooking();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay')
            ->assertStatus(403);
    }

    public function test_a_confirmed_booking_has_no_first_payment_left(): void
    {
        $booking = $this->pendingBooking();
        $booking->update(['status' => 'confirmed']);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay')
            ->assertStatus(422);
    }

    /** ยอดคงเหลืออ่านจากแถวที่บันทึกไว้ ไม่ใช่คิดใหม่ — ไม่งั้นไม่ตรงกับที่ระบบรอรับ */
    public function test_balance_uses_the_stored_amount_on_a_confirmed_booking(): void
    {
        $booking = $this->pendingBooking();
        $booking->update([
            'status' => 'confirmed',
            'payment_type' => 'deposit',
            'deposit_amount' => 2000,
            'balance_amount' => 4000,
            'paid_amount' => 2000,
        ]);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay?purpose=balance')
            ->assertOk()
            ->assertJsonPath('data.amount', 4000);
    }

    public function test_a_paid_balance_has_nothing_left_to_charge(): void
    {
        $booking = $this->pendingBooking();
        $booking->update([
            'status' => 'confirmed', 'payment_type' => 'deposit',
            'balance_amount' => 4000, 'balance_paid_at' => now(),
        ]);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay?purpose=balance')
            ->assertStatus(422);
    }

    public function test_installment_due_reads_the_requested_instalment(): void
    {
        $booking = $this->pendingBooking();
        $booking->update(['status' => 'confirmed', 'payment_type' => 'installment']);

        foreach ([[1, 2000, 'paid'], [2, 4000, 'pending']] as [$no, $amount, $status]) {
            InstallmentPayment::create([
                'booking_id' => $booking->id,
                'installment_no' => $no,
                'amount' => $amount,
                'due_date' => now()->addDays($no * 15)->toDateString(),
                'status' => $status,
            ]);
        }

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay?purpose=installment_due&installment_no=2')
            ->assertOk()
            ->assertJsonPath('data.amount', 4000);

        // งวดที่จ่ายแล้วต้องไม่ออก QR ให้จ่ายซ้ำ
        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay?purpose=installment_due&installment_no=1')
            ->assertStatus(422);
    }

    public function test_split_share_reads_the_share_amount(): void
    {
        $booking = $this->pendingBooking();
        $booking->update(['status' => 'confirmed', 'payment_type' => 'deposit', 'balance_amount' => 4000]);

        $share = BookingSplitShare::create([
            'booking_id' => $booking->id,
            'label' => 'เพื่อน ก',
            'amount' => 1500,
            'status' => BookingSplitShare::STATUS_PENDING,
            'pay_token' => BookingSplitShare::generateToken(),
        ]);

        $this->actingAs($booking->user)
            ->getJson('/api/v1/payments/'.$booking->booking_ref.'/promptpay?purpose=split_share&share_id='.$share->id)
            ->assertOk()
            ->assertJsonPath('data.amount', 1500);
    }

    public function test_check_in_qr_is_rendered_for_the_owner_only(): void
    {
        $booking = $this->pendingBooking();

        $res = $this->actingAs($booking->user)
            ->getJson('/api/v1/bookings/'.$booking->booking_ref.'/check-in-qr');

        $res->assertOk()->assertJsonPath('data.code', $booking->qr_code);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $res->json('data.qr_data_uri'));

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/bookings/'.$booking->booking_ref.'/check-in-qr')
            ->assertStatus(403);
    }
}
