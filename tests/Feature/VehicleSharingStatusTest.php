<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\VehicleLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "รถคันนี้แชร์ตำแหน่งแล้วหรือยัง" — คำตอบเดียวที่หน้าติดตามรถของแอดมิน
 * กอง "สิ่งที่รอคุณ" และแอปสตาฟใช้ร่วมกัน
 */
class VehicleSharingStatusTest extends TestCase
{
    use RefreshDatabase;

    /** ตรึงนาฬิกาไว้ตอนเช้า — นับนาทีก่อน/หลังรถออกภายในวันเดียวกันเสมอ */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(
            now('Asia/Bangkok')->toDateString().' 08:00:00',
            'Asia/Bangkok',
        ));
    }

    private function shareStatus(TripSchedule $schedule): array
    {
        return app(VehicleLocationService::class)->sharingStatus($schedule->fresh());
    }

    public function test_the_state_follows_the_clock_until_a_position_arrives(): void
    {
        [$schedule] = $this->round(minutesFromNow: 180);
        $this->assertSame('upcoming', $this->shareStatus($schedule)['state']);

        [$soon] = $this->round(minutesFromNow: 30);
        $this->assertSame('waiting', $this->shareStatus($soon)['state']);

        // เพิ่งออกไม่ถึงสิบห้านาที — ยังไม่ใช่เรื่องที่ต้องโทรตาม
        [$justLeft] = $this->round(minutesFromNow: -10);
        $this->assertSame('waiting', $this->shareStatus($justLeft)['state']);
        $this->assertFalse($this->shareStatus($justLeft)['needs_attention']);

        [$left] = $this->round(minutesFromNow: -20);
        $this->assertSame('missing', $this->shareStatus($left)['state']);
        $this->assertTrue($this->shareStatus($left)['needs_attention']);
    }

    public function test_a_fresh_position_is_live_and_names_who_is_sharing(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -20);
        $this->locate($schedule, minutesAgo: 2, userId: $staff->id);

        $status = $this->shareStatus($schedule);

        $this->assertSame('live', $status['state']);
        $this->assertFalse($status['needs_attention']);
        $this->assertSame($staff->id, $status['sharer']['id']);
        $this->assertSame(2, $status['minutes_since']);
    }

    public function test_a_short_gap_is_not_a_failure_but_a_long_one_is(): void
    {
        [$schedule] = $this->round(minutesFromNow: -90);

        // อุโมงค์/ทางเขา — เงียบไป 25 นาทีเป็นเรื่องปกติ
        $this->locate($schedule, minutesAgo: 25);
        $status = $this->shareStatus($schedule);
        $this->assertSame('stalled', $status['state']);
        $this->assertFalse($status['needs_attention']);

        VehicleLocation::query()->delete();
        $this->locate($schedule, minutesAgo: 60);
        $this->assertTrue($this->shareStatus($schedule)['needs_attention']);
    }

    public function test_a_position_from_the_previous_trip_does_not_count(): void
    {
        [$schedule] = $this->round(minutesFromNow: -20);
        // จุดสุดท้ายของทริปเมื่อวาน — ก่อนกรอบของรอบนี้
        $this->locate($schedule, minutesAgo: 60 * 20);

        $this->assertSame('missing', $this->shareStatus($schedule)['state']);
    }

    public function test_nothing_needs_chasing_once_the_van_has_been_out_all_day(): void
    {
        $this->travelTo(Carbon::parse(now('Asia/Bangkok')->toDateString().' 20:00:00', 'Asia/Bangkok'));
        [$schedule] = $this->round(minutesFromNow: -11 * 60);

        $status = $this->shareStatus($schedule);
        $this->assertSame('missing', $status['state']);
        $this->assertFalse($status['needs_attention']);
    }

    public function test_admins_see_who_to_call_with_the_urgent_rounds_first(): void
    {
        [$ok, $okStaff] = $this->round(minutesFromNow: -30, plate: 'กข 1111');
        $this->locate($ok, minutesAgo: 1, userId: $okStaff->id);

        [, $lateStaff] = $this->round(minutesFromNow: -30, plate: 'ฮก 8899');
        $lateStaff->update(['name' => 'น้องบี', 'phone' => '0899999999']);

        // ยังไม่ถึงเวลาเปิดแชร์ — ไม่ต้องอยู่ในรายการ
        $this->round(minutesFromNow: 240, plate: 'ยังไม่ถึง');

        $rows = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/v1/admin/tracking/sharing')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame('ฮก 8899', $rows[0]['license_plate']);
        $this->assertSame('missing', $rows[0]['state']);
        $this->assertTrue($rows[0]['needs_attention']);
        $this->assertSame([['id' => $lateStaff->id, 'name' => 'น้องบี', 'phone' => '0899999999']], $rows[0]['staff']);
        $this->assertSame('live', $rows[1]['state']);
        $this->assertSame($okStaff->id, $rows[1]['sharer']['id']);
    }

    public function test_the_departure_time_is_only_printed_when_it_was_set(): void
    {
        [$schedule] = $this->round(minutesFromNow: -30);
        $schedule->update(['departs_at' => null]);

        $rows = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/v1/admin/tracking/sharing')
            ->assertOk()
            ->json('data');

        $this->assertNull($rows[0]['departs_time']);
    }

    public function test_staff_phone_numbers_stay_behind_the_admin_login(): void
    {
        [, $staff] = $this->round(minutesFromNow: -30);
        $staff->update(['phone' => '0811111111']);

        $this->getJson('/api/v1/admin/tracking/sharing')->assertUnauthorized();
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/admin/tracking/sharing')->assertForbidden();

        // ตำแหน่งรถสาธารณะยังเหมือนเดิม และไม่มีเบอร์ทีมงานหลุดออกไป
        $public = $this->getJson('/api/v1/tracking/current')->assertOk()->getContent();
        $this->assertStringNotContainsString('0811111111', $public);
    }

    public function test_the_action_queue_lists_vans_customers_cannot_see(): void
    {
        [, $staff] = $this->round(minutesFromNow: -30, plate: 'ฮก 8899');
        $staff->update(['name' => 'น้องซี', 'phone' => '0822222222']);

        $groups = collect(
            $this->actingAs($this->user('admin'), 'sanctum')
                ->getJson('/api/v1/admin/action-queue')
                ->assertOk()
                ->json('data.groups'),
        );

        $group = $groups->firstWhere('key', 'vehicle_share');
        $this->assertSame(1, $group['count']);
        $this->assertSame('/admin/tracking', $group['route']);
        $this->assertStringContainsString('ฮก 8899', $group['items'][0]['title']);
        $this->assertStringContainsString('น้องซี 0822222222', $group['items'][0]['detail']);
    }

    public function test_staff_see_when_sharing_starts_and_who_already_shares(): void
    {
        [$schedule, $me] = $this->round(minutesFromNow: 30);
        $friend = $this->user('staff');
        $friend->update(['name' => 'พี่ดี']);
        $schedule->staff()->attach($friend->id, ['assigned_by' => $friend->id]);

        $mine = fn () => collect(
            $this->actingAs($me, 'sanctum')->getJson('/api/v1/staff/schedules/my')
                ->assertOk()->json('data.schedules'),
        )->firstWhere('id', $schedule->id);

        $row = $mine();
        $this->assertSame(
            now('Asia/Bangkok')->addMinutes(30 - 90)->format('Y-m-d H:i:s'),
            $row['share_auto_starts_at'],
        );
        $this->assertNull($row['vehicle_sharer']);

        // เพื่อนส่งพิกัดอยู่ — เครื่องเราไม่ต้องเปิด และควรบอกว่าใครเป็นคนส่ง
        $this->locate($schedule, minutesAgo: 1, userId: $friend->id);
        $row = $mine();
        $this->assertSame(['id' => $friend->id, 'name' => 'พี่ดี'], $row['vehicle_sharer']);
        $this->assertNull($row['share_location_mode']);

        // เราเป็นคนส่งเอง — ไม่ใช่ "คนอื่นแชร์อยู่"
        $this->locate($schedule, minutesAgo: 0, userId: $me->id);
        $this->assertNull($mine()['vehicle_sharer']);
    }

    public function test_no_start_time_is_promised_when_the_departure_time_is_unknown(): void
    {
        [$schedule, $me] = $this->round(minutesFromNow: 30);
        $schedule->update(['departs_at' => null]);

        $row = collect(
            $this->actingAs($me, 'sanctum')->getJson('/api/v1/staff/schedules/my')
                ->assertOk()->json('data.schedules'),
        )->firstWhere('id', $schedule->id);

        $this->assertNull($row['share_auto_starts_at']);
    }

    private function locate(TripSchedule $schedule, int $minutesAgo, ?int $userId = null): void
    {
        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'user_id' => $userId,
            'latitude' => 13.75,
            'longitude' => 100.5,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{0: TripSchedule, 1: User}
     */
    private function round(int $minutesFromNow, string $plate = 'ฮก 8899'): array
    {
        $staff = $this->user('staff');

        $trip = Trip::create([
            'title' => 'ยอดดอยหลวง',
            'slug' => 'share-status-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ '.$plate,
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => $plate,
        ]);

        // departs_at เก็บเป็นเวลาไทยตรง ๆ ในคอลัมน์ชนิด UTC
        $departsAt = now('Asia/Bangkok')->addMinutes($minutesFromNow);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => $departsAt->toDateString(),
            'return_date' => $departsAt->copy()->addDay()->toDateString(),
            'departs_at' => $departsAt->format('Y-m-d H:i:s'),
            'total_seats' => 12,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return [$schedule, $staff];
    }
}
