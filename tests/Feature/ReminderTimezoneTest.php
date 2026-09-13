<?php

namespace Tests\Feature;

use App\Jobs\SendBookingRemindersJob;
use App\Jobs\SendStaffShiftRemindersJob;
use App\Jobs\SendTripReminderNotificationsJob;
use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FcmService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * งานเตือนก่อนเดินทางต้องนับวันเป็น "วันไทย" ไม่ใช่วันของ UTC
 *
 * แอปตั้งโซนเป็น UTC ช่วง 00:00-07:00 เวลาไทยจึงเป็นคนละวันกับ UTC — งานที่เขียน
 * now()->addDay() เฉย ๆ จะเลื่อนเป้าหมายไปหนึ่งวันในช่วงนั้น ข้อความ "พรุ่งนี้
 * ออกเดินทางแล้ว!" กลายเป็นไปหาคนที่รถออกเช้านี้ และ ETA ของรอบที่ออกตีสี่ไม่ยิงเลย
 *
 * เทสต์เดิมที่จับบั๊กนี้ได้ใช้เวลาจริงของเครื่อง จึงแดงเฉพาะถ้าบังเอิญรันตอนเช้ามืด
 * และเขียวทั้งวันที่เหลือ — ที่นี่ตรึงนาฬิกาไว้ที่ชั่วโมงอันตรายเลย จะได้จับได้
 * ทุกครั้งที่รัน ไม่ใช่ปีละไม่กี่หน
 */
class ReminderTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-09-13 19:00 UTC = 2026-09-14 02:00 เวลาไทย — คนละวันกันพอดี
        Carbon::setTestNow(Carbon::parse('2026-09-13 19:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** รอบที่ออกเดินทาง "พรุ่งนี้" ตามปฏิทินไทย (15 ก.ย. เพราะตอนนี้คือ 14 ก.ย. เวลาไทย) */
    private function schedule(string $departureDate, ?string $departsAt = null): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'tz-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 12, 'price_per_person' => 3200, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate,
            'return_date' => $departureDate,
            'total_seats' => 12, 'booked_seats' => 1,
            'transport_type' => 'van', 'status' => 'open',
            'departs_at' => $departsAt,
        ]);
    }

    private function bookOnto(TripSchedule $schedule): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['phone' => '0891112222'])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3200,
        ]);
    }

    public function test_the_day_before_push_targets_tomorrow_in_bangkok(): void
    {
        // เวลาไทยตอนนี้คือ 14 ก.ย. ตีสอง → "พรุ่งนี้" คือ 15 ก.ย.
        $tomorrow = $this->schedule('2026-09-15');
        $today = $this->schedule('2026-09-14');

        $tomorrowBooking = $this->bookOnto($tomorrow);
        $todayBooking = $this->bookOnto($today);

        (new SendTripReminderNotificationsJob)->handle();

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $tomorrowBooking->user_id,
            'type' => 'trip_reminder',
        ]);

        // คนที่รถออกเช้านี้ต้องไม่ได้ข้อความว่า "พรุ่งนี้ออกเดินทางแล้ว!"
        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $todayBooking->user_id,
            'type' => 'trip_reminder',
        ]);
    }

    public function test_the_week_ahead_push_counts_bangkok_days_too(): void
    {
        $inSevenDays = $this->schedule('2026-09-21');
        $booking = $this->bookOnto($inSevenDays);

        (new SendTripReminderNotificationsJob)->handle();

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $booking->user_id,
            'type' => 'trip_reminder',
        ]);
    }

    public function test_the_sms_reminder_targets_bangkok_days(): void
    {
        $tomorrow = $this->schedule('2026-09-15');
        $today = $this->schedule('2026-09-14');

        $tomorrowBooking = $this->bookOnto($tomorrow);
        $todayBooking = $this->bookOnto($today);

        (new SendBookingRemindersJob)->handle(
            app(SmsService::class),
            app(FcmService::class),
        );

        $this->assertTrue(
            SmsLog::where('booking_id', $tomorrowBooking->id)->where('sms_type', 'departure_reminder')->exists(),
        );
        $this->assertFalse(
            SmsLog::where('booking_id', $todayBooking->id)->where('sms_type', 'departure_reminder')->exists(),
        );
    }

    public function test_the_staff_shift_reminder_targets_bangkok_days(): void
    {
        $tomorrow = $this->schedule('2026-09-15');
        $staff = User::factory()->create();
        $tomorrow->staff()->attach($staff->id);

        (new SendStaffShiftRemindersJob)->handle();

        $this->assertDatabaseHas('smart_notifications', ['user_id' => $staff->id]);
    }

    public function test_the_eta_command_finds_a_round_leaving_at_four_this_morning(): void
    {
        // รอบที่ออกตีสี่ของวันนี้ (เวลาไทย) — รอบที่ต้องใช้ ETA มากที่สุด และเป็น
        // รอบที่ today() ของ UTC มองไม่เห็นเลย เพราะ UTC ยังเป็นเมื่อวาน
        $schedule = $this->schedule('2026-09-14', '2026-09-14 04:00:00');

        $vehicle = Vehicle::create([
            'name' => 'ตู้ 1', 'type' => 'van', 'capacity' => 12,
            'license_plate' => 'ฮก 1234', 'driver_name' => 'สมชาย', 'driver_phone' => '0812345678',
        ]);
        $schedule->update(['vehicle_id' => $vehicle->id]);

        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'bangkok', 'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี', 'pickup_time' => '04:00',
            'price' => 0, 'latitude' => 13.79, 'longitude' => 100.56, 'sort_order' => 0,
        ]);

        $booking = $this->bookOnto($schedule);
        $booking->update(['pickup_point_id' => $point->id]);

        // ปลอมตำแหน่งรถให้อยู่ห่างจุดรับราว 1 กม. เพื่อให้คำสั่งเดินไปจนสุดทาง
        // แล้วยิง push จริง — เป็นหลักฐานเดียวที่พิสูจน์ว่าคิวรีของคำสั่ง "เห็น"
        // รอบนี้ ถ้าเขียนคิวรีเองในเทสต์ ก็ได้แค่พิสูจน์คิวรีที่เทสต์เขียน
        Redis::shouldReceive('get')
            ->andReturn(json_encode([
                'latitude' => 13.80,
                'longitude' => 100.56,
                'speed' => 12.0,
                'recorded_at' => now()->toIso8601String(),
            ]));

        $this->artisan('eta:notify-pickups')->assertExitCode(0);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $booking->user_id,
            'type' => 'vehicle_approaching',
        ]);
    }
}
