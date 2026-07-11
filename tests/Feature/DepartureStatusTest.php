<?php

namespace Tests\Feature;

use App\Http\Resources\TripScheduleResource;
use App\Models\BroadcastDispatch;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ระบบสถานะการันตีออกเดินทาง (Trip Status) — สถานะ 3 ระดับตามที่นั่งที่จองแล้ว
 * + การยิง push/เปิดส่วนลดอัตโนมัติเมื่อรอบข้ามแถบขึ้น
 */
class DepartureStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Doi Luang Chiang Dao',
            'slug' => 'doi-luang-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'hard',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2000,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, int $total = 12, int $booked = 0, bool $charter = false): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => $total,
            'booked_seats' => $booked,
            'transport_type' => 'van',
            'status' => 'open',
            'is_charter' => $charter,
        ]);
    }

    /** @return array<int, array<string, string>> */
    private function passengers(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'title' => 'Mr.',
                'name' => "Traveler {$i}",
                'phone' => '08100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'email' => "t{$i}@example.test",
            ];
        }

        return $rows;
    }

    public function test_departure_status_reflects_booked_seat_bands(): void
    {
        $trip = $this->makeTrip();

        $this->assertSame('waiting', $this->makeSchedule($trip, booked: 3)->departureStatus());
        $this->assertSame('almost_ready', $this->makeSchedule($trip, booked: 5)->departureStatus());
        $this->assertSame('almost_ready', $this->makeSchedule($trip, booked: 7)->departureStatus());
        $this->assertSame('guaranteed', $this->makeSchedule($trip, booked: 8)->departureStatus());
        $this->assertSame('guaranteed', $this->makeSchedule($trip, booked: 11)->departureStatus());

        // เหมาคัน → ไม่มีสถานะการันตี (ออกแน่นอนอยู่แล้ว)
        $this->assertNull($this->makeSchedule($trip, booked: 2, charter: true)->departureStatus());
    }

    public function test_schedule_resource_exposes_status_and_thresholds(): void
    {
        $trip = $this->makeTrip();
        // booked_seats ถูก sync จากการจองจริงตอนเรียก endpoint จึงทดสอบ resource ตรง ๆ
        $schedule = $this->makeSchedule($trip, booked: 6);

        $array = (new TripScheduleResource($schedule))->toArray(request());

        $this->assertSame('almost_ready', $array['departure_status']);
        $this->assertSame(2, $array['seats_to_guarantee']);
        $this->assertSame(8, $array['guarantee_min_seats']);
        $this->assertSame(5, $array['almost_ready_min_seats']);
    }

    public function test_booking_into_almost_ready_band_blasts_and_opens_auto_discount(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, total: 12, booked: 0);

        // 5 ผู้เดินทางในครั้งเดียว → ข้ามจาก waiting เข้า almost_ready
        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(5),
        );

        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'almost_ready',
            'dedupe_key' => "almost_ready:{$schedule->id}",
        ]);

        // เปิดส่วนลดอัตโนมัติ (flash sale รายรอบ) จริง
        $schedule->refresh();
        $this->assertTrue((bool) $schedule->flash_sale_enabled);
        $this->assertNotNull($schedule->flash_sale_price);
        $this->assertLessThan((float) $schedule->original_price, (float) $schedule->flash_sale_price);
    }

    public function test_booking_into_guaranteed_band_blasts_guaranteed(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, total: 12, booked: 0);

        // 8 ผู้เดินทาง → ข้ามไป guaranteed โดยตรง (ยิงแค่ guaranteed ไม่ยิง almost)
        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(8),
        );

        $this->assertDatabaseHas('broadcast_dispatches', [
            'event_type' => 'guaranteed',
            'dedupe_key' => "guaranteed:{$schedule->id}",
        ]);
        $this->assertSame(0, BroadcastDispatch::where('event_type', 'almost_ready')->count());
    }

    public function test_waiting_band_booking_does_not_blast_status(): void
    {
        Mail::fake();
        Queue::fake();

        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, total: 12, booked: 0);

        // 3 ผู้เดินทาง → ยังอยู่ waiting ไม่ยิงสถานะ
        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(3),
        );

        $this->assertSame(0, BroadcastDispatch::where('event_type', 'almost_ready')->count());
        $this->assertSame(0, BroadcastDispatch::where('event_type', 'guaranteed')->count());
    }
}
