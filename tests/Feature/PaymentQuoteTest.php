<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\LoyaltyAccount;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\LoyaltyTier;
use App\Support\PaymentQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ยอดที่ลูกค้าเห็น (payment_options) ต้องเป็นยอดเดียวกับที่ระบบเรียกเก็บจริง
 *
 * เคสที่เคยทำให้ลูกค้าโอนมาไม่เท่ากัน: มัดจำแบบยอดคงที่ (คิดต่อคน) และส่วนลด
 * มัดจำตามระดับสมาชิก — แอปกับเว็บเคยคำนวณเองแล้วได้คนละยอดกับหลังบ้าน
 */
class PaymentQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริป '.uniqid(),
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2000,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonths(6)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonths(6)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function makeBooking(User $user, TripSchedule $schedule, float $total, int $passengers = 1): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'pending',
            'total_amount' => $total,
        ]);

        for ($i = 1; $i <= $passengers; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => 'ผู้เดินทาง '.$i,
                'phone' => '081000000'.$i,
            ]);
        }

        return $booking->load(['schedule', 'passengers']);
    }

    public function test_fixed_deposit_is_charged_per_passenger(): void
    {
        $schedule = $this->makeSchedule([
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 1000,
        ]);

        $booking = $this->makeBooking(User::factory()->create(), $schedule, 8000, passengers: 4);

        $quote = PaymentQuote::forBooking($booking);

        // มัดจำคนละ 1,000 × 4 คน — ไม่ใช่ 1,000 ต่อการจอง
        $this->assertSame(4000.0, $quote['deposit']['amount']);
        $this->assertSame(4000.0, $quote['deposit']['balance']);
        $this->assertSame(
            $schedule->resolveDepositAmount(8000, 4, $booking->user_id),
            $quote['deposit']['amount'],
        );
    }

    public function test_deposit_quote_includes_the_member_tier_discount(): void
    {
        $schedule = $this->makeSchedule([
            'deposit_enabled' => true,
            'deposit_type' => 'percent',
            'deposit_percent' => 50,
        ]);

        $user = User::factory()->create();
        LoyaltyAccount::create([
            'user_id' => $user->id,
            'points' => 0,
            'lifetime_points' => 0,
            'lifetime_trips' => 10,
            'tier' => LoyaltyTier::INSIDER,
        ]);

        $quote = PaymentQuote::forBooking($this->makeBooking($user, $schedule, 4000));

        // 50% ของ 4,000 = 2,000 · คนกันเองลดมัดจำอีก 15% = 1,700
        $this->assertSame(1700.0, $quote['deposit']['amount']);
        $this->assertSame(2300.0, $quote['deposit']['balance']);
        $this->assertSame(15, $quote['deposit']['tier_discount_percent']);
    }

    public function test_deposit_is_unavailable_when_it_would_cover_the_whole_trip(): void
    {
        $schedule = $this->makeSchedule([
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 3000,
        ]);

        $quote = PaymentQuote::forBooking(
            $this->makeBooking(User::factory()->create(), $schedule, 2000)
        );

        $this->assertFalse($quote['deposit']['available']);
        $this->assertSame('exceeds_total', $quote['deposit']['reason']);
    }

    public function test_installment_options_stop_where_the_trip_gets_too_close(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(45)->toDateString(),
            'installment_enabled' => true,
            'installment_count' => 4,
            'installment_interval_days' => 30,
        ]);

        $quote = PaymentQuote::forBooking(
            $this->makeBooking(User::factory()->create(), $schedule, 3000)
        );

        // เหลือ 45 วัน ทุก 30 วัน → ผ่อนได้มากสุด 2 งวด แม้รอบจะตั้งไว้ 4 งวด
        $this->assertSame([2], array_column($quote['installment']['options'], 'count'));
        $this->assertSame(2, $quote['installment']['default_count']);
        $this->assertSame(1500.0, $quote['installment']['options'][0]['per_amount']);
    }

    public function test_installment_amounts_always_add_up_to_the_total(): void
    {
        $amounts = PaymentQuote::installmentAmounts(1000, 3);

        $this->assertSame(333.33, $amounts['per_amount']);
        $this->assertSame(333.34, $amounts['last_amount']);
        $this->assertSame(
            1000.0,
            round($amounts['per_amount'] * 2 + $amounts['last_amount'], 2),
        );
    }

    public function test_pending_booking_payload_carries_the_quote(): void
    {
        $schedule = $this->makeSchedule([
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 500,
        ]);

        $user = User::factory()->create();
        $booking = $this->makeBooking($user, $schedule, 4000, passengers: 2);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.payment_options.deposit.amount', 1000)
            ->assertJsonPath('data.payment_options.deposit.balance', 3000)
            ->assertJsonPath('data.payment_options.split.owner_share', 2000)
            ->assertJsonPath('data.payment_options.full.amount', 4000);
    }

    public function test_charging_a_deposit_collects_exactly_the_quoted_amount(): void
    {
        $schedule = $this->makeSchedule([
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 1000,
        ]);

        $user = User::factory()->create();
        $booking = $this->makeBooking($user, $schedule, 8000, passengers: 4);
        $quoted = PaymentQuote::forBooking($booking)['deposit'];

        // client เวอร์ชันเก่าเคยส่งมัดจำของคนเดียว (1,000) มา — เซิร์ฟเวอร์ต้องยึดยอดของตัวเอง
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'deposit',
                'payment_method' => 'promptpay',
                'amount' => 1000,
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($quoted['amount'], (float) $booking->deposit_amount);
        $this->assertSame($quoted['balance'], (float) $booking->balance_amount);
        $this->assertSame($quoted['amount'], (float) $booking->paid_amount);
    }
}
