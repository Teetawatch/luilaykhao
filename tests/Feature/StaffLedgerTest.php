<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ScheduleExpense;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * สมุดบัญชีหน้างานของสตาฟ — จดรายรับ/รายจ่ายระหว่างทริปพร้อมสลิป
 * และยอดต้องไหลไปโผล่ในหน้ากำไรของแอดมินโดยไม่ถูกนับผิดฝั่ง
 */
class StaffLedgerTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('staff');
        Role::findOrCreate('admin');

        // ชุดนี้ทดสอบสมุดบัญชีหน้างานของสตาฟ ส่วนข้อบังคับของโหมดเข้มงวด
        // (บังคับหมวด/สลิป, ล็อกหลังปิดงบ) มีชุดของตัวเองที่ ScheduleFinanceStrictTest
        Setting::put(SiteSettings::KEY, ['finance_strict_mode' => false]);

        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'ledger-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'max_participants' => 15,
            'price_per_person' => 4500,
            'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
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
    }

    private function ledgerUrl(string $suffix = ''): string
    {
        return "/api/v1/staff/schedules/{$this->schedule->id}/ledger".$suffix;
    }

    public function test_staff_records_an_expense_with_a_slip_photo(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $response = $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'expense',
            'category' => 'food',
            'name' => 'ข้าวเช้าทีมงาน',
            'amount' => 850.50,
            'note' => 'ร้านป้าแดง 8 คน',
            'spent_at' => now()->addDays(3)->toDateString(),
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.entry.kind', 'expense')
            ->assertJsonPath('data.entry.name', 'ข้าวเช้าทีมงาน')
            ->assertJsonPath('data.entry.amount', 850.5)
            ->assertJsonPath('data.entry.category_label', 'อาหาร/เครื่องดื่ม')
            ->assertJsonPath('data.entry.created_by_name', 'สตาฟบอย')
            ->assertJsonPath('data.entry.can_edit', true)
            ->assertJsonPath('data.ledger.summary.expense_total', 850.5);

        $this->assertNotNull($response->json('data.entry.slip_url'));

        $entry = ScheduleExpense::first();
        $this->assertStringStartsWith('slips/expenses/', $entry->slip_path);
        Storage::disk(MediaDisk::slipDisk())->assertExists($entry->slip_path);
    }

    public function test_summary_separates_income_from_expenses(): void
    {
        $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'expense', 'name' => 'ค่าน้ำมัน', 'amount' => 2000, 'category' => 'fuel',
        ])->assertStatus(201);

        $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'income', 'name' => 'เก็บค่าเช่าเต็นท์หน้างาน', 'amount' => 600, 'category' => 'rental',
        ])->assertStatus(201);

        $response = $this->actingAs($this->staff)->getJson($this->ledgerUrl());

        $response->assertOk()
            ->assertJsonPath('data.summary.expense_total', 2000)
            ->assertJsonPath('data.summary.income_total', 600)
            ->assertJsonPath('data.summary.net', -1400)
            ->assertJsonPath('data.summary.items_count', 2);

        // หมวดให้แอปเอาไปทำชิป — คนละชุดระหว่างรายรับกับรายจ่าย
        $this->assertNotEmpty($response->json('data.categories.expense'));
        $this->assertNotEmpty($response->json('data.categories.income'));
    }

    public function test_spent_at_defaults_to_today_when_omitted(): void
    {
        $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'expense', 'name' => 'ค่าเข้าอุทยาน', 'amount' => 300,
        ])->assertStatus(201)
            ->assertJsonPath('data.entry.spent_at', now('Asia/Bangkok')->toDateString());
    }

    public function test_staff_can_edit_and_delete_only_their_own_entries(): void
    {
        $mine = $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'expense', 'name' => 'ค่าที่พัก', 'amount' => 1200,
        ])->json('data.entry.id');

        // รายการของสตาฟอีกคนในรอบเดียวกัน
        $other = User::factory()->create(['name' => 'สตาฟอีกคน']);
        $other->assignRole('staff');
        $this->schedule->staff()->attach($other->id);
        $theirs = ScheduleExpense::create([
            'schedule_id' => $this->schedule->id,
            'kind' => 'expense',
            'name' => 'ค่ารถ',
            'amount' => 500,
            'created_by' => $other->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson($this->ledgerUrl("/{$mine}"), ['amount' => 1500])
            ->assertOk()
            ->assertJsonPath('data.entry.amount', 1500);

        $this->actingAs($this->staff)
            ->postJson($this->ledgerUrl("/{$theirs->id}"), ['amount' => 9999])
            ->assertStatus(403);

        $this->actingAs($this->staff)
            ->deleteJson($this->ledgerUrl("/{$theirs->id}"))
            ->assertStatus(403);

        $this->actingAs($this->staff)
            ->deleteJson($this->ledgerUrl("/{$mine}"))
            ->assertOk()
            ->assertJsonPath('data.summary.expense_total', 500);

        $this->assertSoftDeleted('schedule_expenses', ['id' => $mine]);
        $this->assertDatabaseHas('schedule_expenses', ['id' => $theirs->id, 'amount' => 500]);
    }

    public function test_replacing_the_slip_removes_the_old_file(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $entryId = $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'expense',
            'name' => 'ค่าเรือ',
            'amount' => 900,
            'slip' => UploadedFile::fake()->image('first.jpg'),
        ])->json('data.entry.id');

        $oldPath = ScheduleExpense::find($entryId)->slip_path;

        $this->actingAs($this->staff)->post($this->ledgerUrl("/{$entryId}"), [
            'slip' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        $newPath = ScheduleExpense::find($entryId)->slip_path;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk(MediaDisk::slipDisk())->assertMissing($oldPath);
        Storage::disk(MediaDisk::slipDisk())->assertExists($newPath);
    }

    public function test_staff_not_assigned_to_the_schedule_is_refused(): void
    {
        $outsider = User::factory()->create();
        $outsider->assignRole('staff');

        $this->actingAs($outsider)->getJson($this->ledgerUrl())->assertStatus(403);
        $this->actingAs($outsider)->postJson($this->ledgerUrl(), [
            'kind' => 'expense', 'name' => 'มั่ว', 'amount' => 100,
        ])->assertStatus(403);
    }

    public function test_customer_without_the_staff_role_is_refused(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->getJson($this->ledgerUrl())->assertStatus(403);
    }

    public function test_invalid_amount_or_kind_is_rejected(): void
    {
        $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'donation', 'name' => 'อะไรก็ไม่รู้', 'amount' => 100,
        ])->assertStatus(422);

        $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'expense', 'name' => 'ติดลบ', 'amount' => -50,
        ])->assertStatus(422);
    }

    public function test_onsite_income_counts_as_revenue_not_expense_in_admin_profit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 9000,
            'paid_amount' => 9000,
        ]);

        $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'expense', 'name' => 'ค่าน้ำมัน', 'amount' => 2000,
        ])->assertStatus(201);

        $this->actingAs($this->staff)->postJson($this->ledgerUrl(), [
            'kind' => 'income', 'name' => 'เก็บเงินหน้างาน', 'amount' => 1000,
        ])->assertStatus(201);

        // 9000 (จอง) + 1000 (หน้างาน) − 2000 (จ่าย) = 8000
        $this->actingAs($admin)
            ->getJson("/api/v1/admin/finance/schedules/{$this->schedule->id}/expenses")
            ->assertOk()
            ->assertJsonPath('data.summary.paid_revenue', 9000)
            ->assertJsonPath('data.summary.onsite_income', 1000)
            ->assertJsonPath('data.summary.expense_total', 2000)
            ->assertJsonPath('data.summary.profit', 8000);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/finance/trips')
            ->assertOk()
            ->assertJsonPath('data.summary.expense_total', 2000)
            ->assertJsonPath('data.summary.onsite_income', 1000)
            ->assertJsonPath('data.summary.profit', 8000);
    }
}
