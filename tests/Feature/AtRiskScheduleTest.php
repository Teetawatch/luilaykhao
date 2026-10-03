<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SmartNotification;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\AtRiskScheduleService;
use App\Services\SmsService;
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

    public function test_underfilled_sms_preview_fills_in_the_round_and_lists_paid_bookings(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 5, booked: 2);
        $paid = $this->makeBooking($schedule, pax: 1);
        $unpaid = $this->makeBooking($schedule, pax: 1, paid: 0);   // ไม่มีอะไรให้คืน
        $noPhone = $this->makeBooking($schedule, pax: 1);
        $noPhone->passengers()->update(['phone' => null]);
        $noPhone->user->forceFill(['phone' => null])->save();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/schedules/{$schedule->id}/underfilled-sms")
            ->assertOk();

        $message = $res->json('data.message');
        $this->assertStringContainsString('มีผู้เดินทางเพียง 2 ท่าน', $message);
        $this->assertStringContainsString('ขั้นต่ำรถออก 8 ท่าน', $message);
        $this->assertStringContainsString('ไลน์ @luilaykhao', $message);
        $this->assertStringContainsString('เงินคืนเต็มจำนวน', $message);
        // เหลือ 5 วัน — เมล D-7 ออกไปแล้วจริง จึงอ้างถึงได้
        $this->assertStringContainsString('ส่งอีเมลแจ้งไปแล้ว', $message);

        $refs = collect($res->json('data.recipients'))->pluck('status', 'booking_ref');
        $this->assertSame('ready', $refs[$paid->booking_ref]);
        $this->assertSame('no_phone', $refs[$noPhone->booking_ref]);
        $this->assertArrayNotHasKey($unpaid->booking_ref, $refs->all());
        $this->assertSame(1, $res->json('data.counts.ready'));
    }

    public function test_underfilled_sms_template_does_not_mention_an_email_before_it_was_sent(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 12, booked: 2);

        $message = app(AtRiskScheduleService::class)->underfilledSmsTemplate($schedule);

        $this->assertStringNotContainsString('อีเมล', $message);
    }

    public function test_sending_underfilled_sms_reaches_each_paid_booking_once(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 5, booked: 3);
        $first = $this->makeBooking($schedule, pax: 2);
        $second = $this->makeBooking($schedule, pax: 1);

        $text = 'รอบเดินทางนี้มีผู้เดินทางเพียง 3 ท่านครับ แจ้งเลขบัญชีที่ไลน์ @luilaykhao ครับ';

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/underfilled-sms", ['message' => $text])
            ->assertOk()
            ->assertJsonPath('data.queued', 2);

        foreach ([$first, $second] as $booking) {
            $this->assertDatabaseHas('sms_logs', [
                'booking_id' => $booking->id,
                'sms_type' => SmsService::UNDERFILLED_NOTICE,
                'dedupe_key' => 's'.$schedule->id,
                'message' => $text,
                'status' => 'pending',
            ]);
        }

        // กดซ้ำไม่ส่งซ้ำ
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/underfilled-sms", ['message' => $text])
            ->assertStatus(422);

        // ใบที่เพิ่งจ่ายทีหลังได้รับในการกดครั้งถัดไป — ใบเดิมไม่ได้ซ้ำ
        $late = $this->makeBooking($schedule, pax: 1);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/underfilled-sms", ['message' => $text])
            ->assertOk()
            ->assertJsonPath('data.queued', 1)
            ->assertJsonPath('data.already', 2);

        $this->assertSame(3, SmsLog::where('sms_type', SmsService::UNDERFILLED_NOTICE)->count());
        $this->assertDatabaseHas('sms_logs', ['booking_id' => $late->id, 'sms_type' => SmsService::UNDERFILLED_NOTICE]);

        $row = collect(
            $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/schedules/at-risk')->json('data.schedules')
        )->firstWhere('id', $schedule->id);
        $this->assertSame(3, $row['underfilled_sms_count']);
        $this->assertNotNull($row['underfilled_sms_at']);
    }

    public function test_underfilled_sms_is_refused_for_full_or_cancelled_rounds(): void
    {
        $trip = $this->makeTrip();
        $full = $this->makeSchedule($trip, 5, booked: 8);
        $this->makeBooking($full);
        $cancelled = $this->makeSchedule($trip, 5, booked: 2, attributes: ['status' => 'cancelled']);
        $this->makeBooking($cancelled);

        foreach ([$full, $cancelled] as $schedule) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson("/api/v1/admin/schedules/{$schedule->id}/underfilled-sms", ['message' => 'ทดสอบ'])
                ->assertStatus(422);
        }

        $this->assertSame(0, SmsLog::count());
    }

    public function test_underfilled_sms_requires_an_admin(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 5, booked: 2);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/underfilled-sms", ['message' => 'ทดสอบ'])
            ->assertForbidden();
    }
}
