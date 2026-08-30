<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รายงานรายได้ (/admin/reports/revenue)
 *
 * เกณฑ์ที่ต้องถูก: ยอดขายนับเฉพาะใบที่ยืนยันแล้ว, ยอดค้างของใบผ่อนมาจากงวดจริง,
 * ใบที่แอดมินข้ามการชำระเงินไม่กลายเป็นลูกหนี้ค้าง และช่วงวันที่อ่านเป็นเวลาไทย
 */
class AdminRevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $trip = Trip::create([
            'title' => 'ทริปทดสอบ', 'slug' => 'report-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 20, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 20, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeBooking(array $attributes, ?Carbon $createdAt = null, int $passengers = 1): Booking
    {
        $booking = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 3000,
            'paid_amount' => 0,
            'payment_type' => 'full',
        ], $attributes));

        if ($createdAt) {
            $booking->forceFill(['created_at' => $createdAt])->save();
        }

        for ($i = 0; $i < $passengers; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Pax '.$i,
                'phone' => '08100000'.$i, 'email' => "pax{$i}-{$booking->id}@example.test",
            ]);
        }

        return $booking->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function report(array $params = []): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/revenue?'.http_build_query($params + [
                'from' => now('Asia/Bangkok')->subMonth()->toDateString(),
                'to' => now('Asia/Bangkok')->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->json('data');
    }

    public function test_pending_bookings_are_kept_out_of_sales_totals(): void
    {
        $this->makeBooking(['status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 3000]);
        $this->makeBooking(['status' => 'pending', 'total_amount' => 5000, 'paid_amount' => 0]);

        $summary = $this->report()['summary'];

        $this->assertEquals(1, $summary['bookings_count']);
        $this->assertEquals(3000.0, $summary['total_amount']);
        $this->assertEquals(3000.0, $summary['paid_amount']);
        $this->assertEquals(0.0, $summary['outstanding_amount']);
        $this->assertEquals(1, $summary['pending_bookings']);
        $this->assertEquals(5000.0, $summary['pending_amount']);
    }

    public function test_cancelled_bookings_are_excluded_entirely(): void
    {
        $this->makeBooking(['status' => 'cancelled', 'total_amount' => 4000, 'paid_amount' => 0]);

        $summary = $this->report()['summary'];

        $this->assertEquals(0, $summary['bookings_count']);
        $this->assertEquals(0.0, $summary['total_amount']);
        $this->assertEquals(0.0, $summary['pending_amount']);
    }

    public function test_deposit_booking_leaves_the_balance_outstanding(): void
    {
        $this->makeBooking([
            'status' => 'confirmed', 'payment_type' => 'deposit',
            'total_amount' => 3000, 'paid_amount' => 1000,
            'deposit_amount' => 1000, 'balance_amount' => 2000,
        ]);

        $summary = $this->report()['summary'];

        $this->assertEquals(3000.0, $summary['total_amount']);
        $this->assertEquals(1000.0, $summary['paid_amount']);
        $this->assertEquals(2000.0, $summary['outstanding_amount']);
        $this->assertEquals(0.0, $summary['installment_outstanding_amount']);
    }

    public function test_installment_outstanding_comes_from_the_unpaid_instalments(): void
    {
        // งวดที่แอดมินแก้ยอดแล้ว ผลรวมงวดจึงไม่เท่ากับ total_amount — ยอดค้างต้องยึดงวด
        $booking = $this->makeBooking([
            'status' => 'confirmed', 'payment_type' => 'installment',
            'total_amount' => 3000, 'paid_amount' => 1000, 'installment_count' => 3,
        ]);

        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 1, 'amount' => 1000, 'due_date' => now()->subDays(30)->toDateString(), 'status' => 'paid', 'paid_at' => now()]);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 800, 'due_date' => now()->toDateString(), 'status' => 'pending']);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 3, 'amount' => 700, 'due_date' => now()->addDays(30)->toDateString(), 'status' => 'pending']);

        $summary = $this->report()['summary'];

        $this->assertEquals(1500.0, $summary['outstanding_amount']);
        $this->assertEquals(1500.0, $summary['installment_outstanding_amount']);
    }

    public function test_admin_skipped_payment_is_waived_not_outstanding(): void
    {
        $this->makeBooking([
            'status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 0,
            'payment_method' => Booking::PAYMENT_METHOD_ADMIN_SKIP,
        ]);

        $summary = $this->report()['summary'];

        $this->assertEquals(3000.0, $summary['total_amount']);
        $this->assertEquals(0.0, $summary['paid_amount']);
        $this->assertEquals(0.0, $summary['outstanding_amount']);
        $this->assertEquals(3000.0, $summary['waived_amount']);
    }

    public function test_refunds_are_netted_out_of_the_money_received(): void
    {
        $this->makeBooking(['status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 3000]);
        $this->makeBooking([
            'status' => 'refunded', 'total_amount' => 5000, 'paid_amount' => 5000,
            'refund_amount' => 4000, 'refunded_at' => now(),
        ]);

        $summary = $this->report()['summary'];

        // ใบที่คืนเงินแล้วไม่ใช่ยอดขาย แต่เงินที่ยังเก็บไว้ (5000 − 4000) ยังอยู่ในเงินสุทธิ
        $this->assertEquals(3000.0, $summary['total_amount']);
        $this->assertEquals(1, $summary['refunded_bookings']);
        $this->assertEquals(4000.0, $summary['refunded_amount']);
        $this->assertEquals(4000.0, $summary['net_amount']);
    }

    public function test_sales_total_equals_paid_plus_outstanding_plus_waived(): void
    {
        $this->makeBooking(['status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 3000]);
        $this->makeBooking([
            'status' => 'confirmed', 'payment_type' => 'deposit',
            'total_amount' => 4000, 'paid_amount' => 1500, 'balance_amount' => 2500,
        ]);
        $this->makeBooking([
            'status' => 'confirmed', 'total_amount' => 2000, 'paid_amount' => 0,
            'payment_method' => Booking::PAYMENT_METHOD_ADMIN_SKIP,
        ]);

        $summary = $this->report()['summary'];

        $this->assertEquals(
            $summary['total_amount'],
            round($summary['paid_amount'] + $summary['outstanding_amount'] + $summary['waived_amount'], 2),
        );
        $this->assertEquals(9000.0, $summary['total_amount']);
        $this->assertEquals(2500.0, $summary['outstanding_amount']);
    }

    public function test_date_range_is_read_in_thai_time(): void
    {
        // 1 ส.ค. ตี 1 ตามเวลาไทย = 31 ก.ค. 18:00 UTC — เดิมตกไปนับเป็นของเดือนกรกฎาคม
        $this->makeBooking(
            ['status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 3000],
            Carbon::parse('2026-08-01 01:00', 'Asia/Bangkok')->utc(),
        );

        $august = $this->report(['from' => '2026-08-01', 'to' => '2026-08-31']);
        $this->assertEquals(3000.0, $august['summary']['total_amount']);
        $this->assertEquals('2026-08', $august['monthly'][0]['month']);

        $july = $this->report(['from' => '2026-07-01', 'to' => '2026-07-31']);
        $this->assertEquals(0.0, $july['summary']['total_amount']);
    }

    public function test_breakdowns_carry_the_same_numbers(): void
    {
        $this->makeBooking([
            'status' => 'confirmed', 'payment_type' => 'deposit',
            'total_amount' => 4000, 'paid_amount' => 1500, 'balance_amount' => 2500,
        ], passengers: 2);

        $data = $this->report();

        $this->assertEquals('deposit', $data['by_payment_type'][0]['payment_type']);
        $this->assertEquals(2500.0, $data['by_payment_type'][0]['outstanding_amount']);
        $this->assertEquals(2, $data['by_payment_type'][0]['passengers_count']);
        $this->assertEquals(2500.0, $data['by_trip'][0]['outstanding_amount']);
        $this->assertEquals('ทริปทดสอบ', $data['by_trip'][0]['trip']);
        $this->assertEquals(2500.0, $data['monthly'][0]['outstanding_amount']);
    }
}
