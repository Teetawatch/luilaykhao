<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ExpenseTemplate;
use App\Models\ScheduleExpense;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Finance Trip', 'slug' => 'finance-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function bookOnto(TripSchedule $schedule, float $paid, string $status = 'confirmed', float $refund = 0): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => $paid,
            'paid_amount' => $paid,
            'refund_amount' => $refund,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Pax', 'phone' => '0810000000',
        ]);

        return $booking;
    }

    public function test_trip_profit_summary_subtracts_expenses_from_paid_revenue(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $this->bookOnto($schedule, 3000);
        $this->bookOnto($schedule, 2000);
        // booking ที่ยกเลิกต้องไม่ถูกนับเป็นรายรับ
        $this->bookOnto($schedule, 5000, 'cancelled');

        ScheduleExpense::create(['schedule_id' => $schedule->id, 'name' => 'ค่าน้ำมัน', 'amount' => 1200]);
        ScheduleExpense::create(['schedule_id' => $schedule->id, 'name' => 'ค่าอาหาร', 'amount' => 800]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/trips')
            ->assertOk()
            ->assertJsonPath('data.summary.paid_revenue', 5000)
            ->assertJsonPath('data.summary.expense_total', 2000)
            ->assertJsonPath('data.summary.profit', 3000)
            ->assertJsonPath('data.trips.0.trip_id', $trip->id)
            ->assertJsonPath('data.trips.0.paid_revenue', 5000)
            ->assertJsonPath('data.trips.0.profit', 3000)
            ->assertJsonPath('data.trips.0.passengers_count', 2);
    }

    public function test_paid_revenue_is_net_of_refunds(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $this->bookOnto($schedule, 3000, 'confirmed', 500);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/finance/trips/{$trip->id}/schedules")
            ->assertOk()
            ->assertJsonPath('data.totals.paid_revenue', 2500)
            ->assertJsonPath('data.schedules.0.paid_revenue', 2500);
    }

    public function test_template_crud_per_trip(): void
    {
        $trip = $this->makeTrip();

        $created = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/finance/trips/{$trip->id}/templates", [
                'name' => 'ค่าสตาฟ', 'default_amount' => 1500,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertDatabaseHas('expense_templates', [
            'id' => $created['id'], 'trip_id' => $trip->id, 'name' => 'ค่าสตาฟ',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/finance/trips/{$trip->id}/templates/{$created['id']}", ['default_amount' => 2000])
            ->assertOk();
        $this->assertEquals('2000.00', ExpenseTemplate::find($created['id'])->default_amount);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/finance/trips/{$trip->id}/templates/{$created['id']}")
            ->assertOk();
        $this->assertDatabaseMissing('expense_templates', ['id' => $created['id']]);
    }

    public function test_store_expense_from_template_snapshots_name_and_amount(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $template = ExpenseTemplate::create(['trip_id' => $trip->id, 'name' => 'ค่าน้ำมัน', 'default_amount' => 1200]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/finance/schedules/{$schedule->id}/expenses", [
                'expense_template_id' => $template->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.expense.name', 'ค่าน้ำมัน')
            ->assertJsonPath('data.expense.amount', 1200)
            ->assertJsonPath('data.summary.expense_total', 1200);

        // rename template ภายหลังต้องไม่กระทบ snapshot ที่บันทึกไว้
        $template->update(['name' => 'ค่าน้ำมันใหม่']);
        $this->assertDatabaseHas('schedule_expenses', [
            'schedule_id' => $schedule->id, 'name' => 'ค่าน้ำมัน', 'expense_template_id' => $template->id,
        ]);
    }

    public function test_store_expense_freeform(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/finance/schedules/{$schedule->id}/expenses", [
                'name' => 'ค่าซื้อของจิปาถะ', 'amount' => 350, 'note' => 'ซื้อน้ำดื่ม',
            ])
            ->assertCreated()
            ->assertJsonPath('data.expense.created_by', $this->admin->id);

        $this->assertDatabaseHas('schedule_expenses', [
            'schedule_id' => $schedule->id, 'name' => 'ค่าซื้อของจิปาถะ', 'note' => 'ซื้อน้ำดื่ม',
        ]);
    }

    public function test_apply_templates_creates_all_active_and_skips_duplicates(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        ExpenseTemplate::create(['trip_id' => $trip->id, 'name' => 'ค่าน้ำมัน', 'default_amount' => 1200]);
        ExpenseTemplate::create(['trip_id' => $trip->id, 'name' => 'ค่าอาหาร', 'default_amount' => 800]);
        ExpenseTemplate::create(['trip_id' => $trip->id, 'name' => 'รายการปิด', 'default_amount' => 500, 'is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/finance/schedules/{$schedule->id}/expenses/apply-templates")
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.summary.expense_total', 2000);

        // กดซ้ำต้องไม่เพิ่มซ้ำ
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/finance/schedules/{$schedule->id}/expenses/apply-templates")
            ->assertOk()
            ->assertJsonPath('data.created', 0);

        $this->assertEquals(2, ScheduleExpense::where('schedule_id', $schedule->id)->count());
    }

    public function test_update_and_delete_expense(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $expense = ScheduleExpense::create(['schedule_id' => $schedule->id, 'name' => 'ค่าน้ำมัน', 'amount' => 1200]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/finance/schedules/{$schedule->id}/expenses/{$expense->id}", ['amount' => 1500])
            ->assertOk()
            ->assertJsonPath('data.summary.expense_total', 1500);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/finance/schedules/{$schedule->id}/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.expense_total', 0);
        $this->assertDatabaseMissing('schedule_expenses', ['id' => $expense->id]);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $trip = $this->makeTrip();
        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/finance/trips')
            ->assertForbidden();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/admin/finance/trips/{$trip->id}/templates", ['name' => 'x'])
            ->assertForbidden();
    }
}
