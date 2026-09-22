<?php

namespace Tests\Feature;

use App\Jobs\AnnounceDepartedTripsJob;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\TripDepartureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "รถออกเดินทางแล้ว" ต้องถึงลูกค้าเองจากพิกัดที่ไหลเข้ามา — ไม่มีปุ่มให้ใครกด
 * เพราะนาทีที่รถออกคือนาทีที่สตาฟกำลังเช็คอินลูกค้าอยู่พอดี
 */
class TripDepartedAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_van_that_starts_driving_tells_the_passengers_itself(): void
    {
        [$schedule] = $this->round();

        // จอดอยู่ที่อู่ แล้วออกวิ่งไปไกลกว่าหนึ่งกิโลครึ่ง
        $this->pin($schedule, 13.7563, 100.5018, minutesAgo: 25);
        $this->pin($schedule, 13.7760, 100.5300, minutesAgo: 2);

        (new AnnounceDepartedTripsJob)->handle(app(TripDepartureService::class));

        $push = SmartNotification::where('type', 'vehicle_departed')->firstOrFail();
        $this->assertStringContainsString('ออกเดินทางแล้ว', $push->title);
        $this->assertSame(
            1,
            ChatMessage::where('system_key', TripDepartureService::CHAT_KEY)->count(),
        );
    }

    public function test_a_van_moving_at_road_speed_counts_even_before_it_gets_far(): void
    {
        [$schedule] = $this->round();

        $this->pin($schedule, 13.7563, 100.5018, minutesAgo: 20);
        $this->pin($schedule, 13.7570, 100.5030, minutesAgo: 1, speed: 48);

        (new AnnounceDepartedTripsJob)->handle(app(TripDepartureService::class));

        $this->assertSame(1, SmartNotification::where('type', 'vehicle_departed')->count());
    }

    public function test_staff_walking_around_the_car_park_is_not_a_departure(): void
    {
        [$schedule] = $this->round();

        // ขยับไม่กี่ร้อยเมตรด้วยความเร็วคนเดิน — ยังไม่ได้ออก
        $this->pin($schedule, 13.7563, 100.5018, minutesAgo: 20, speed: 3);
        $this->pin($schedule, 13.7580, 100.5020, minutesAgo: 1, speed: 4);

        (new AnnounceDepartedTripsJob)->handle(app(TripDepartureService::class));

        $this->assertSame(0, SmartNotification::where('type', 'vehicle_departed')->count());
    }

    public function test_it_announces_once_however_often_the_job_runs(): void
    {
        [$schedule] = $this->round();
        $this->pin($schedule, 13.7563, 100.5018, minutesAgo: 25);
        $this->pin($schedule, 13.7760, 100.5300, minutesAgo: 2);

        (new AnnounceDepartedTripsJob)->handle(app(TripDepartureService::class));
        (new AnnounceDepartedTripsJob)->handle(app(TripDepartureService::class));

        $this->assertSame(1, SmartNotification::where('type', 'vehicle_departed')->count());
    }

    public function test_a_position_that_went_stale_does_not_announce_anything(): void
    {
        [$schedule] = $this->round();

        // เปิดแชร์ตอนเช้าแล้วปิดไป — พิกัดล่าสุดเก่าเกินกว่าจะเรียกว่า "ตอนนี้"
        $this->pin($schedule, 13.7563, 100.5018, minutesAgo: 120);
        $this->pin($schedule, 13.7760, 100.5300, minutesAgo: 90);

        (new AnnounceDepartedTripsJob)->handle(app(TripDepartureService::class));

        $this->assertSame(0, SmartNotification::where('type', 'vehicle_departed')->count());
    }

    public function test_movement_from_yesterdays_round_does_not_leak_into_today(): void
    {
        [$schedule] = $this->round();
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
            'departs_at' => now('Asia/Bangkok')->addDays(3)->format('Y-m-d').' 06:00:00',
        ]);

        $this->pin($schedule, 13.7563, 100.5018, minutesAgo: 25);
        $this->pin($schedule, 13.7760, 100.5300, minutesAgo: 2);

        (new AnnounceDepartedTripsJob)->handle(app(TripDepartureService::class));

        $this->assertSame(0, SmartNotification::where('type', 'vehicle_departed')->count());
    }

    private function pin(
        TripSchedule $schedule,
        float $lat,
        float $lng,
        int $minutesAgo = 0,
        ?float $speed = null,
    ): void {
        VehicleLocation::create([
            'vehicle_id' => $schedule->vehicle_id,
            'latitude' => $lat,
            'longitude' => $lng,
            'speed' => $speed,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /**
     * @return array{0: TripSchedule, 1: Booking}
     */
    private function round(): array
    {
        $trip = Trip::create([
            'title' => 'ยอดดอยหลวง',
            'slug' => 'departed-'.uniqid(),
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

        // รถออกเมื่อ 15 นาทีที่แล้ว — อยู่ในช่วงที่ระบบเฝ้าดูการเคลื่อนไหว
        $departsAt = now('Asia/Bangkok')->subMinutes(15);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => $departsAt->toDateString(),
            'return_date' => $departsAt->copy()->addDay()->toDateString(),
            'departs_at' => $departsAt->format('Y-m-d H:i:s'),
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 2500,
        ]);

        return [$schedule, $booking];
    }
}
