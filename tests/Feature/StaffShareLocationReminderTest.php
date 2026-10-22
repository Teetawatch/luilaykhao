<?php

namespace Tests\Feature;

use App\Jobs\RemindStaffToShareLocationJob;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * การเตือนที่ดังทั้งที่สตาฟทำถูกแล้ว คือการเตือนที่คนจะเลิกอ่าน — งานนี้จึง
 * เตือนเฉพาะรอบที่ "ควรมีตำแหน่งรถแล้วแต่ยังไม่มี"
 */
class StaffShareLocationReminderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ตรึงนาฬิกาไว้ตอนเช้า — เทสพวกนี้นับ "อีกกี่นาทีรถออก" จากเวลาจริงของเครื่อง
     * ถ้ารันตอนห้าทุ่มครึ่ง "อีก 45 นาที" จะข้ามไปเป็นรอบของพรุ่งนี้ แล้วงานที่
     * มองเฉพาะรอบของวันนี้ก็จะไม่เจออะไรเลย
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(
            now('Asia/Bangkok')->toDateString().' 08:00:00',
            'Asia/Bangkok',
        ));
    }

    public function test_staff_are_reminded_shortly_before_the_van_leaves(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: 45);

        (new RemindStaffToShareLocationJob)->handle();

        $push = SmartNotification::where('type', 'staff_share_location')->firstOrFail();
        $this->assertSame($staff->id, $push->user_id);
        $this->assertStringContainsString('ฮก 8899', $push->body);
    }

    public function test_a_round_already_sending_its_position_stays_quiet(): void
    {
        [$schedule] = $this->round(minutesFromNow: 45);

        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'latitude' => 13.75,
            'longitude' => 100.5,
            'recorded_at' => now(),
        ]);

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(0, SmartNotification::where('type', 'staff_share_location')->count());
    }

    public function test_a_position_from_this_morning_does_not_count_as_sharing_now(): void
    {
        [$schedule] = $this->round(minutesFromNow: 45);

        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'latitude' => 13.75,
            'longitude' => 100.5,
            'recorded_at' => now()->subHours(3),
        ]);

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(1, SmartNotification::where('type', 'staff_share_location')->count());
    }

    public function test_it_is_too_early_the_day_before(): void
    {
        [$schedule] = $this->round(minutesFromNow: 45);
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'departs_at' => now('Asia/Bangkok')->addDay()->setTime(6, 0)->format('Y-m-d H:i:s'),
        ]);

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(0, SmartNotification::where('type', 'staff_share_location')->count());
    }

    public function test_it_reminds_once_a_day_however_often_it_runs(): void
    {
        $this->round(minutesFromNow: 45);

        (new RemindStaffToShareLocationJob)->handle();
        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(1, SmartNotification::where('type', 'staff_share_location')->count());
    }

    public function test_a_round_with_no_departure_time_is_reminded_in_the_morning(): void
    {
        [$schedule] = $this->round(minutesFromNow: 45);
        // รอบที่แอดมินไม่ได้กรอกเวลารถออก — ใช้ 06:00 เวลาไทยเป็นตัวแทน
        $schedule->update([
            'departs_at' => null,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
        ]);

        // 05:30 ของเช้าวันเดินทาง = ครึ่งชั่วโมงก่อนเวลาตัวแทน จึงต้องเตือน
        $this->travelToThai('05:30');
        (new RemindStaffToShareLocationJob)->handle();
        $this->assertSame(1, SmartNotification::where('type', 'staff_share_location')->count());
    }

    public function test_it_does_not_wait_until_lunchtime_to_remind(): void
    {
        [$schedule] = $this->round(minutesFromNow: 45);
        $schedule->update([
            'departs_at' => null,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
        ]);

        // เที่ยงวัน = รถออกไปหกชั่วโมงแล้ว เตือนตอนนี้ไม่มีประโยชน์กับใคร
        $this->travelToThai('12:00');
        (new RemindStaffToShareLocationJob)->handle();
        $this->assertSame(0, SmartNotification::where('type', 'staff_share_location')->count());
    }

    public function test_sharing_that_dies_mid_trip_is_flagged(): void
    {
        // รถออกไปสามชั่วโมงแล้ว — พ้นช่วงเตือน "ก่อนรถออก" ไปแล้ว
        [$schedule] = $this->round(minutesFromNow: -180);

        // เปิดแชร์ตอนเช้าจริง แล้วเงียบไปตั้งแต่ชั่วโมงที่แล้ว (เครื่องรีสตาร์ต)
        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'latitude' => 13.75,
            'longitude' => 100.5,
            'recorded_at' => now()->subMinutes(70),
        ]);

        (new RemindStaffToShareLocationJob)->handle();

        $push = SmartNotification::where('type', 'staff_share_location')->firstOrFail();
        $this->assertSame('stalled', $push->data['slot']);
        $this->assertStringContainsString('หยุดส่ง', $push->title);
    }

    public function test_a_round_that_never_shared_is_not_nagged_again_mid_trip(): void
    {
        $this->round(minutesFromNow: -180);

        // ไม่เคยมีพิกัดเข้ามาเลย — เตือนไปแล้วตอนก่อนรถออก ไม่ต้องตามจิกอีก
        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(0, SmartNotification::where('type', 'staff_share_location')->count());
    }

    public function test_a_short_signal_gap_on_the_road_is_not_a_failure(): void
    {
        [$schedule] = $this->round(minutesFromNow: -180);

        // เงียบไป 25 นาที — อุโมงค์กับทางเขาเป็นแบบนี้เป็นปกติ
        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'latitude' => 13.75,
            'longitude' => 100.5,
            'recorded_at' => now()->subMinutes(25),
        ]);

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(0, SmartNotification::where('type', 'staff_share_location')->count());
    }

    public function test_staff_are_nudged_again_and_the_office_is_told_once_the_van_has_left(): void
    {
        [$schedule, $staff] = $this->round(minutesFromNow: -20);
        $staff->update(['name' => 'น้องเอ', 'phone' => '0812345678']);

        $admin = $this->user('admin');
        $operator = $this->user('operator');
        $this->user('customer');

        (new RemindStaffToShareLocationJob)->handle();

        $staffPush = SmartNotification::where('type', 'staff_share_location')->firstOrFail();
        $this->assertSame($staff->id, $staffPush->user_id);
        $this->assertSame('late', $staffPush->data['slot']);
        $this->assertSame('staff_manifest', $staffPush->data['route']);

        $ops = SmartNotification::where('type', RemindStaffToShareLocationJob::OPS_TYPE)->get();
        $this->assertEqualsCanonicalizing([$admin->id, $operator->id], $ops->pluck('user_id')->all());
        $this->assertStringContainsString('น้องเอ 0812345678', $ops->first()->body);
        $this->assertStringContainsString('ฮก 8899', $ops->first()->body);
        $this->assertSame('/admin/tracking', $ops->first()->data['url']);
    }

    public function test_the_late_alert_goes_out_once_however_often_the_job_runs(): void
    {
        $this->round(minutesFromNow: -20);
        $this->user('admin');

        (new RemindStaffToShareLocationJob)->handle();
        $this->travel(10)->minutes();
        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(1, SmartNotification::where('type', 'staff_share_location')->count());
        $this->assertSame(1, SmartNotification::where('type', RemindStaffToShareLocationJob::OPS_TYPE)->count());
    }

    public function test_an_admin_who_is_staff_on_the_round_is_not_told_twice(): void
    {
        [, $staff] = $this->round(minutesFromNow: -20);
        Role::firstOrCreate(['name' => 'admin']);
        $staff->assignRole('admin');

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(1, SmartNotification::where('user_id', $staff->id)->count());
        $this->assertSame(0, SmartNotification::where('type', RemindStaffToShareLocationJob::OPS_TYPE)->count());
    }

    public function test_a_van_that_is_sharing_after_leaving_raises_nothing(): void
    {
        [$schedule] = $this->round(minutesFromNow: -20);
        $this->user('admin');

        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'latitude' => 13.75,
            'longitude' => 100.5,
            'recorded_at' => now()->subMinutes(2),
        ]);

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(0, SmartNotification::count());
    }

    public function test_the_first_reminder_does_not_repeat_as_before_once_the_van_has_left(): void
    {
        [$schedule] = $this->round(minutesFromNow: 30);

        (new RemindStaffToShareLocationJob)->handle();
        $this->assertSame('before', SmartNotification::firstOrFail()->data['slot']);

        // รถออกไปแล้ว 20 นาที ยังไม่มีพิกัด = จังหวะ late ไม่ใช่ before ซ้ำ
        $this->travel(50)->minutes();
        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(
            ['before', 'late'],
            SmartNotification::where('type', 'staff_share_location')->orderBy('id')->get()
                ->map(fn ($n) => $n->data['slot'])->all(),
        );
    }

    public function test_the_late_alert_stops_two_hours_after_departure(): void
    {
        $this->round(minutesFromNow: -150);
        $this->user('admin');

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(0, SmartNotification::count());
    }

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** ย้ายนาฬิกาไปที่เวลาไทยที่กำหนดของวันนี้ */
    private function travelToThai(string $hhmm): void
    {
        $target = Carbon::parse(
            now('Asia/Bangkok')->toDateString().' '.$hhmm,
            'Asia/Bangkok',
        );
        $this->travelTo($target);
    }

    public function test_a_round_without_a_van_is_skipped(): void
    {
        [$schedule] = $this->round(minutesFromNow: 45);
        $schedule->update(['vehicle_id' => null]);

        (new RemindStaffToShareLocationJob)->handle();

        $this->assertSame(0, SmartNotification::where('type', 'staff_share_location')->count());
    }

    /**
     * @return array{0: TripSchedule, 1: User}
     */
    private function round(int $minutesFromNow): array
    {
        if (! Role::where('name', 'staff')->exists()) {
            Role::create(['name' => 'staff']);
        }

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $trip = Trip::create([
            'title' => 'ยอดดอยหลวง',
            'slug' => 'share-loc-'.uniqid(),
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

        // departs_at เก็บเป็นเวลาไทยตรง ๆ (ดู reference: departs_at timezone)
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
