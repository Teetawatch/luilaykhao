<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ScheduleExpense;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * โหมดบัญชีเข้มงวด — ข้อบังคับที่ทำให้ตัวเลขกำไรเชื่อถือได้
 *
 * ชุดนี้รันด้วยค่าตั้งต้นจริงของระบบ (strict = เปิด) ต่างจาก AdminFinanceTest
 * ที่ปิดโหมดไว้เพื่อทดสอบคณิตศาสตร์ของบัญชีล้วน ๆ
 */
class ScheduleFinanceStrictTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    private Trip $trip;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('operator');
        Role::findOrCreate('finance');
        Role::findOrCreate('staff');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('operator');

        $this->trip = Trip::create([
            'title' => 'เขาหลวง', 'slug' => 'strict-'.uniqid(), 'type' => 'trekking',
            'location' => 'นครศรีธรรมราช', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 5000, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => now()->subDays(5)->toDateString(),
            'return_date' => now()->subDays(3)->toDateString(),
            'total_seats' => 12, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'completed',
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/admin/finance/schedules/{$this->schedule->id}".$suffix;
    }

    private function book(float $total, float $paid): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => $total,
            'paid_amount' => $paid,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'ผู้เดินทาง '.$booking->id,
            'phone' => '0800000000',
        ]);

        return $booking;
    }

    private function expense(array $attributes = []): ScheduleExpense
    {
        return ScheduleExpense::create([
            'schedule_id' => $this->schedule->id,
            'kind' => ScheduleExpense::KIND_EXPENSE,
            'category' => 'fuel',
            'name' => 'ค่าน้ำมัน',
            'amount' => 800,
            'created_by' => $this->admin->id,
            ...$attributes,
        ]);
    }

    // ─── ข้อบังคับตอนบันทึก ─────────────────────────────────────

    public function test_expense_without_a_category_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/expenses'), ['name' => 'ค่าอะไรสักอย่าง', 'amount' => 300])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ต้องระบุหมวดของรายการนี้ก่อนบันทึก');

        $this->assertDatabaseCount('schedule_expenses', 0);
    }

    public function test_expense_above_the_threshold_needs_a_slip(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/expenses'), [
                'name' => 'ค่าที่พัก', 'amount' => 4000, 'category' => 'accommodation',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'รายจ่ายเกิน 1,000 บาท ต้องแนบสลิป/ใบเสร็จ');
    }

    public function test_expense_above_the_threshold_passes_with_a_slip(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $this->actingAs($this->admin, 'sanctum')
            ->post($this->url('/expenses'), [
                'name' => 'ค่าที่พัก', 'amount' => 4000, 'category' => 'accommodation',
                'slip' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.expense.has_slip', true);
    }

    public function test_income_never_needs_a_slip(): void
    {
        // เงินที่รับเข้ามาหน้างานไม่มีใบเสร็จให้ถ่าย — บังคับเฉพาะฝั่งจ่าย
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/expenses'), [
                'kind' => 'income', 'name' => 'เก็บเงินหน้างาน', 'amount' => 9000, 'category' => 'onsite_payment',
            ])
            ->assertCreated();
    }

    // ─── ปิดงบ ──────────────────────────────────────────────────

    public function test_close_is_blocked_while_a_booking_is_unpaid(): void
    {
        $this->expense();
        $this->book(total: 5000, paid: 2000);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/close-check'))
            ->assertOk()
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.blockers.0.code', 'outstanding');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/close'))
            ->assertStatus(422);

        $this->assertNull($this->schedule->fresh()->finance_closed_at);
    }

    public function test_close_is_blocked_when_no_expense_was_recorded(): void
    {
        $this->book(total: 5000, paid: 5000);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/close-check'))
            ->assertOk()
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.blockers.0.code', 'no_expenses');
    }

    public function test_close_is_blocked_when_a_big_expense_has_no_slip(): void
    {
        // แถวนี้เข้าฐานตรง ๆ เลียนแบบข้อมูลเก่าก่อนมีข้อบังคับ
        $this->expense(['amount' => 5000]);
        $this->book(total: 5000, paid: 5000);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/close-check'))
            ->assertOk()
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.blockers.0.code', 'missing_slip');
    }

    public function test_closing_locks_every_money_edit(): void
    {
        $this->expense();
        $this->book(total: 5000, paid: 5000);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/close'), ['note' => 'ปิดงบประจำเดือน'])
            ->assertOk()
            ->assertJsonPath('data.summary.is_closed', true);

        $expense = ScheduleExpense::where('schedule_id', $this->schedule->id)->first();

        // แอดมินที่ไม่ให้เหตุผลก็แก้ไม่ได้
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/expenses'), ['name' => 'ตกหล่น', 'amount' => 100, 'category' => 'other'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'รอบนี้ปิดงบแล้ว การแก้ไขต้องระบุเหตุผลกำกับทุกครั้ง');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson($this->url("/expenses/{$expense->id}"))
            ->assertStatus(422);

        // มีเหตุผลแล้วผ่าน และเหตุผลถูกเก็บลงปูม
        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->url("/expenses/{$expense->id}"), ['amount' => 950, 'reason' => 'ใบเสร็จมาทีหลัง'])
            ->assertOk();

        $this->assertDatabaseHas('schedule_expense_audits', [
            'schedule_id' => $this->schedule->id,
            'action' => 'updated',
            'reason' => 'ใบเสร็จมาทีหลัง',
        ]);
    }

    public function test_only_an_admin_may_edit_a_closed_round_or_reopen_it(): void
    {
        $this->operator->assignRole('finance');
        $this->expense();
        $this->book(total: 5000, paid: 5000);

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/close'))->assertOk();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/expenses'), [
                'name' => 'แอบเพิ่ม', 'amount' => 100, 'category' => 'other', 'reason' => 'ขอแก้หน่อย',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'รอบนี้ปิดงบแล้ว แก้ไขได้เฉพาะแอดมินเท่านั้น');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/reopen'), ['reason' => 'อยากแก้'])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/reopen'), ['reason' => 'ตกหล่นค่าอาหารมื้อเย็น'])
            ->assertOk()
            ->assertJsonPath('data.summary.is_closed', false);

        $this->assertDatabaseHas('schedule_expense_audits', [
            'schedule_id' => $this->schedule->id, 'action' => 'reopened',
        ]);
    }

    public function test_a_closed_round_refuses_staff_entries_from_the_app(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->schedule->staff()->attach($staff->id);

        $this->expense();
        $this->book(total: 5000, paid: 5000);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/close'))->assertOk();

        $this->actingAs($staff)
            ->postJson("/api/v1/staff/schedules/{$this->schedule->id}/ledger", [
                'kind' => 'expense', 'name' => 'ค่ารถ', 'amount' => 300, 'category' => 'transport',
            ])
            ->assertStatus(422);
    }

    // ─── ปูมการแก้ไข ────────────────────────────────────────────

    public function test_every_money_change_lands_in_the_audit_log(): void
    {
        $expense = $this->expense();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->url("/expenses/{$expense->id}"), ['amount' => 950])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson($this->url("/expenses/{$expense->id}"))
            ->assertOk();

        $audits = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/audits'))
            ->assertOk()
            ->json('data.audits');

        $this->assertSame(['deleted', 'updated'], array_column($audits, 'action'));
        $this->assertEquals(800, $audits[1]['before']['amount']);
        $this->assertEquals(950, $audits[1]['after']['amount']);

        // ลบแล้วรายการหายจากยอด แต่แถวยังอยู่ให้ตรวจสอบย้อนหลังได้
        $this->assertSoftDeleted('schedule_expenses', ['id' => $expense->id]);
    }

    // ─── ตัวเลขที่ต้องมี ────────────────────────────────────────

    public function test_summary_reports_receivables_cost_per_head_and_break_even(): void
    {
        $this->book(total: 5000, paid: 5000);
        $this->book(total: 5000, paid: 2000);
        $this->expense(['amount' => 900]);

        $summary = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/expenses'))
            ->assertOk()
            ->json('data.summary');

        $this->assertEquals(10000, $summary['booked_total']);
        $this->assertEquals(7000, $summary['paid_revenue']);
        $this->assertEquals(3000, $summary['outstanding']);
        $this->assertSame(1, $summary['unpaid_bookings_count']);
        // กำไรที่รับจริง 7000 − 900 = 6100 และถ้าเก็บครบอีก 3000 = 9100
        $this->assertEquals(6100, $summary['profit']);
        $this->assertEquals(9100, $summary['potential_profit']);
        // ผู้เดินทาง 2 คน → ต้นทุนหัวละ 450, รายรับหัวละ 3500 → คุ้มทุนที่ 1 ที่นั่ง
        $this->assertSame(2, $summary['passengers_count']);
        $this->assertEquals(450, $summary['cost_per_pax']);
        $this->assertSame(1, $summary['break_even_pax']);
    }

    public function test_budget_is_tracked_against_actual_spend(): void
    {
        $this->expense(['amount' => 900]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->url('/budget'), ['finance_budget' => 2000])
            ->assertOk()
            ->assertJsonPath('data.summary.budget', 2000)
            ->assertJsonPath('data.summary.budget_variance', 1100)
            ->assertJsonPath('data.summary.over_budget', false);

        $this->expense(['amount' => 900, 'name' => 'ค่าอาหาร', 'category' => 'food']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->url('/budget'), ['finance_budget' => 1500])
            ->assertOk()
            ->assertJsonPath('data.summary.over_budget', true)
            ->assertJsonPath('data.summary.budget_variance', -300);
    }

    public function test_staff_cost_is_generated_from_the_day_rate(): void
    {
        $guide = User::factory()->create(['name' => 'พี่ไกด์']);
        $guide->assignRole('staff');
        $guide->forceFill(['staff_day_rate' => 1000])->save();
        $this->schedule->staff()->attach($guide->id);

        // ไม่ได้ตั้งเรต — ต้องถูกข้าม ไม่ใช่ลงเป็นศูนย์
        $helper = User::factory()->create(['name' => 'น้องผู้ช่วย']);
        $helper->assignRole('staff');
        $this->schedule->staff()->attach($helper->id);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/staff-cost'))
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped', 1)
            // ทริป 3 วัน (ไป-กลับนับหัวท้าย) × 1000
            ->assertJsonPath('data.total', 3000);

        // กดซ้ำต้องไม่ลงซ้ำ
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/staff-cost'))
            ->assertOk()
            ->assertJsonPath('data.created', 0);
    }

    // ─── สิทธิ์การเข้าถึง ───────────────────────────────────────

    public function test_an_operator_without_finance_access_cannot_see_the_numbers(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/admin/finance/trips')
            ->assertStatus(403);

        $this->operator->assignRole('finance');

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/admin/finance/trips')
            ->assertOk();
    }

    public function test_monthly_dashboard_buckets_rounds_by_departure_month(): void
    {
        $this->book(total: 5000, paid: 5000);
        $this->expense(['amount' => 900]);

        $months = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/dashboard')
            ->assertOk()
            ->json('data.months');

        $this->assertCount(1, $months);
        $this->assertSame($this->schedule->departure_date->format('Y-m'), $months[0]['month']);
        $this->assertEquals(5000, $months[0]['paid_revenue']);
        $this->assertEquals(4100, $months[0]['profit']);
    }

    public function test_csv_export_carries_the_thai_bom_and_every_line_item(): void
    {
        $this->expense(['amount' => 900, 'name' => 'ค่าน้ำมันขาไป']);

        $body = $this->actingAs($this->admin, 'sanctum')
            ->get($this->url('/export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('ค่าน้ำมันขาไป', $body);
        $this->assertStringContainsString('ค่าใช้จ่ายรวม', $body);
    }
}
