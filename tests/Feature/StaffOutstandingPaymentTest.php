<?php

namespace Tests\Feature;

use App\Mail\BalanceDueReminderMail;
use App\Mail\InstallmentDueReminderMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ยอดค้างชำระในเมนูงานสตาฟ — สตาฟเห็นเฉพาะรอบที่ตัวเองรับผิดชอบ
 * และส่งลิงก์ชำระเงินซ้ำให้ลูกค้าในรอบนั้นได้
 */
class StaffOutstandingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');
        config()->set('services.thaibulksms.enabled', false);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Trip', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonths(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
            'installment_enabled' => true, 'installment_count' => 3, 'installment_interval_days' => 30,
            'deposit_enabled' => true, 'deposit_type' => 'amount', 'deposit_amount' => 1000,
        ]);
    }

    private function assign(TripSchedule $schedule, ?User $user = null): void
    {
        $user ??= $this->staff;
        $schedule->staff()->attach($user->id, ['assigned_by' => $user->id]);
    }

    private function makeInstallmentBooking(TripSchedule $schedule, ?string $slipPath = null): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 1000,
            'payment_type' => 'installment', 'installment_count' => 3, 'installment_interval_days' => 30,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Inst Pax',
            'phone' => '0810000001', 'email' => 'inst@example.test',
        ]);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 1, 'amount' => 1000, 'due_date' => now()->subDays(30)->toDateString(), 'status' => 'paid', 'paid_at' => now()]);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 1000, 'due_date' => now()->toDateString(), 'status' => 'pending', 'slip_path' => $slipPath]);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 3, 'amount' => 1000, 'due_date' => now()->addDays(30)->toDateString(), 'status' => 'pending']);

        return $booking;
    }

    private function makeDepositBooking(TripSchedule $schedule): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 1000,
            'payment_type' => 'deposit', 'deposit_amount' => 1000, 'balance_amount' => 2000,
            'balance_due_at' => now()->addDays(10)->toDateString(),
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Ms.', 'name' => 'Dep Pax',
            'phone' => '0810000002', 'email' => 'dep@example.test',
        ]);

        return $booking;
    }

    public function test_assigned_staff_sees_outstanding_rows_with_pay_url(): void
    {
        $schedule = $this->makeSchedule();
        $this->assign($schedule);
        $inst = $this->makeInstallmentBooking($schedule);
        $dep = $this->makeDepositBooking($schedule);

        $response = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/outstanding");

        $response->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.total_due', 3000)
            ->assertJsonPath('data.schedule.id', $schedule->id)
            ->assertJsonFragment(['booking_ref' => $inst->booking_ref])
            ->assertJsonFragment(['booking_ref' => $dep->booking_ref]);

        // ทุกแถวต้องมีลิงก์จ่ายเงินให้ลูกค้าสแกน — คือหัวใจของหน้านี้
        foreach ($response->json('data.items') as $row) {
            $this->assertStringContainsString('/pay/', $row['pay_url']);
        }
        $this->assertNotNull($inst->fresh()->payment_token);
    }

    public function test_outstanding_is_scoped_to_the_requested_schedule(): void
    {
        $mine = $this->makeSchedule();
        $other = $this->makeSchedule();
        $this->assign($mine);
        $this->assign($other);

        $inst = $this->makeInstallmentBooking($mine);
        $this->makeDepositBooking($other);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$mine->id}/outstanding")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonFragment(['booking_ref' => $inst->booking_ref]);
    }

    public function test_slip_pending_flags_rows_awaiting_verification(): void
    {
        $schedule = $this->makeSchedule();
        $this->assign($schedule);
        $this->makeInstallmentBooking($schedule, slipPath: 'slips/pending.jpg');

        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/outstanding")
            ->assertOk()
            ->assertJsonPath('data.items.0.slip_pending', true);
    }

    public function test_slip_pending_is_false_when_no_slip_uploaded(): void
    {
        $schedule = $this->makeSchedule();
        $this->assign($schedule);
        $this->makeDepositBooking($schedule);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/outstanding")
            ->assertOk()
            ->assertJsonPath('data.items.0.slip_pending', false);
    }

    public function test_installment_row_carries_every_instalment_with_totals(): void
    {
        $schedule = $this->makeSchedule();
        $this->assign($schedule);
        $this->makeInstallmentBooking($schedule);

        $response = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/outstanding");

        $response->assertOk();
        $row = $response->json('data.items.0');

        // งวดที่ 1 จ่ายแล้ว, 2 และ 3 ยังค้าง → เหลือ 2000 จ่ายแล้ว 1000
        $this->assertSame(3, $row['installment_count']);
        $this->assertSame(1, $row['paid_count']);
        $this->assertSame(1000.0, (float) $row['paid_total']);
        $this->assertSame(2000.0, (float) $row['remaining_total']);
        // amount_due ยังเป็นงวดถัดไปงวดเดียว (พฤติกรรมเดิมที่ admin ใช้อยู่)
        $this->assertSame(1000.0, (float) $row['amount_due']);

        $this->assertCount(3, $row['schedule']);
        $this->assertSame('paid', $row['schedule'][0]['status']);
        $this->assertNotNull($row['schedule'][0]['paid_at']);
        $this->assertSame('pending', $row['schedule'][1]['status']);
        $this->assertSame(2, $row['schedule'][1]['installment_no']);
        $this->assertSame(1000.0, (float) $row['schedule'][2]['amount']);
    }

    public function test_installment_schedule_is_ordered_and_flags_overdue(): void
    {
        $schedule = $this->makeSchedule();
        $this->assign($schedule);
        $booking = $this->makeInstallmentBooking($schedule);

        // ดันงวดที่ 2 ให้เลยกำหนด — ต้องติดธง overdue เฉพาะงวดนั้น
        InstallmentPayment::where('booking_id', $booking->id)
            ->where('installment_no', 2)
            ->update(['due_date' => now()->subDays(3)->toDateString()]);

        $rows = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/outstanding")
            ->assertOk()
            ->json('data.items.0.schedule');

        $this->assertSame([1, 2, 3], array_column($rows, 'installment_no'));
        $this->assertFalse($rows[0]['overdue'], 'งวดที่จ่ายแล้วต้องไม่ถือว่าเลยกำหนด');
        $this->assertTrue($rows[1]['overdue']);
        $this->assertFalse($rows[2]['overdue']);
    }

    public function test_deposit_booking_is_rendered_as_two_step_schedule(): void
    {
        $schedule = $this->makeSchedule();
        $this->assign($schedule);
        $this->makeDepositBooking($schedule);

        $row = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/outstanding")
            ->assertOk()
            ->json('data.items.0');

        // มัดจำถูกทำให้เป็นไทม์ไลน์ 2 ขั้น เพื่อให้แอปเรนเดอร์เหมือนผ่อนชำระ
        $this->assertCount(2, $row['schedule']);
        $this->assertSame('มัดจำ', $row['schedule'][0]['label']);
        $this->assertSame('paid', $row['schedule'][0]['status']);
        $this->assertSame(1000.0, (float) $row['schedule'][0]['amount']);
        $this->assertSame('ยอดส่วนที่เหลือ', $row['schedule'][1]['label']);
        $this->assertSame(2000.0, (float) $row['schedule'][1]['amount']);
        $this->assertSame(2000.0, (float) $row['remaining_total']);
        $this->assertSame(3000.0, (float) $row['total_amount']);
    }

    public function test_staff_not_assigned_to_schedule_is_forbidden(): void
    {
        $schedule = $this->makeSchedule();
        $this->assign($schedule, User::factory()->create());
        $this->makeInstallmentBooking($schedule);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/outstanding")
            ->assertForbidden();
    }

    public function test_customer_without_staff_role_is_forbidden(): void
    {
        $schedule = $this->makeSchedule();
        $this->assign($schedule);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/outstanding")
            ->assertForbidden();
    }

    public function test_staff_can_resend_payment_link(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule();
        $this->assign($schedule);
        $inst = $this->makeInstallmentBooking($schedule);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/outstanding/{$inst->booking_ref}/send-link", [
                'channels' => ['email'],
            ])
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $inst->booking_ref);

        Mail::assertQueued(InstallmentDueReminderMail::class);
    }

    public function test_staff_can_resend_balance_link(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule();
        $this->assign($schedule);
        $dep = $this->makeDepositBooking($schedule);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/outstanding/{$dep->booking_ref}/send-link", [
                'channels' => ['email'],
            ])
            ->assertOk();

        Mail::assertQueued(BalanceDueReminderMail::class);
    }

    public function test_staff_cannot_send_link_for_booking_outside_their_schedule(): void
    {
        Mail::fake();
        $mine = $this->makeSchedule();
        $other = $this->makeSchedule();
        $this->assign($mine);
        $foreign = $this->makeInstallmentBooking($other);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$mine->id}/outstanding/{$foreign->booking_ref}/send-link", [
                'channels' => ['email'],
            ])
            ->assertNotFound();

        Mail::assertNothingQueued();
    }
}
