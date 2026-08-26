<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * โควตาจอยทริป — คนจอยไม่กินที่นั่งบนรถ จึงมีเพดานแยกของตัวเอง (join_trip_seats)
 * ที่แอดมินกำหนดได้ ไม่กำหนด = รับไม่จำกัดเหมือนพฤติกรรมเดิม
 */
class JoinTripCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(?int $joinSeats = null): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปทดสอบจอย',
            'slug' => 'join-capacity-trip',
            'type' => 'trekking',
            'location' => 'เขาใหญ่',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
            'join_trip_enabled' => true,
            'join_trip_price' => 900,
            'join_trip_seats' => $joinSeats,
        ]);
    }

    private function passengers(int $count): array
    {
        return collect(range(1, $count))->map(fn ($i) => [
            'title' => 'นาย',
            'name' => "ผู้จอยที่ {$i}",
            'phone' => '0812345678',
            'email' => "join{$i}@example.test",
        ])->all();
    }

    private function bookJoin(TripSchedule $schedule, int $passengerCount): void
    {
        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers($passengerCount),
            isJoinTrip: true,
        );
    }

    public function test_join_trip_booking_is_rejected_once_the_quota_is_used_up(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule(joinSeats: 3);

        $this->bookJoin($schedule, 2);

        $this->assertSame(2, $schedule->fresh()->join_trip_booked_seats);
        $this->assertSame(1, $schedule->fresh()->join_trip_available_seats);

        $this->expectExceptionMessage('จอยทริปรอบนี้เหลือ 1 ที่ ไม่พอสำหรับ 2 ท่าน');
        $this->bookJoin($schedule, 2);
    }

    public function test_a_full_join_quota_says_so(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule(joinSeats: 2);

        $this->bookJoin($schedule, 2);

        $this->assertTrue($schedule->fresh()->joinTripIsFull());

        $this->expectExceptionMessage('จอยทริปรอบนี้เต็มแล้ว');
        $this->bookJoin($schedule, 1);
    }

    public function test_no_quota_means_unlimited_join_trip(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule(joinSeats: null);

        $this->bookJoin($schedule, 12);

        $fresh = $schedule->fresh();
        $this->assertSame(12, $fresh->join_trip_booked_seats);
        $this->assertNull($fresh->join_trip_available_seats);
        $this->assertFalse($fresh->joinTripIsFull());
        // คนจอยไม่กินที่นั่งบนรถ ที่นั่งจึงต้องยังว่างครบ
        $this->assertSame(0, $fresh->booked_seats);
        $this->assertSame(10, $fresh->available_seats);
    }

    public function test_cancelling_a_join_booking_frees_the_quota(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule(joinSeats: 3);
        $service = app(BookingService::class);

        $booking = $service->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(3),
            isJoinTrip: true,
        );

        $this->assertSame(0, $schedule->fresh()->join_trip_available_seats);

        $service->cancelBooking($booking);

        $this->assertSame(0, $schedule->fresh()->join_trip_booked_seats);
        $this->assertSame(3, $schedule->fresh()->join_trip_available_seats);
    }

    public function test_schedule_payload_reports_the_join_quota(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule(joinSeats: 5);
        $this->bookJoin($schedule, 2);

        $this->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.join_trip_enabled', true)
            ->assertJsonPath('data.join_trip_seats', 5)
            ->assertJsonPath('data.join_trip_booked_seats', 2)
            ->assertJsonPath('data.join_trip_available_seats', 3);
    }

    public function test_admin_can_set_the_join_quota_on_a_schedule(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $schedule = $this->makeSchedule(joinSeats: null);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}", ['join_trip_seats' => 8])
            ->assertOk()
            ->assertJsonPath('data.join_trip_seats', 8);

        $this->assertSame(8, $schedule->fresh()->join_trip_seats);

        // ส่ง null กลับมา = เอาเพดานออก รับไม่จำกัดอีกครั้ง
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}", ['join_trip_seats' => null])
            ->assertOk();

        $this->assertNull($schedule->fresh()->join_trip_seats);
    }
}
