<?php

namespace Tests\Feature;

use App\Jobs\SendFinanceCloseRemindersJob;
use App\Mail\AdminFinanceCloseOverdueMail;
use App\Models\Booking;
use App\Models\ScheduleExpense;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ScheduleFinanceService;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "ทุกรอบต้องปิดงบ" — ตัวบังคับที่ทำงานแม้ไม่มีใครกดอะไรเลย
 *
 * เงื่อนไขก่อนปิดงบกันการปิดมั่วได้ แต่ไม่ได้กันการไม่ปิดเลย ชุดนี้ทดสอบสามอย่าง
 * ที่ปิดช่องนั้น: บล็อกการเปิดรอบใหม่ / รายการงานค้าง / อีเมลเตือนรายวัน
 */
class ScheduleFinanceOverdueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('finance');
        Role::findOrCreate('operator');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว', 'slug' => 'overdue-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'hard', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 4000, 'status' => 'active',
        ]);

        // ค่าตั้งต้นของระบบคือ "ไม่บล็อก" — ชุดนี้ทดสอบตัวบล็อกโดยเฉพาะ จึงเปิดเอง
        // ให้ชัด แทนที่จะพึ่งค่าตั้งต้นซึ่งเปลี่ยนความหมายของเทสต์ไปเงียบ ๆ ได้
        $this->setSetting(['finance_block_new_rounds' => true]);
    }

    /** ทับเฉพาะคีย์ที่ส่งมา — Setting::put() เขียนทับทั้งก้อน จะล้างค่าที่ setUp ตั้งไว้ */
    private function setSetting(array $values): void
    {
        $stored = Setting::get(SiteSettings::KEY, []);

        Setting::put(SiteSettings::KEY, array_merge(is_array($stored) ? $stored : [], $values));
    }

    /** รอบที่จบไปแล้ว $daysAgo วันและยังไม่ปิดงบ */
    private function endedRound(int $daysAgo): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => now('Asia/Bangkok')->subDays($daysAgo + 1)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays($daysAgo)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'closed',
        ]);
    }

    private function newRoundPayload(): array
    {
        return [
            'trip_id' => $this->trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'transport_type' => 'van',
        ];
    }

    // ─── บล็อกการเปิดรอบใหม่ ────────────────────────────────────

    public function test_a_trip_with_an_overdue_round_cannot_open_a_new_one(): void
    {
        $this->endedRound(10);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, TripSchedule::where('trip_id', $this->trip->id)->count());
    }

    public function test_a_round_still_inside_the_grace_window_does_not_block(): void
    {
        // ตั้งต้นผ่อนผัน 7 วัน — จบมา 2 วันยังไม่ถือว่าค้าง
        $this->endedRound(2);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertCreated();
    }

    public function test_closing_the_old_round_unblocks_the_trip(): void
    {
        $overdue = $this->endedRound(10);
        ScheduleExpense::create([
            'schedule_id' => $overdue->id, 'kind' => 'expense', 'category' => 'fuel',
            'name' => 'ค่าน้ำมัน', 'amount' => 800, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/finance/schedules/{$overdue->id}/close")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertCreated();
    }

    public function test_an_empty_overdue_round_can_be_closed_and_unblocks_the_trip(): void
    {
        // ทางออกของทางตัน: รอบที่ไม่มีใครจองปิดงบได้โดยไม่ต้องกุค่าใช้จ่ายขึ้นมา
        $overdue = $this->endedRound(10);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/finance/schedules/{$overdue->id}/close")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertCreated();
    }

    public function test_an_overdue_round_that_took_money_still_blocks_until_its_costs_are_keyed(): void
    {
        $overdue = $this->endedRound(10);
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $overdue->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 4000,
            'paid_amount' => 4000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/finance/schedules/{$overdue->id}/close")
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertStatus(422);
    }

    public function test_a_cancelled_round_is_never_counted_as_overdue(): void
    {
        $this->endedRound(30)->update(['status' => 'cancelled']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/overdue')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertCreated();
    }

    public function test_the_shipped_default_lets_a_new_round_open_regardless(): void
    {
        // ค่าตั้งต้นของระบบคือไม่บล็อก — ลบค่าที่ setUp บันทึกไว้เพื่อทดสอบค่าตั้งต้นจริง
        Setting::query()->where('key', SiteSettings::KEY)->delete();
        Setting::forget(SiteSettings::KEY);

        $this->assertFalse(SiteSettings::financeBlocksNewRounds());

        $this->endedRound(30);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertCreated();

        // แต่ยังต้องขึ้นเป็นงานค้างให้เห็นอยู่
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/overdue')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.blocks_new_rounds', false);
    }

    public function test_turning_the_block_off_leaves_only_a_warning(): void
    {
        $this->setSetting(['finance_block_new_rounds' => false]);
        $this->endedRound(10);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertCreated();

        // ยังต้องขึ้นเป็นงานค้างอยู่ — แค่ไม่ห้าม
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/overdue')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    // ─── รายการงานค้าง ──────────────────────────────────────────

    public function test_overdue_list_reports_how_long_each_round_has_been_sitting(): void
    {
        $this->endedRound(10);

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/overdue')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['count']);
        $this->assertSame(7, $data['grace_days']);
        $this->assertTrue($data['blocks_new_rounds']);
        $this->assertSame(10, $data['rounds'][0]['days_since_end']);
        $this->assertSame(0, $data['rounds'][0]['expense_items_count']);
    }

    public function test_the_action_queue_surfaces_overdue_rounds(): void
    {
        $this->endedRound(10);

        $groups = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertOk()
            ->json('data.groups');

        $group = collect($groups)->firstWhere('key', 'finance_close');
        $this->assertNotNull($group);
        $this->assertSame(1, $group['count']);
    }

    public function test_the_menu_badge_count_is_gated_to_finance_roles(): void
    {
        $this->endedRound(10);

        $operator = User::factory()->create();
        $operator->assignRole('operator');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/admin/finance/overdue-count')
            ->assertStatus(403);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/overdue-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    // ─── อีเมลเตือนรายวัน ───────────────────────────────────────

    public function test_the_daily_job_mails_admins_and_finance_users_once(): void
    {
        Mail::fake();
        $this->endedRound(10);

        $finance = User::factory()->create();
        $finance->assignRole('operator');
        $finance->assignRole('finance');

        // ลูกค้าธรรมดาต้องไม่ได้รับ
        $customer = User::factory()->create();

        (new SendFinanceCloseRemindersJob)->handle(app(ScheduleFinanceService::class));

        Mail::assertQueued(AdminFinanceCloseOverdueMail::class, function ($mail) use ($finance, $customer) {
            return $mail->hasTo($this->admin->email)
                && $mail->hasTo($finance->email)
                && ! $mail->hasTo($customer->email);
        });
    }

    public function test_the_daily_job_stays_quiet_when_nothing_is_overdue(): void
    {
        Mail::fake();
        $this->endedRound(2);

        (new SendFinanceCloseRemindersJob)->handle(app(ScheduleFinanceService::class));

        Mail::assertNothingQueued();
    }

    public function test_a_round_that_never_departed_yet_is_not_overdue(): void
    {
        TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/overdue')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_the_grace_window_is_configurable(): void
    {
        $this->setSetting(['finance_close_grace_days' => 30]);
        $this->endedRound(10);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/finance/overdue')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        // และเมื่อไม่ค้าง ก็เปิดรอบใหม่ได้ตามปกติ
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertCreated();
    }

    public function test_a_paid_but_unclosed_round_still_blocks(): void
    {
        // จ่ายครบแล้วไม่ได้แปลว่าปิดงบแล้ว — ต้องมีคนกดปิดจริง
        $round = $this->endedRound(10);
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $round->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 4000,
            'paid_amount' => 4000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules', $this->newRoundPayload())
            ->assertStatus(422);
    }
}
