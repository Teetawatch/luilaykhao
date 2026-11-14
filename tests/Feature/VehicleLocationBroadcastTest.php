<?php

namespace Tests\Feature;

use App\Events\VehicleLocationUpdated;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\VehicleLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * พิกัดสดที่แอปลูกค้ารับผ่าน Reverb (ช่อง vehicle-tracking.{id}) — ช่องเป็นของ
 * "รถ" ซึ่งวิ่งได้หลายรอบ ข้อความจึงต้องบอกว่าพิกัดนี้ของรอบไหน แอปจะได้ไม่เอา
 * รถที่กำลังวิ่งทริปอื่นมาเล่นบนจอของคนที่รอรอบพรุ่งนี้
 */
class VehicleLocationBroadcastTest extends TestCase
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

    public function test_live_position_says_which_round_the_van_is_driving(): void
    {
        Event::fake([VehicleLocationUpdated::class]);
        [$vehicle, $schedule] = $this->vanOnRound(minutesFromNow: -30);

        $this->broadcastPosition($vehicle);

        Event::assertDispatched(
            VehicleLocationUpdated::class,
            function (VehicleLocationUpdated $event) use ($schedule, $vehicle) {
                $payload = $event->broadcastWith();

                return $payload['schedule_id'] === $schedule->id
                    && $payload['vehicle_id'] === $vehicle->id
                    && $event->broadcastAs() === 'location.updated';
            },
        );
    }

    public function test_a_van_between_rounds_broadcasts_no_round(): void
    {
        Event::fake([VehicleLocationUpdated::class]);
        // รอบของรถคันนี้อยู่สัปดาห์หน้า — วันนี้ไม่ได้วิ่งรอบไหน
        [$vehicle] = $this->vanOnRound(minutesFromNow: 7 * 24 * 60);

        $this->broadcastPosition($vehicle);

        Event::assertDispatched(
            VehicleLocationUpdated::class,
            fn (VehicleLocationUpdated $event) => $event->broadcastWith()['schedule_id'] === null,
        );
    }

    private function broadcastPosition(Vehicle $vehicle): void
    {
        $location = VehicleLocation::create([
            'vehicle_id' => $vehicle->id,
            'latitude' => 13.7563,
            'longitude' => 100.5018,
            'speed' => 42,
            'heading' => 90,
            'recorded_at' => now(),
        ]);

        app(VehicleLocationService::class)->broadcastLatest($vehicle, $location);
    }

    /**
     * @return array{0: Vehicle, 1: TripSchedule}
     */
    private function vanOnRound(int $minutesFromNow): array
    {
        $trip = Trip::create([
            'title' => 'ภูชี้ฟ้า',
            'slug' => 'broadcast-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้คันที่ 2',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'ฮข 1234',
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

        return [$vehicle, $schedule];
    }
}
