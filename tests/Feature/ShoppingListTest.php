<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ScheduleExpense;
use App\Models\ScheduleShoppingItem;
use App\Models\ScheduleShoppingReport;
use App\Models\Setting;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\TripShoppingItem;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ใบซื้อของก่อนออกทริป — แอดมินตั้งรายการประจำทริป รอบก๊อปไปใช้
 * สตาฟติ๊กของที่ซื้อแล้ว และปิดท้ายด้วยรายงานที่บังคับแนบรูป
 */
class ShoppingListTest extends TestCase
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
        Role::findOrCreate('operator');

        Setting::put(SiteSettings::KEY, ['finance_strict_mode' => false]);
        Storage::fake(MediaDisk::slipDisk());

        $this->trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'shopping-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'max_participants' => 12,
            'price_per_person' => 3900,
            'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => now()->addDays(2)->toDateString(),
            'return_date' => now()->addDays(4)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $this->staff = User::factory()->create(['name' => 'สตาฟเอ']);
        $this->staff->assignRole('staff');
        $this->schedule->staff()->attach($this->staff->id);

        $this->admin = User::factory()->create(['name' => 'แอดมินบี']);
        $this->admin->assignRole('admin');

        // ลูกค้ายืนยันแล้ว 2 คน + สตาฟ 1 = 3 คน
        $this->bookPassengers(2);
    }

    private function bookPassengers(int $count, string $status = 'confirmed'): void
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 3900 * $count,
        ]);

        for ($i = 0; $i < $count; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => 'Mr.',
                'name' => 'ผู้โดยสาร '.$i,
                'phone' => '081111111'.$i,
            ]);
        }
    }

    private function template(): void
    {
        TripShoppingItem::create(['trip_id' => $this->trip->id, 'name' => 'น้ำดื่ม', 'quantity' => 2, 'unit' => 'ขวด', 'per_person' => true, 'sort_order' => 0]);
        TripShoppingItem::create(['trip_id' => $this->trip->id, 'name' => 'น้ำแข็ง', 'quantity' => 1, 'unit' => 'ถุง', 'sort_order' => 1]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/staff/schedules/{$this->schedule->id}/shopping".$suffix;
    }

    private function adminUrl(string $suffix = ''): string
    {
        return "/api/v1/admin/schedules/{$this->schedule->id}/shopping".$suffix;
    }

    private function itemId(string $name): int
    {
        return ScheduleShoppingItem::where('schedule_id', $this->schedule->id)->where('name', $name)->value('id');
    }

    private function tickAll(): void
    {
        foreach (ScheduleShoppingItem::where('schedule_id', $this->schedule->id)->pluck('id') as $id) {
            $this->actingAs($this->staff)->postJson($this->url("/items/{$id}/bought"), ['bought' => true])->assertOk();
        }
    }

    public function test_first_open_copies_the_trip_list_and_multiplies_per_person_items(): void
    {
        $this->template();

        $response = $this->actingAs($this->staff)->getJson($this->url())->assertOk();

        $response->assertJsonPath('data.headcount.travellers', 2)
            ->assertJsonPath('data.headcount.staff', 1)
            ->assertJsonPath('data.headcount.total', 3)
            ->assertJsonPath('data.summary.total_items', 2)
            ->assertJsonPath('data.items.0.name', 'น้ำดื่ม')
            ->assertJsonPath('data.items.0.total_label', '6 ขวด')
            ->assertJsonPath('data.items.0.rule_label', '2 ขวด/คน × 3 คน')
            ->assertJsonPath('data.items.1.total_label', '1 ถุง')
            ->assertJsonPath('data.items.1.source', 'template');

        // เปิดซ้ำต้องไม่ก๊อปซ้ำ
        $this->actingAs($this->staff)->getJson($this->url())->assertJsonPath('data.summary.total_items', 2);
        $this->assertSame(2, ScheduleShoppingItem::where('schedule_id', $this->schedule->id)->count());
    }

    public function test_round_opened_before_the_trip_had_a_list_picks_it_up_later(): void
    {
        $this->actingAs($this->staff)->getJson($this->url())
            ->assertJsonPath('data.summary.total_items', 0)
            ->assertJsonPath('data.has_template', false);

        $this->template();

        $this->actingAs($this->staff)->getJson($this->url())->assertJsonPath('data.summary.total_items', 2);
    }

    public function test_round_whose_items_were_all_removed_is_not_refilled(): void
    {
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());

        foreach (ScheduleShoppingItem::where('schedule_id', $this->schedule->id)->pluck('id') as $id) {
            $this->actingAs($this->admin)->deleteJson($this->adminUrl("/items/{$id}"))->assertOk();
        }

        $this->actingAs($this->staff)->getJson($this->url())->assertJsonPath('data.summary.total_items', 0);
    }

    public function test_staff_ticks_and_unticks_an_item_shared_across_the_team(): void
    {
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());
        $water = $this->itemId('น้ำดื่ม');

        $this->actingAs($this->staff)->postJson($this->url("/items/{$water}/bought"), ['bought' => true])
            ->assertOk()
            ->assertJsonPath('data.summary.bought_items', 1)
            ->assertJsonPath('data.items.0.bought', true)
            ->assertJsonPath('data.items.0.bought_by_name', 'สตาฟเอ');

        // เพื่อนสตาฟอีกคนในรอบเดียวกันเห็นสถานะเดียวกัน
        $other = User::factory()->create();
        $other->assignRole('staff');
        $this->schedule->staff()->attach($other->id);
        $this->actingAs($other)->getJson($this->url())->assertJsonPath('data.items.0.bought', true);

        $this->actingAs($other)->postJson($this->url("/items/{$water}/bought"), ['bought' => false])
            ->assertOk()
            ->assertJsonPath('data.items.0.bought', false)
            ->assertJsonPath('data.items.0.bought_by_name', null);
    }

    public function test_only_assigned_staff_can_open_the_list(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('staff');
        $this->actingAs($stranger)->getJson($this->url())->assertForbidden();

        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson($this->url())->assertForbidden();
    }

    public function test_staff_adds_an_extra_item_and_can_delete_only_their_own_extras(): void
    {
        $this->template();

        $this->actingAs($this->staff)->postJson($this->url('/items'), ['name' => 'ถ่านไฟฉาย', 'quantity' => 4, 'unit' => 'ก้อน'])
            ->assertCreated()
            ->assertJsonPath('data.items.2.name', 'ถ่านไฟฉาย')
            ->assertJsonPath('data.items.2.source', 'extra')
            ->assertJsonPath('data.items.2.can_delete', true)
            ->assertJsonPath('data.items.0.can_delete', false);

        $this->actingAs($this->staff)->deleteJson($this->url('/items/'.$this->itemId('น้ำดื่ม')))->assertStatus(422);
        $this->actingAs($this->staff)->deleteJson($this->url('/items/'.$this->itemId('ถ่านไฟฉาย')))
            ->assertOk()
            ->assertJsonPath('data.summary.total_items', 2);
    }

    public function test_report_requires_a_photo(): void
    {
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());
        $this->tickAll();

        $this->actingAs($this->staff)->postJson($this->url('/report'), ['note' => 'ครบ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photos');

        $this->assertDatabaseCount('schedule_shopping_reports', 0);
    }

    public function test_unticked_items_need_a_note_before_the_report_goes_through(): void
    {
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());

        $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('bag.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ยังมีของที่ไม่ได้ติ๊ก 2 รายการ — ช่วยเขียนหมายเหตุบอกแอดมินด้วยว่าเพราะอะไร');

        $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('bag.jpg')],
            'note' => 'ร้านน้ำแข็งปิด ไปซื้อที่ปั๊มแทน',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.report.unbought', ['น้ำดื่ม', 'น้ำแข็ง']);
    }

    public function test_submitting_locks_the_list_stores_photos_and_notifies_admins(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operator');
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());
        $this->tickAll();

        $response = $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        $response->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.report.submitted', true)
            ->assertJsonPath('data.report.submitted_by_name', 'สตาฟเอ')
            ->assertJsonPath('data.report.headcount', 3)
            ->assertJsonCount(2, 'data.report.photos');

        $report = ScheduleShoppingReport::first();
        $this->assertCount(2, $report->photos);
        foreach ($report->photos as $path) {
            $this->assertStringStartsWith('slips/shopping/', $path);
            Storage::disk(MediaDisk::slipDisk())->assertExists($path);
        }

        $this->assertSame(1, SmartNotification::where('user_id', $this->admin->id)->where('type', 'shopping_report')->count());
        $this->assertSame(1, SmartNotification::where('user_id', $operator->id)->where('type', 'shopping_report')->count());
        $this->assertSame(0, SmartNotification::where('user_id', $this->staff->id)->count());

        // ล็อกแล้ว ติ๊ก/เพิ่ม/ส่งซ้ำไม่ได้
        $this->actingAs($this->staff)->postJson($this->url('/items/'.$this->itemId('น้ำดื่ม').'/bought'), ['bought' => false])
            ->assertStatus(422);
        $this->actingAs($this->staff)->postJson($this->url('/items'), ['name' => 'ขนม'])->assertStatus(422);
        $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('c.jpg')],
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_amount_goes_into_the_trip_ledger_once_even_after_a_resubmit(): void
    {
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());
        $this->tickAll();

        $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('receipt.jpg')],
            'total_amount' => 850,
            'add_to_ledger' => '1',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.report.in_ledger', true);

        $expense = ScheduleExpense::sole();
        $this->assertSame('ซื้อของก่อนออกทริป', $expense->name);
        $this->assertSame('food', $expense->category);
        $this->assertEquals(850, $expense->amount);
        $this->assertNotNull($expense->slip_path);

        $this->actingAs($this->admin)->postJson($this->adminUrl('/reopen'), ['reason' => 'ยอดไม่ตรงใบเสร็จ'])
            ->assertOk()
            ->assertJsonPath('data.locked', false);

        $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('receipt2.jpg')],
            'total_amount' => 920,
            'add_to_ledger' => '1',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, ScheduleExpense::count());
        $this->assertEquals(920, ScheduleExpense::sole()->amount);
        // รูปเดิมยังอยู่เป็นหลักฐาน
        $this->assertCount(2, ScheduleShoppingReport::first()->photos);
    }

    public function test_report_without_ledger_flag_leaves_the_ledger_alone(): void
    {
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());
        $this->tickAll();

        $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('receipt.jpg')],
            'total_amount' => 500,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.report.total_amount', 500)
            ->assertJsonPath('data.report.in_ledger', false);

        $this->assertSame(0, ScheduleExpense::count());
    }

    public function test_reopen_notifies_the_submitter_and_acknowledge_clears_the_action_queue(): void
    {
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());
        $this->tickAll();
        $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        $queue = fn () => collect($this->actingAs($this->admin)->getJson('/api/v1/admin/action-queue')->json('data.groups'))
            ->firstWhere('key', 'shopping_reports');
        $this->assertSame(1, $queue()['count']);

        $this->actingAs($this->admin)->postJson($this->adminUrl('/acknowledge'))
            ->assertOk()
            ->assertJsonPath('data.report.reviewed_by_name', 'แอดมินบี');
        $this->assertSame(0, $queue()['count']);

        $this->actingAs($this->admin)->postJson($this->adminUrl('/reopen'), ['reason' => 'ขอรูปใบเสร็จชัด ๆ'])
            ->assertOk()
            ->assertJsonPath('data.report.submitted', false)
            ->assertJsonPath('data.report.reopen_reason', 'ขอรูปใบเสร็จชัด ๆ');

        $this->assertSame(1, SmartNotification::where('user_id', $this->staff->id)->where('type', 'shopping_report_reopened')->count());

        // ตีกลับแล้วสตาฟติ๊กได้อีก
        $this->actingAs($this->staff)->postJson($this->url('/items/'.$this->itemId('น้ำแข็ง').'/bought'), ['bought' => false])
            ->assertOk();
    }

    public function test_resync_adds_new_template_items_and_updates_only_unbought_ones(): void
    {
        $this->template();
        $this->actingAs($this->staff)->getJson($this->url());
        $this->actingAs($this->staff)->postJson($this->url('/items/'.$this->itemId('น้ำดื่ม').'/bought'), ['bought' => true]);

        TripShoppingItem::where('name', 'น้ำดื่ม')->update(['quantity' => 3]);
        TripShoppingItem::where('name', 'น้ำแข็ง')->update(['quantity' => 2]);
        TripShoppingItem::create(['trip_id' => $this->trip->id, 'name' => 'ถุงขยะ', 'quantity' => 1, 'unit' => 'แพ็ค', 'sort_order' => 2]);

        $this->actingAs($this->admin)->postJson($this->adminUrl('/resync'))
            ->assertOk()
            ->assertJsonPath('message', 'ดึงรายการจากทริปแล้ว — เพิ่ม 1 · อัปเดต 1 รายการ')
            ->assertJsonPath('data.items.0.quantity', 2) // ซื้อไปแล้ว ไม่แตะ
            ->assertJsonPath('data.items.1.quantity', 2)
            ->assertJsonPath('data.items.2.name', 'ถุงขยะ');
    }

    public function test_admin_saves_the_trip_template_keeping_row_ids(): void
    {
        $url = "/api/v1/admin/trips/{$this->trip->id}/shopping-template";

        $first = $this->actingAs($this->admin)->putJson($url, ['items' => [
            ['name' => 'น้ำดื่ม', 'quantity' => 2, 'unit' => 'ขวด', 'per_person' => true],
            ['name' => 'น้ำแข็ง', 'quantity' => 1, 'unit' => 'ถุง'],
        ]])->assertOk()->json('data.items');

        $second = $this->actingAs($this->admin)->putJson($url, ['items' => [
            ['id' => $first[1]['id'], 'name' => 'น้ำแข็งหลอด', 'quantity' => 2, 'unit' => 'ถุง'],
            ['name' => 'ผลไม้', 'quantity' => 1, 'unit' => 'กก.'],
        ]])->assertOk()->json('data.items');

        $this->assertSame($first[1]['id'], $second[0]['id']);
        $this->assertSame('น้ำแข็งหลอด', $second[0]['name']);
        $this->assertSame(['น้ำแข็งหลอด', 'ผลไม้'], array_column($second, 'name'));
        $this->assertSame(2, TripShoppingItem::count());

        $this->actingAs($this->staff)->putJson($url, ['items' => []])->assertForbidden();
    }

    public function test_admin_round_list_reports_each_round_status(): void
    {
        $this->template();

        $rows = $this->actingAs($this->admin)->getJson('/api/v1/admin/shopping/rounds')->assertOk()->json('data');
        $this->assertSame('not_started', $rows[0]['status']);
        $this->assertSame(2, $rows[0]['items_count']);

        $this->actingAs($this->staff)->getJson($this->url());
        $this->tickAll();
        $this->actingAs($this->staff)->post($this->url('/report'), [
            'photos' => [UploadedFile::fake()->image('a.jpg')],
            'total_amount' => 300,
        ], ['Accept' => 'application/json'])->assertOk();

        $row = $this->actingAs($this->admin)->getJson('/api/v1/admin/shopping/rounds')->json('data.0');
        $this->assertSame('submitted', $row['status']);
        $this->assertSame(2, $row['bought_count']);
        $this->assertEquals(300, $row['total_amount']);
    }
}
