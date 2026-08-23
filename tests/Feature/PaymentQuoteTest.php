<?php

namespace Tests\Feature;

use App\Http\Resources\TripScheduleResource;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\LoyaltyAccount;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingSettlementService;
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

    public function test_installment_plan_is_derived_from_the_departure_date_alone(): void
    {
        // 45 วันข้างหน้า → ปิดยอดก่อนเดินทาง 15 วัน เหลือช่วงผ่อนจริง 30 วัน
        // → ผ่อนได้มากสุด 3 งวด (ห่างกัน 15 วัน) โดยไม่ต้องตั้งค่าอะไรที่รอบเลย
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(45)->toDateString(),
        ]);

        $quote = PaymentQuote::forBooking(
            $this->makeBooking(User::factory()->create(), $schedule, 3000)
        )['installment'];

        $this->assertTrue($quote['available']);
        $this->assertSame([2, 3], array_column($quote['options'], 'count'));
        $this->assertSame(3, $quote['default_count']);
        $this->assertSame(1000.0, $quote['options'][1]['per_amount']);
        $this->assertSame(15, $quote['options'][1]['interval_days']);
    }

    public function test_the_last_instalment_falls_due_before_departure_not_on_it(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(60)->toDateString(),
        ]);

        $quote = PaymentQuote::forBooking(
            $this->makeBooking(User::factory()->create(), $schedule, 3000)
        )['installment'];

        $closing = now('Asia/Bangkok')->addDays(60 - PaymentQuote::INSTALLMENT_LEAD_DAYS)->toDateString();

        $this->assertSame($closing, $quote['final_due_date']);
        foreach ($quote['options'] as $option) {
            $dueDates = $option['due_dates'];
            $this->assertCount($option['count'], $dueDates);
            $this->assertSame(now('Asia/Bangkok')->toDateString(), $dueDates[0]);
            $this->assertSame($closing, $dueDates[$option['count'] - 1]);
        }
    }

    public function test_installment_closes_when_the_trip_is_too_close_to_split_the_bill(): void
    {
        // เหลือ 20 วัน → ช่วงผ่อนจริง 5 วัน ซึ่งสั้นกว่าระยะห่างขั้นต่ำระหว่างงวด
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
        ]);

        $quote = PaymentQuote::forBooking(
            $this->makeBooking(User::factory()->create(), $schedule, 3000)
        )['installment'];

        $this->assertFalse($quote['available']);
        $this->assertSame(0, $quote['max_count']);
        $this->assertSame([], $quote['options']);
    }

    public function test_charging_an_installment_writes_the_due_dates_the_customer_was_shown(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(90)->toDateString(),
        ]);

        $user = User::factory()->create();
        $booking = $this->makeBooking($user, $schedule, 6000);
        $quoted = collect(PaymentQuote::installment($booking)['options'])
            ->firstWhere('count', 3);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'installment',
                'installment_count' => 3,
                'payment_method' => 'promptpay',
                'amount' => $quoted['per_amount'],
            ])
            ->assertOk();

        $booking->refresh()->load('installmentPayments');

        $this->assertSame(3, $booking->installment_count);
        $this->assertSame(
            $quoted['due_dates'],
            $booking->installmentPayments->sortBy('installment_no')
                ->map(fn ($installment) => $installment->due_date->toDateString())
                ->values()->all(),
        );
        $this->assertSame('paid', $booking->installmentPayments->firstWhere('installment_no', 1)->status);
    }

    public function test_more_instalments_than_the_remaining_time_allows_is_refused(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(45)->toDateString(),
        ]);

        $user = User::factory()->create();
        $booking = $this->makeBooking($user, $schedule, 3000);

        // เหลือที่ 3 งวด — ขอ 6 งวดต้องถูกปฏิเสธ ไม่ใช่ได้ตารางที่ครบกำหนดหลังไปแล้ว
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payments/charge', [
                'booking_ref' => $booking->booking_ref,
                'payment_type' => 'installment',
                'installment_count' => 6,
                'payment_method' => 'promptpay',
                'amount' => 500,
            ])
            ->assertStatus(422);

        $this->assertSame(0, $booking->fresh()->installmentPayments()->count());
    }

    public function test_schedule_payload_advertises_the_plan_without_any_admin_setup(): void
    {
        // หน้าทริปกับหน้าจองแอดมินมีแค่ "รอบ" ยังไม่มีการจอง จึงต้องอ่านจากตรงนี้ได้
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(60)->toDateString(),
        ]);

        $payload = (new TripScheduleResource($schedule))->toArray(request());

        $this->assertTrue($payload['installment_enabled']);
        $this->assertSame(4, $payload['installment_count']);
        $this->assertSame(15, $payload['installment_interval_days']);
        $this->assertSame(15, $payload['installment_lead_days']);
        $this->assertSame(
            now('Asia/Bangkok')->addDays(45)->toDateString(),
            $payload['installment_final_due_date'],
        );
    }

    public function test_a_round_that_is_too_close_advertises_no_plan(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
        ]);

        $payload = (new TripScheduleResource($schedule))->toArray(request());

        $this->assertFalse($payload['installment_enabled']);
        $this->assertSame(0, $payload['installment_count']);
        $this->assertNull($payload['installment_final_due_date']);
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

    public function test_the_chosen_payment_type_survives_until_the_customer_comes_back(): void
    {
        $schedule = $this->makeSchedule([
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 1000,
        ]);

        $user = User::factory()->create();
        $booking = $this->makeBooking($user, $schedule, 8000, passengers: 4);

        // ออก QR มัดจำไว้แล้วยังไม่จ่าย — หน้าจ่ายเงินต้องกลับมาที่ "มัดจำ" ได้ตอนเปิดใหม่
        app(BookingSettlementService::class)->record($booking, 'deposit');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.payment_type', 'deposit')
            ->assertJsonPath('data.payment_options.deposit.amount', 4000);
    }

    public function test_switching_back_to_full_clears_the_abandoned_deposit_plan(): void
    {
        $schedule = $this->makeSchedule([
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 1000,
        ]);

        $booking = $this->makeBooking(User::factory()->create(), $schedule, 8000, passengers: 4);
        $settlement = app(BookingSettlementService::class);

        $settlement->record($booking, 'deposit');
        $this->assertSame(4000.0, (float) $booking->fresh()->deposit_amount);

        // เปลี่ยนใจไปจ่ายเต็มจำนวน — ยอดมัดจำ/ยอดคงเหลือของแผนที่ทิ้งแล้วต้องไม่ค้าง
        $settlement->record($booking->fresh(), 'full');

        $booking->refresh();
        $this->assertSame('full', $booking->payment_type);
        $this->assertNull($booking->deposit_amount);
        $this->assertNull($booking->balance_amount);
        $this->assertNull($booking->balance_due_at);
    }

    public function test_switching_away_from_installment_drops_the_old_schedule(): void
    {
        $schedule = $this->makeSchedule([
            'installment_enabled' => true,
            'installment_count' => 3,
            'installment_interval_days' => 30,
        ]);

        $booking = $this->makeBooking(User::factory()->create(), $schedule, 9000);
        $settlement = app(BookingSettlementService::class);

        $settlement->record($booking, 'installment', ['installment_count' => 3]);
        $this->assertSame(3, $booking->fresh()->installmentPayments()->count());

        $settlement->record($booking->fresh()->load('schedule'), 'full');

        $booking->refresh();
        $this->assertSame('full', $booking->payment_type);
        $this->assertNull($booking->installment_count);
        $this->assertSame(0, $booking->installmentPayments()->count());
    }
}
