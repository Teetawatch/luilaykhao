<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ExpenseTemplate;
use App\Models\ScheduleExpense;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ScheduleFinanceService;
use App\Support\MediaDisk;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * เงินที่สตาฟต้องจ่ายหน้างาน — รายการประจำที่แอดมินติ๊ก "ให้สตาฟเห็น"
 * คูณจำนวนคนของรอบ แล้วสตาฟกดจ่ายลงสมุดบัญชีได้โดยไม่ซ้ำกับปุ่มของแอดมิน
 */
class StaffFeeTest extends TestCase
{
    use RefreshDatabase;

    private Trip $trip;

    private TripSchedule $schedule;

    private User $staff;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('staff');
        Role::findOrCreate('admin');
        Setting::put(SiteSettings::KEY, ['finance_strict_mode' => false]);

        $this->trip = Trip::create([
            'title' => 'หลวงพระบาง',
            'slug' => 'fee-trip-'.uniqid(),
            'type' => 'tour',
            'location' => 'ลาว',
            'difficulty' => 'easy',
            'duration_days' => 3,
            'max_participants' => 15,
            'price_per_person' => 6900,
            'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => now()->addDays(3)->toDateString(),
            'return_date' => now()->addDays(5)->toDateString(),
            'total_seats' => 15,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $this->staff = User::factory()->create(['name' => 'สตาฟบอย']);
        $this->staff->assignRole('staff');
        $this->schedule->staff()->attach($this->staff->id);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/staff/schedules/{$this->schedule->id}/fees".$suffix;
    }

    /** @return list<BookingPassenger> */
    private function book(int $count, string $status = 'confirmed'): array
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 6900 * $count,
        ]);

        $passengers = [];
        for ($i = 0; $i < $count; $i++) {
            $passengers[] = BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => 'Mr.',
                'name' => 'ผู้โดยสาร '.$i,
                'phone' => '081111111'.$i,
            ]);
        }

        return $passengers;
    }

    private function fee(array $attrs = []): ExpenseTemplate
    {
        return ExpenseTemplate::create($attrs + [
            'trip_id' => $this->trip->id,
            'name' => 'ค่าผ่านด่านลาว',
            'category' => 'ticket',
            'default_amount' => 300,
            'amount_basis' => ExpenseTemplate::BASIS_PER_PERSON,
            'staff_visible' => true,
            'staff_note' => 'จ่ายที่ด่านเป็นเงินกีบ',
        ]);
    }

    public function test_per_head_fees_multiply_by_the_rounds_headcount(): void
    {
        $this->book(3);
        $this->book(2);
        $this->book(4, 'cancelled'); // ยกเลิกแล้วไม่นับ

        $this->fee();
        $this->fee(['name' => 'ค่าธรรมเนียมเข้าพื้นที่', 'default_amount' => 50, 'amount_basis' => ExpenseTemplate::BASIS_PER_TRAVELLER]);
        $this->fee(['name' => 'ค่าจอดรถ', 'default_amount' => 200, 'amount_basis' => ExpenseTemplate::BASIS_FLAT]);

        $this->actingAs($this->staff)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.headcount.travellers', 5)
            ->assertJsonPath('data.headcount.staff', 1)
            // 300 × (5 + 1) / 50 × 5 / 200 เหมา
            ->assertJsonPath('data.items.0.amount', 1800)
            ->assertJsonPath('data.items.0.heads', 6)
            ->assertJsonPath('data.items.0.rule_label', '300 บาท × 6 คน (ลูกทริป 5 + ทีมงาน 1)')
            ->assertJsonPath('data.items.0.note', 'จ่ายที่ด่านเป็นเงินกีบ')
            ->assertJsonPath('data.items.1.amount', 250)
            ->assertJsonPath('data.items.1.rule_label', '50 บาท × 5 คน (ลูกทริป)')
            ->assertJsonPath('data.items.2.amount', 200)
            ->assertJsonPath('data.items.2.heads', null)
            ->assertJsonPath('data.summary.expected_total', 2250)
            ->assertJsonPath('data.summary.remaining_total', 2250);
    }

    public function test_passengers_who_said_they_are_not_going_are_not_counted(): void
    {
        [$a, $b, $c] = $this->book(3);
        $b->forceFill(['not_going_at' => now()])->save();
        // แจ้งไม่ไปแต่สุดท้ายมาขึ้นรถ — ต้องนับ
        $c->forceFill(['not_going_at' => now(), 'checked_in_at' => now()])->save();

        $this->fee(['amount_basis' => ExpenseTemplate::BASIS_PER_TRAVELLER]);

        $this->actingAs($this->staff)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.headcount.travellers', 2)
            ->assertJsonPath('data.items.0.amount', 600);
    }

    public function test_only_staff_visible_active_templates_are_shown(): void
    {
        $this->fee();
        $this->fee(['name' => 'ค่าจ้างไกด์', 'staff_visible' => false]);
        $this->fee(['name' => 'ปิดไว้', 'is_active' => false]);

        $response = $this->actingAs($this->staff)->getJson($this->url())->assertOk();

        $this->assertSame(['ค่าผ่านด่านลาว'], array_column($response->json('data.items'), 'name'));
    }

    public function test_staff_not_assigned_to_the_round_cannot_see_fees(): void
    {
        $other = User::factory()->create();
        $other->assignRole('staff');
        $this->fee();

        $this->actingAs($other)->getJson($this->url())->assertForbidden();
    }

    public function test_paying_records_a_ledger_expense_linked_to_the_template(): void
    {
        Storage::fake(MediaDisk::slipDisk());
        $this->book(4);
        $fee = $this->fee();

        $this->actingAs($this->staff)->postJson($this->url("/{$fee->id}/pay"), [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.paid', true)
            ->assertJsonPath('data.items.0.entry.amount', 1500)
            ->assertJsonPath('data.items.0.entry.created_by_name', 'สตาฟบอย')
            ->assertJsonPath('data.items.0.entry.can_undo', true)
            ->assertJsonPath('data.summary.paid_total', 1500)
            ->assertJsonPath('data.summary.remaining_total', 0);

        $entry = ScheduleExpense::sole();
        $this->assertSame($fee->id, $entry->expense_template_id);
        $this->assertSame('ticket', $entry->category);
        $this->assertSame('ค่าผ่านด่านลาว', $entry->name);
        $this->assertSame('300 บาท × 5 คน (ลูกทริป 4 + ทีมงาน 1)', $entry->note);
        $this->assertSame($this->staff->id, $entry->created_by);
        $this->assertNotNull($entry->slip_path);
    }

    public function test_staff_can_override_the_amount_and_cannot_pay_twice(): void
    {
        $this->book(2);
        $fee = $this->fee();

        $this->actingAs($this->staff)->postJson($this->url("/{$fee->id}/pay"), [
            'amount' => 880,
            'note' => 'เรตกีบวันนี้แพงขึ้น',
        ])->assertStatus(201)->assertJsonPath('data.items.0.entry.amount', 880);

        $this->assertStringEndsWith('เรตกีบวันนี้แพงขึ้น', ScheduleExpense::sole()->note);

        $this->actingAs($this->staff)->postJson($this->url("/{$fee->id}/pay"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'รายการนี้ลงบัญชีไปแล้ว');
    }

    public function test_unpriced_fee_needs_an_amount(): void
    {
        $fee = $this->fee(['default_amount' => null]);

        $this->actingAs($this->staff)->getJson($this->url())
            ->assertJsonPath('data.items.0.amount', null)
            ->assertJsonPath('data.summary.unpriced_count', 1);

        $this->actingAs($this->staff)->postJson($this->url("/{$fee->id}/pay"))
            ->assertStatus(422)
            ->assertJsonPath('message', 'กรอกยอดที่จ่ายจริงก่อนบันทึก');
    }

    public function test_large_payment_needs_a_slip_when_the_rule_is_on(): void
    {
        Setting::put(SiteSettings::KEY, ['finance_strict_mode' => true, 'finance_slip_required_above' => 1000]);
        $this->book(5);
        $fee = $this->fee();

        $this->actingAs($this->staff)->postJson($this->url("/{$fee->id}/pay"))
            ->assertStatus(422);

        $this->assertSame(0, ScheduleExpense::count());
    }

    public function test_cannot_pay_a_hidden_template(): void
    {
        $fee = $this->fee(['staff_visible' => false]);

        $this->actingAs($this->staff)->postJson($this->url("/{$fee->id}/pay"), ['amount' => 100])
            ->assertStatus(422);
    }

    public function test_undo_goes_through_the_ledger_and_the_fee_reopens(): void
    {
        $this->book(1);
        $fee = $this->fee();

        $entryId = $this->actingAs($this->staff)->postJson($this->url("/{$fee->id}/pay"))
            ->json('data.items.0.entry.id');

        $this->actingAs($this->staff)
            ->deleteJson("/api/v1/staff/schedules/{$this->schedule->id}/ledger/{$entryId}")
            ->assertOk();

        $this->actingAs($this->staff)->getJson($this->url())
            ->assertJsonPath('data.items.0.paid', false);
    }

    public function test_admin_apply_templates_multiplies_and_skips_what_staff_already_paid(): void
    {
        $this->book(3);
        $paidByStaff = $this->fee();
        $this->fee(['name' => 'ค่าเข้าพื้นที่', 'default_amount' => 40, 'amount_basis' => ExpenseTemplate::BASIS_PER_TRAVELLER]);

        $this->actingAs($this->staff)->postJson($this->url("/{$paidByStaff->id}/pay"))->assertStatus(201);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/finance/schedules/{$this->schedule->id}/expenses/apply-templates")
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertSame(2, ScheduleExpense::count());
        $this->assertEquals(120, ScheduleExpense::where('name', 'ค่าเข้าพื้นที่')->value('amount'));

        // แถวที่แอดมินลงก็นับว่าจ่ายแล้วฝั่งสตาฟ แต่สตาฟยกเลิกเองไม่ได้
        $this->actingAs($this->staff)->getJson($this->url())
            ->assertJsonPath('data.items.1.paid', true)
            ->assertJsonPath('data.items.1.entry.can_undo', false);
    }

    public function test_budget_uses_per_head_totals(): void
    {
        $this->book(2);
        $this->fee(); // 300 × 3
        $this->fee(['name' => 'ค่ารถ', 'default_amount' => 5000, 'amount_basis' => ExpenseTemplate::BASIS_FLAT, 'staff_visible' => false]);

        $this->assertSame(5900.0, app(ScheduleFinanceService::class)->budgetFor($this->schedule));
    }

    public function test_admin_saves_staff_fee_fields_on_a_template(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/finance/trips/{$this->trip->id}/templates", [
                'name' => 'ค่าผ่านด่าน',
                'category' => 'ticket',
                'default_amount' => 300,
                'amount_basis' => 'per_person',
                'staff_visible' => true,
                'staff_note' => 'ด่านบ่อเต็น',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.amount_basis', 'per_person')
            ->assertJsonPath('data.staff_visible', true);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/finance/trips/{$this->trip->id}/templates", [
                'name' => 'x', 'amount_basis' => 'per_dog',
            ])
            ->assertStatus(422);
    }
}
