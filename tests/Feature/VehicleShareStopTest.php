<?php

namespace Tests\Feature;

use App\Jobs\RemindStaffToShareLocationJob;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Models\VehicleShareStop;
use App\Services\VehicleLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * สตาฟกดปิดแชร์ตำแหน่งรถเองพร้อมเหตุผล — เดิมเซิร์ฟเวอร์ไม่รู้ จึงเตือน "ตำแหน่งรถ
 * หยุดส่ง" ทั้งที่สตาฟตั้งใจปิดเพราะถึงที่หมายแล้ว
 */
class VehicleShareStopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(
            now('Asia/Bangkok')->toDateString().' 08:00:00',
            'Asia/Bangkok',
        ));
    }

    public function test_staff_record_who_stopped_sharing_when_and_why(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -180);
        $this->locate($schedule, $staff);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/vehicle-location/stop", ['reason' => 'arrived'])
            ->assertOk()
            ->assertJsonPath('data.reason', 'arrived')
            ->assertJsonPath('data.reason_label', 'ถึงที่หมายแล้ว');

        $stop = VehicleShareStop::sole();
        $this->assertSame($schedule->id, $stop->schedule_id);
        $this->assertSame($staff->id, $stop->user_id);
        $this->assertTrue($stop->stopped_at->equalTo(now()));
    }

    public function test_only_known_reasons_from_the_rounds_own_staff_are_accepted(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -180);
        $stranger = $this->user('staff');

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/vehicle-location/stop", ['reason' => 'tired'])
            ->assertStatus(422);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/vehicle-location/stop", ['reason' => 'arrived'])
            ->assertStatus(403);

        $this->assertSame(0, VehicleShareStop::count());
    }

    public function test_a_deliberate_stop_is_not_reported_as_sharing_that_died(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -180);
        $this->locate($schedule, $staff, minutesAgo: 70);
        $this->stop($schedule, $staff, 'arrived', minutesAgo: 69);

        (new RemindStaffToShareLocationJob)->handle();
        $this->assertSame(0, SmartNotification::where('type', 'staff_share_location')->count());

        $status = $this->shareStatus($schedule);
        $this->assertSame('stopped', $status['state']);
        $this->assertFalse($status['needs_attention']);
        $this->assertSame('ถึงที่หมายแล้ว', $status['stopped']['reason_label']);
        $this->assertSame($staff->name, $status['stopped']['by']['name']);
    }

    public function test_switching_back_on_puts_the_round_under_watch_again(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -180);
        $this->locate($schedule, $staff, minutesAgo: 120);
        $this->stop($schedule, $staff, 'battery', minutesAgo: 119);

        // ชาร์จแบตแล้วเปิดต่อ ส่งได้พักหนึ่งแล้วหลุดไปอีก — ต้องตามได้ตามปกติ
        $this->locate($schedule, $staff, minutesAgo: 70);

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame('stalled', SmartNotification::where('type', 'staff_share_location')->sole()->data['slot']);
        $this->assertNotSame('stopped', $this->shareStatus($schedule)['state']);
    }

    public function test_a_position_already_on_its_way_when_stopping_does_not_cancel_the_stop(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -180);
        $this->locate($schedule, $staff, minutesAgo: 71);

        $this->travel(-70)->minutes();
        app(VehicleLocationService::class)->recordStop($schedule->fresh(), $staff, 'arrived');
        // จุดสุดท้ายที่ค้างอยู่กลางทาง มาถึงเซิร์ฟเวอร์ 20 วินาทีหลังกดปิด
        $this->travel(20)->seconds();
        $this->locate($schedule, $staff);
        $this->travel(-20)->seconds();
        $this->travel(70)->minutes();

        $this->assertSame('stopped', $this->shareStatus($schedule)['state']);
    }

    public function test_stopping_before_the_van_leaves_still_gets_chased(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -180);
        // ปิดตั้งแต่ก่อนรถออก — ลูกค้าจะไม่เห็นรถทั้งวัน เหตุผลอะไรก็ต้องตาม
        $this->locate($schedule, $staff, minutesAgo: 200);
        $this->stop($schedule, $staff, 'other', minutesAgo: 190);

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame('stalled', SmartNotification::where('type', 'staff_share_location')->sole()->data['slot']);
        $this->assertTrue($this->shareStatus($schedule)['needs_attention']);
    }

    public function test_other_staff_phones_do_not_switch_sharing_back_on_after_a_stop(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -60);
        $friend = $this->user('staff');
        $schedule->staff()->attach($friend->id, ['assigned_by' => $friend->id]);

        $this->locate($schedule, $staff, minutesAgo: 30);
        $locations = app(VehicleLocationService::class);
        $this->assertNotNull($locations->autoShareMode($schedule->fresh(), null, $friend->id));

        $this->stop($schedule, $staff, 'arrived', minutesAgo: 29);

        $this->assertNull($locations->autoShareMode($schedule->fresh(), null, $friend->id));

        $row = collect(
            $this->actingAs($friend, 'sanctum')->getJson('/api/v1/staff/schedules/my')
                ->assertOk()->json('data.schedules'),
        )->firstWhere('id', $schedule->id);

        $this->assertNull($row['share_location_mode']);
        $this->assertSame('ถึงที่หมายแล้ว', $row['vehicle_share_stopped']['reason_label']);
        $this->assertSame($staff->name, $row['vehicle_share_stopped']['by']);
        $this->assertSame(
            now('Asia/Bangkok')->subMinutes(29)->format('Y-m-d H:i:s'),
            $row['vehicle_share_stopped']['at'],
        );
    }

    private function shareStatus(TripSchedule $schedule): array
    {
        return app(VehicleLocationService::class)->sharingStatus($schedule->fresh());
    }

    /** สร้างพิกัดตอน [$minutesAgo] นาทีก่อน — created_at ตามเวลานั้นด้วย */
    private function locate(TripSchedule $schedule, User $user, int $minutesAgo = 0): void
    {
        $this->travel(-$minutesAgo)->minutes();
        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'user_id' => $user->id,
            'latitude' => 13.75,
            'longitude' => 100.5,
            'recorded_at' => now(),
        ]);
        $this->travel($minutesAgo)->minutes();
    }

    private function stop(TripSchedule $schedule, User $user, string $reason, int $minutesAgo): void
    {
        $this->travel(-$minutesAgo)->minutes();
        app(VehicleLocationService::class)->recordStop($schedule->fresh(), $user, $reason);
        $this->travel($minutesAgo)->minutes();
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
    private function round(int $minutesFromNow): array
    {
        $staff = $this->user('staff');

        $trip = Trip::create([
            'title' => 'ยอดดอยหลวง',
            'slug' => 'share-stop-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้คันที่ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'ฮก 8899',
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
