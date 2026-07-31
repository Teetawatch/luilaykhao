<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\AtRiskScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "เรดาร์รอบเสี่ยงไม่ออก" — รวมรอบที่ใกล้เดินทางแต่คนยังไม่ครบขั้นต่ำ
 * พร้อมปุ่มชวนผู้ที่จองแล้วให้ช่วยกันหาเพื่อนมาเติม
 */
class AtRiskScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.thaibulksms.enabled', false);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'ดอยม่อนจอง', 'slug' => 'risk-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 12, 'price_per_person' => 3200, 'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, int $daysAhead, int $booked = 0, array $attributes = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($daysAhead)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($daysAhead + 1)->toDateString(),
            'total_seats' => 12, 'booked_seats' => $booked, 'status' => 'open',
            'transport_type' => 'van',
        ], $attributes));
    }

    private function makeBooking(TripSchedule $schedule, int $pax = 1, float $paid = 3200): Booking
    {
        $customer = User::factory()->create();

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => $paid,
            'paid_amount' => $paid,
            'payment_type' => 'full',
        ]);

        for ($i = 1; $i <= $pax; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id, 'title' => 'Mr.',
                'name' => "ผู้เดินทาง {$i}", 'phone' => '08100000'.$i,
            ]);
        }

        return $booking;
    }

    public function test_radar_lists_only_underfilled_rounds_inside_the_window(): void
    {
        $trip = $this->makeTrip();
        $atRisk = $this->makeSchedule($trip, 6, booked: 4);
        $this->makeSchedule($trip, 6, booked: 9);           // ครบขั้นต่ำแล้ว
        $this->makeSchedule($trip, 60, booked: 2);          // ไกลเกินกรอบเวลา
        $this->makeSchedule($trip, 5, booked: 1, attributes: ['is_charter' => true]);   // เหมาคัน
        $this->makeSchedule($trip, 5, booked: 1, attributes: ['status' => 'cancelled']); // ยกเลิกแล้ว

        $ids = app(AtRiskScheduleService::class)->atRisk()->pluck('id')->all();

        $this->assertSame([$atRisk->id], $ids);
    }

    public function test_endpoint_returns_severity_money_and_merge_candidates(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 5, booked: 3);
        $this->makeBooking($schedule, pax: 2, paid: 6400);
        $this->makeBooking($schedule, pax: 1, paid: 3200);

        // รอบพี่น้องที่มีคนเยอะกว่าและรับคนจากรอบเสี่ยงไหว
        $target = $this->makeSchedule($trip, 12, booked: 6);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/schedules/at-risk')
            ->assertOk();

        $row = collect($res->json('data.schedules'))->firstWhere('id', $schedule->id);

        $this->assertSame(3, $row['booked_seats']);
        $this->assertSame(5, $row['seats_needed']);          // ขั้นต่ำ 8 - จองแล้ว 3
        $this->assertSame(2, $row['bookings_count']);
        $this->assertEquals(9600, $row['revenue_at_risk']);
        $this->assertSame('critical', $row['severity']);     // เหลือ 5 วัน
        $this->assertSame($target->id, $row['merge_candidates'][0]['id']);
        $this->assertTrue($row['merge_candidates'][0]['reaches_minimum']);   // 6 + 3 = 9 ≥ 8

        $this->assertEquals(9600, $res->json('data.summary.revenue_at_risk'));
        $this->assertSame(8, $res->json('data.summary.min_seats'));
    }

    public function test_round_with_no_bookings_is_listed_but_not_urgent(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 3, booked: 0);

        $row = app(AtRiskScheduleService::class)->atRisk()->firstWhere('id', $schedule->id);

        // ยกเลิกได้โดยไม่มีลูกค้าเสียหาย จึงไม่ใช่เรื่องเร่งด่วนแม้เหลือ 3 วัน
        $this->assertSame('low', $row['severity']);
        $this->assertSame(0, $row['bookings_count']);
    }

    public function test_action_queue_counts_only_rounds_that_already_have_customers(): void
    {
        $trip = $this->makeTrip();
        $withCustomers = $this->makeSchedule($trip, 4, booked: 2);
        $this->makeBooking($withCustomers, pax: 2);
        $this->makeSchedule($trip, 4, booked: 0);   // ว่างเปล่า ไม่นับ

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertOk();

        $group = collect($res->json('data.groups'))->firstWhere('key', 'at_risk_schedules');

        $this->assertSame(1, $group['count']);
        $this->assertSame('ดอยม่อนจอง', $group['items'][0]['title']);
    }

    public function test_rally_nudge_notifies_every_booking_owner_once(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 6, booked: 3);
        $ownerA = User::factory()->create();

        // เจ้าของคนเดียวจองสองใบในรอบเดียวกัน — ต้องได้แจ้งเตือนครั้งเดียว
        foreach ([1, 1] as $pax) {
            Booking::create([
                'booking_ref' => Booking::generateRef(),
                'user_id' => $ownerA->id,
                'schedule_id' => $schedule->id,
                'qr_code' => Booking::generateQrCode(),
                'status' => 'confirmed',
                'total_amount' => 3200, 'paid_amount' => 3200, 'payment_type' => 'full',
            ]);
        }
        $this->makeBooking($schedule, pax: 1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/rally-nudge")
            ->assertOk()
            ->assertJsonPath('data.notified', 2);

        $this->assertSame(1, SmartNotification::where('user_id', $ownerA->id)
            ->where('type', 'schedule_rally_nudge')->count());

        $schedule->refresh();
        $this->assertNotNull($schedule->rally_nudged_at);
    }

    public function test_rally_nudge_is_rate_limited_until_the_cooldown_passes(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 6, booked: 3);
        $this->makeBooking($schedule, pax: 1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/rally-nudge")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/rally-nudge")
            ->assertStatus(422);

        // ผ่านช่วงพักแล้วกดซ้ำได้
        $this->travel(AtRiskScheduleService::NUDGE_COOLDOWN_HOURS + 1)->hours();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/rally-nudge")
            ->assertOk();
    }

    public function test_rally_nudge_rejects_rounds_that_are_already_full_or_empty(): void
    {
        $trip = $this->makeTrip();

        $full = $this->makeSchedule($trip, 6, booked: 9);
        $this->makeBooking($full, pax: 1);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$full->id}/rally-nudge")
            ->assertStatus(422);

        $empty = $this->makeSchedule($trip, 6, booked: 0);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$empty->id}/rally-nudge")
            ->assertStatus(422);
    }

    public function test_radar_requires_an_admin(): void
    {
        $this->getJson('/api/v1/admin/schedules/at-risk')->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/admin/schedules/at-risk')
            ->assertForbidden();
    }
}
