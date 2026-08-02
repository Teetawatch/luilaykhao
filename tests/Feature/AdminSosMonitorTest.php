<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SosAlert;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ศูนย์เฝ้าระวัง SOS ฝั่งแอดมิน — เดิมสัญญาณ SOS เห็นได้เฉพาะคนในทริปเดียวกัน
 * ทีมงานออฟฟิศจึงไม่รู้เรื่องเลย เทสต์นี้ยึดว่าแอดมินเห็นทุกเคสและปิดเคสได้
 */
class AdminSosMonitorTest extends TestCase
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

    private function makeAlert(string $status = 'active'): SosAlert
    {
        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว', 'slug' => 'sos-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'hard', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3500, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $customer = User::factory()->create(['phone' => '0810000000']);
        $customer->assignRole('customer');

        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3500,
            'paid_amount' => 3500,
            'payment_type' => 'full',
        ]);

        return SosAlert::create([
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'latitude' => 19.39,
            'longitude' => 98.92,
            'message' => 'ขาแพลง เดินต่อไม่ไหว',
            'contact_phone' => '0810000000',
            'status' => $status,
        ]);
    }

    public function test_admin_sees_every_alert_with_the_context_needed_to_act(): void
    {
        $alert = $this->makeAlert();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/sos')
            ->assertOk();

        $this->assertSame(1, $res->json('data.active_count'));

        $row = $res->json('data.alerts.0');
        $this->assertSame($alert->id, $row['id']);
        $this->assertSame('ขาแพลง เดินต่อไม่ไหว', $row['message']);
        $this->assertSame('0810000000', $row['contact_phone']);
        $this->assertSame('ดอยหลวงเชียงดาว', $row['trip_title']);
        // ต้องมีเลขการจอง เพื่อเปิดใบจองดูรายละเอียดผู้เดินทางต่อได้ทันที
        $this->assertNotNull($row['booking_ref']);
        $this->assertSame(19.39, $row['latitude']);
    }

    public function test_active_alerts_are_listed_before_resolved_ones(): void
    {
        $this->makeAlert('resolved');
        $active = $this->makeAlert('active');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/sos')
            ->assertOk();

        $this->assertSame($active->id, $res->json('data.alerts.0.id'));
    }

    public function test_admin_can_close_a_case_from_the_office_without_being_on_the_trip(): void
    {
        $alert = $this->makeAlert();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/sos/{$alert->id}/resolve", ['note' => 'ประสานกู้ภัยรับตัวแล้ว'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $alert->refresh();
        $this->assertSame('resolved', $alert->status);
        $this->assertSame($this->admin->id, $alert->resolved_by);
        $this->assertNotNull($alert->resolved_at);
        // โน้ตของทีมงานเก็บแยกช่อง ไม่ปนกับข้อความที่ลูกค้าพิมพ์ตอนขอความช่วยเหลือ
        $this->assertSame('ขาแพลง เดินต่อไม่ไหว', $alert->message);
        $this->assertSame('ประสานกู้ภัยรับตัวแล้ว', $alert->admin_note);
    }

    public function test_closing_an_already_closed_case_is_a_no_op(): void
    {
        $alert = $this->makeAlert('resolved');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/sos/{$alert->id}/resolve")
            ->assertOk();

        $this->assertNull($alert->fresh()->resolved_by);
    }

    public function test_active_count_endpoint_stays_cheap_and_accurate(): void
    {
        $this->makeAlert();
        $this->makeAlert();
        $this->makeAlert('resolved');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/sos/active-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    public function test_customers_cannot_reach_the_admin_sos_monitor(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/sos')
            ->assertForbidden();
    }
}
