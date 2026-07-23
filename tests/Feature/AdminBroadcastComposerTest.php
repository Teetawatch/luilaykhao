<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastNotificationJob;
use App\Models\Booking;
use App\Models\BroadcastDispatch;
use App\Models\FcmToken;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BroadcastNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ทีมงานเขียนข้อความถึงลูกค้าเอง — เดิม push ยิงได้จาก job อัตโนมัติเท่านั้น
 *
 * จุดสำคัญคือกฎผู้รับที่ต่างกันโดยตั้งใจ: ส่งหาทุกคน = การตลาด ต้องเคารพการปิดรับ
 * ข่าวสาร แต่ส่งหาคนในรอบเดินทาง = เรื่องปฏิบัติการ ต้องถึงทุกคนบนรอบนั้น
 */
class AdminBroadcastComposerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'bc-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'medium', 'duration_days' => 3,
            'max_participants' => 20, 'price_per_person' => 4200, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(2)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(4)->toDateString(),
            'total_seats' => 20, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    private function makeReachableCustomer(bool $marketingEnabled = true): User
    {
        $user = User::factory()->create(['marketing_push_enabled' => $marketingEnabled]);
        $user->assignRole('customer');
        FcmToken::create([
            'user_id' => $user->id,
            'token' => 'tok-'.uniqid(),
            'platform' => 'android',
            'is_active' => true,
        ]);

        return $user;
    }

    private function bookOnto(TripSchedule $schedule, User $user): void
    {
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 4200,
            'paid_amount' => 4200,
            'payment_type' => 'full',
        ]);
    }

    public function test_admin_can_send_a_message_to_everyone_on_one_round(): void
    {
        $schedule = $this->makeSchedule();
        $traveller = $this->makeReachableCustomer();
        $this->bookOnto($schedule, $traveller);
        $this->makeReachableCustomer(); // ลูกค้าคนอื่นที่ไม่ได้อยู่รอบนี้

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/broadcasts', [
                'title' => 'พรุ่งนี้ฝนตก',
                'body' => 'เตรียมเสื้อกันฝนมาด้วยนะครับ',
                'audience' => 'schedule',
                'audience_id' => $schedule->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.reachable', 1);

        // ถึงเฉพาะคนบนรอบนั้น
        $this->assertDatabaseCount('smart_notifications', 1);
        $notification = SmartNotification::first();
        $this->assertSame($traveller->id, $notification->user_id);
        $this->assertSame('พรุ่งนี้ฝนตก', $notification->title);
        $this->assertSame($schedule->id, $notification->data['schedule_id']);

        $dispatch = BroadcastDispatch::where('event_type', 'admin_broadcast')->first();
        $this->assertSame($this->admin->id, $dispatch->sent_by);
        $this->assertSame(1, $dispatch->recipients_count);
        $this->assertSame($notification->broadcast_dispatch_id, $dispatch->id);
    }

    public function test_an_operational_message_reaches_travellers_who_opted_out_of_marketing(): void
    {
        $schedule = $this->makeSchedule();
        $optedOut = $this->makeReachableCustomer(marketingEnabled: false);
        $this->bookOnto($schedule, $optedOut);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/broadcasts', [
                'title' => 'จุดรับเปลี่ยนที่',
                'body' => 'ย้ายจุดรับไปหน้าปั๊ม ปตท.',
                'audience' => 'schedule',
                'audience_id' => $schedule->id,
            ])
            ->assertOk();

        $this->assertDatabaseCount('smart_notifications', 1);
    }

    public function test_a_message_to_everyone_respects_the_marketing_opt_out(): void
    {
        $this->makeReachableCustomer(marketingEnabled: true);
        $this->makeReachableCustomer(marketingEnabled: false);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/broadcasts', [
                'title' => 'ทริปใหม่เดือนหน้า',
                'body' => 'เปิดจองแล้ววันนี้',
                'audience' => 'all',
            ])
            ->assertOk()
            ->assertJsonPath('data.reachable', 1);

        $this->assertDatabaseCount('smart_notifications', 1);
    }

    public function test_sending_to_an_empty_audience_is_refused_rather_than_silently_dropped(): void
    {
        $schedule = $this->makeSchedule();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/broadcasts', [
                'title' => 'ทดสอบ',
                'body' => 'ไม่มีใครอยู่ในรอบนี้',
                'audience' => 'schedule',
                'audience_id' => $schedule->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('broadcast_dispatches', ['event_type' => 'admin_broadcast']);
    }

    public function test_targeting_a_trip_or_round_requires_choosing_one(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/broadcasts', [
                'title' => 'ทดสอบ',
                'body' => 'ลืมเลือกทริป',
                'audience' => 'trip',
            ])
            ->assertStatus(422);
    }

    public function test_history_reports_the_read_rate_of_each_blast(): void
    {
        $schedule = $this->makeSchedule();
        $a = $this->makeReachableCustomer();
        $b = $this->makeReachableCustomer();
        $this->bookOnto($schedule, $a);
        $this->bookOnto($schedule, $b);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/broadcasts', [
                'title' => 'นัดเวลาออกเดินทาง',
                'body' => 'เจอกัน 06:00 น.',
                'audience' => 'schedule',
                'audience_id' => $schedule->id,
            ])->assertOk();

        SmartNotification::where('user_id', $a->id)->update(['is_read' => true]);

        $row = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/broadcasts')
            ->assertOk()
            ->json('data.dispatches.0');

        $this->assertTrue($row['is_manual']);
        $this->assertSame(2, $row['recipients_count']);
        $this->assertSame(1, $row['read_count']);
        $this->assertSame(50, $row['read_percent']);
        $this->assertSame($this->admin->name, $row['sent_by_name']);
    }

    public function test_automatic_broadcasts_also_appear_in_the_history(): void
    {
        Queue::fake();

        app(BroadcastNotificationService::class)->broadcast(
            'new_trip', 'new_trip:99', 'ทริปใหม่มาแล้ว!', 'เปิดจองวันนี้'
        );

        $row = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/broadcasts')
            ->assertOk()
            ->json('data.dispatches.0');

        $this->assertFalse($row['is_manual']);
        $this->assertSame('ทริปใหม่', $row['event_label']);
        $this->assertSame('ทริปใหม่มาแล้ว!', $row['title']);

        Queue::assertPushed(SendBroadcastNotificationJob::class);
    }

    public function test_audience_options_report_who_is_actually_reachable(): void
    {
        $schedule = $this->makeSchedule();
        $this->bookOnto($schedule, $this->makeReachableCustomer());

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/broadcasts/audiences')
            ->assertOk();

        $this->assertSame(1, $res->json('data.all_reachable'));
        $this->assertSame($schedule->id, $res->json('data.schedules.0.id'));
        $this->assertSame(1, $res->json('data.schedules.0.reachable'));
    }

    public function test_operators_cannot_be_impersonated_by_customers(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/admin/broadcasts', [
                'title' => 'x', 'body' => 'y', 'audience' => 'all',
            ])
            ->assertForbidden();
    }
}
