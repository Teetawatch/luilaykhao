<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInstallmentOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeSchedule(string $title = 'Installment Trip'): TripSchedule
    {
        $trip = Trip::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 3000,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonths(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $installments
     */
    private function makeInstallmentBooking(
        TripSchedule $schedule,
        array $installments,
        array $attributes = [],
        ?User $customer = null,
    ): Booking {
        $customer ??= User::factory()->create(['name' => 'สมชาย ใจดี', 'phone' => '0812345678']);

        $booking = Booking::create(array_merge([
            'booking_ref' => 'LLK-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -4)),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 6000,
            'paid_amount' => 0,
            'payment_method' => 'promptpay',
            'payment_type' => 'installment',
            'installment_count' => count($installments),
        ], $attributes));

        foreach ($installments as $index => $row) {
            InstallmentPayment::create(array_merge([
                'booking_id' => $booking->id,
                'installment_no' => $index + 1,
                'amount' => 3000,
                'status' => 'pending',
            ], $row));
        }

        return $booking->fresh(['installmentPayments']);
    }

    public function test_lists_installment_bookings_with_progress_and_amounts(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->makeInstallmentBooking($schedule, [
            [
                'amount' => 3000,
                'due_date' => now('Asia/Bangkok')->subDays(20)->toDateString(),
                'status' => 'paid',
                'paid_at' => now()->subDays(20),
                'payment_method' => 'promptpay',
                'payment_ref' => 'PAY-INST-A',
                'slip_path' => 'slips/2026/08/one.jpg',
                'slip_ocr_status' => 'verified',
            ],
            [
                'amount' => 3000,
                'due_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            ],
        ], ['paid_amount' => 3000]);

        $row = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/admin/installments')
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame($booking->booking_ref, $row['booking_ref']);
        $this->assertSame('สมชาย ใจดี', $row['customer_name']);
        $this->assertSame('Installment Trip', $row['trip_title']);
        $this->assertSame(2, $row['installment_count']);
        $this->assertSame(1, $row['paid_count']);
        $this->assertSame(1, $row['remaining_count']);
        $this->assertSame(50, $row['progress_percent']);
        $this->assertEquals(3000, $row['paid_amount']);
        $this->assertEquals(3000, $row['outstanding_amount']);
        $this->assertFalse($row['is_complete']);

        $this->assertSame(2, $row['next_due']['installment_no']);
        $this->assertSame(10, $row['next_due']['days_until_due']);
        $this->assertFalse($row['next_due']['is_overdue']);

        $this->assertCount(2, $row['installments']);
        $this->assertSame('paid', $row['installments'][0]['display_status']);
        $this->assertTrue($row['installments'][0]['has_slip']);
        $this->assertNotNull($row['installments'][0]['slip_url']);
        $this->assertSame('PAY-INST-A', $row['installments'][0]['payment_ref']);
        $this->assertFalse($row['installments'][0]['needs_review']);
        $this->assertSame('pending', $row['installments'][1]['display_status']);
        $this->assertFalse($row['installments'][1]['has_slip']);
        $this->assertNull($row['installments'][1]['slip_url']);
    }

    public function test_marks_a_past_due_unpaid_installment_as_overdue(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeInstallmentBooking($schedule, [
            ['due_date' => now('Asia/Bangkok')->subDays(30)->toDateString(), 'status' => 'paid', 'paid_at' => now()],
            // ยังเป็น pending ในฐานข้อมูล แต่เลยกำหนดแล้ว — ต้องถูกคำนวณเป็น overdue
            ['due_date' => now('Asia/Bangkok')->subDays(3)->toDateString()],
        ]);

        $data = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/admin/installments')
            ->assertOk()
            ->json('data');

        $row = $data['items'][0];

        $this->assertSame(1, $row['overdue_count']);
        $this->assertEquals(3000, $row['overdue_amount']);
        $this->assertTrue($row['next_due']['is_overdue']);
        $this->assertSame(-3, $row['next_due']['days_until_due']);
        $this->assertSame('overdue', $row['installments'][1]['display_status']);

        $this->assertSame(1, $data['summary']['overdue_bookings']);
        $this->assertEquals(3000, $data['summary']['overdue_amount']);
        $this->assertEquals(3000, $data['summary']['collected_amount']);
        $this->assertEquals(3000, $data['summary']['outstanding_amount']);
    }

    public function test_exposes_slip_ocr_detail_and_flags_slips_needing_review(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeInstallmentBooking($schedule, [
            [
                'due_date' => now('Asia/Bangkok')->subDays(5)->toDateString(),
                'status' => 'paid',
                'paid_at' => now(),
                'slip_path' => 'slips/2026/08/mismatch.jpg',
                'slip_ocr_status' => 'failed',
                'slip_ocr_result' => [
                    'status' => 'success',
                    'amount' => 2500,
                    'datetime' => '2026-08-20 10:15:00',
                    'bank' => 'กสิกรไทย',
                    'transaction_id' => 'TX-9',
                ],
            ],
            ['due_date' => now('Asia/Bangkok')->addDays(20)->toDateString()],
        ]);

        $data = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/admin/installments')
            ->assertOk()
            ->json('data');

        $installment = $data['items'][0]['installments'][0];

        $this->assertSame('failed', $installment['slip_ocr_status']);
        $this->assertTrue($installment['needs_review']);
        $this->assertEquals(2500, $installment['slip_ocr']['amount']);
        $this->assertEquals(-500, $installment['slip_ocr']['amount_diff']);
        $this->assertSame('กสิกรไทย', $installment['slip_ocr']['bank']);
        $this->assertSame('TX-9', $installment['slip_ocr']['transaction_id']);

        $this->assertSame(1, $data['items'][0]['needs_review_count']);
        $this->assertSame(1, $data['summary']['needs_review_bookings']);
    }

    public function test_reads_a_double_encoded_ocr_result_instead_of_crashing(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeInstallmentBooking($schedule, [
            [
                'due_date' => now('Asia/Bangkok')->subDays(5)->toDateString(),
                'status' => 'paid',
                'paid_at' => now(),
                'slip_path' => 'slips/2026/08/double.jpg',
                'slip_ocr_status' => 'failed',
                // แถวจริงบน production บางแถวเก็บ JSON ซ้อนสองชั้น: ส่งสตริงเข้าคอลัมน์ที่
                // cast เป็น array มันจึงถูก encode ให้อีกชั้น พออ่านกลับได้สตริง ไม่ใช่ array
                'slip_ocr_result' => json_encode([
                    'status' => 'success',
                    'amount' => 2800,
                    'bank' => 'ไทยพาณิชย์',
                ]),
            ],
            ['due_date' => now('Asia/Bangkok')->addDays(20)->toDateString()],
        ]);

        $installment = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/admin/installments')
            ->assertOk()
            ->json('data.items.0.installments.0');

        $this->assertEquals(2800, $installment['slip_ocr']['amount']);
        $this->assertEquals(-200, $installment['slip_ocr']['amount_diff']);
        $this->assertSame('ไทยพาณิชย์', $installment['slip_ocr']['bank']);
    }

    public function test_a_slip_ocr_result_that_is_not_readable_is_skipped_quietly(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeInstallmentBooking($schedule, [
            [
                'due_date' => now('Asia/Bangkok')->subDays(5)->toDateString(),
                'status' => 'paid',
                'paid_at' => now(),
                'slip_path' => 'slips/2026/08/junk.jpg',
                'slip_ocr_status' => 'failed',
                'slip_ocr_result' => 'ตรวจไม่ผ่าน',
            ],
            ['due_date' => now('Asia/Bangkok')->addDays(20)->toDateString()],
        ]);

        $installment = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/admin/installments')
            ->assertOk()
            ->json('data.items.0.installments.0');

        $this->assertNull($installment['slip_ocr']);
        // สลิปยังต้องเปิดดูได้และยังต้องถูกนับว่ารอตรวจอยู่
        $this->assertNotNull($installment['slip_url']);
        $this->assertTrue($installment['needs_review']);
    }

    public function test_falls_back_to_the_booking_slip_for_the_first_installment(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeInstallmentBooking($schedule, [
            // การจองรุ่นเก่า: งวดแรกจ่ายพร้อมจอง สลิปถูกเก็บไว้ที่ booking เท่านั้น
            ['due_date' => now('Asia/Bangkok')->subDays(10)->toDateString(), 'status' => 'paid', 'paid_at' => now()],
            ['due_date' => now('Asia/Bangkok')->addDays(20)->toDateString()],
        ], [
            'slip_path' => 'slips/2026/08/booking-level.jpg',
            'slip_ocr_status' => 'verified',
        ]);

        $installments = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/admin/installments')
            ->assertOk()
            ->json('data.items.0.installments');

        $this->assertTrue($installments[0]['has_slip']);
        $this->assertNotNull($installments[0]['slip_url']);
        $this->assertSame('verified', $installments[0]['slip_ocr_status']);
        // งวดที่ 2 ต้องไม่ยืมสลิปของ booking มาแสดง
        $this->assertFalse($installments[1]['has_slip']);
    }

    public function test_filters_by_state_and_search(): void
    {
        $schedule = $this->makeSchedule();

        $overdue = $this->makeInstallmentBooking($schedule, [
            ['due_date' => now('Asia/Bangkok')->subDays(40)->toDateString(), 'status' => 'paid', 'paid_at' => now()],
            ['due_date' => now('Asia/Bangkok')->subDays(2)->toDateString()],
        ], [], User::factory()->create(['name' => 'มานี รักเรียน', 'phone' => '0899999999']));

        $completed = $this->makeInstallmentBooking($schedule, [
            ['due_date' => now('Asia/Bangkok')->subDays(40)->toDateString(), 'status' => 'paid', 'paid_at' => now()],
            ['due_date' => now('Asia/Bangkok')->subDays(10)->toDateString(), 'status' => 'paid', 'paid_at' => now()],
        ], ['paid_amount' => 6000], User::factory()->create(['name' => 'ปิติ ชอบเดิน']));

        $admin = $this->makeAdmin();

        $overdueRefs = $this->actingAs($admin)
            ->getJson('/api/v1/admin/installments?filter=overdue')
            ->assertOk()
            ->json('data.items.*.booking_ref');
        $this->assertSame([$overdue->booking_ref], $overdueRefs);

        $completedRefs = $this->actingAs($admin)
            ->getJson('/api/v1/admin/installments?filter=completed')
            ->assertOk()
            ->json('data.items.*.booking_ref');
        $this->assertSame([$completed->booking_ref], $completedRefs);

        $searchRefs = $this->actingAs($admin)
            ->getJson('/api/v1/admin/installments?search=0899999999')
            ->assertOk()
            ->json('data.items.*.booking_ref');
        $this->assertSame([$overdue->booking_ref], $searchRefs);

        // การ์ดสรุปด้านบนต้องนับทั้งชุด ไม่ลดลงตามตัวกรอง
        $summary = $this->actingAs($admin)
            ->getJson('/api/v1/admin/installments?filter=completed')
            ->assertOk()
            ->json('data.summary');
        $this->assertSame(2, $summary['bookings']);
        $this->assertSame(1, $summary['completed_bookings']);
        $this->assertSame(1, $summary['active_bookings']);
    }

    public function test_cancelled_bookings_are_hidden_unless_asked_for(): void
    {
        $schedule = $this->makeSchedule();
        $cancelled = $this->makeInstallmentBooking($schedule, [
            ['due_date' => now('Asia/Bangkok')->subDays(5)->toDateString(), 'status' => 'paid', 'paid_at' => now()],
            ['due_date' => now('Asia/Bangkok')->addDays(5)->toDateString()],
        ], ['status' => 'cancelled', 'cancelled_at' => now()]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/installments')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $refs = $this->actingAs($admin)
            ->getJson('/api/v1/admin/installments?include_cancelled=1')
            ->assertOk()
            ->json('data.items.*.booking_ref');

        $this->assertSame([$cancelled->booking_ref], $refs);
    }

    public function test_non_installment_bookings_are_excluded(): void
    {
        $schedule = $this->makeSchedule();

        Booking::create([
            'booking_ref' => 'LLK-FULL-0001',
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'payment_type' => 'full',
            'paid_at' => now(),
        ]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/admin/installments')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_show_returns_one_booking_with_fresh_slip_urls(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->makeInstallmentBooking($schedule, [
            [
                'due_date' => now('Asia/Bangkok')->subDays(5)->toDateString(),
                'status' => 'paid',
                'paid_at' => now(),
                'slip_path' => 'slips/2026/08/detail.jpg',
                'slip_ocr_status' => 'pending',
            ],
            ['due_date' => now('Asia/Bangkok')->addDays(25)->toDateString()],
        ]);

        $row = $this->actingAs($this->makeAdmin())
            ->getJson("/api/v1/admin/installments/{$booking->booking_ref}")
            ->assertOk()
            ->json('data');

        $this->assertSame($booking->booking_ref, $row['booking_ref']);
        $this->assertNotNull($row['installments'][0]['slip_url']);
        $this->assertTrue($row['installments'][0]['needs_review']);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/admin/installments/LLK-NOPE-0000')
            ->assertNotFound();
    }

    public function test_endpoint_requires_admin(): void
    {
        $this->getJson('/api/v1/admin/installments')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/admin/installments')
            ->assertForbidden();
    }
}
