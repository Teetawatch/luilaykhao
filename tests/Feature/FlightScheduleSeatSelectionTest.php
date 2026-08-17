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
 * รอบที่เดินทางด้วยเครื่องบิน (transport_type = flight) — สายการบินเป็นคนจัด
 * ที่นั่ง ลูกค้าจึงเลือกเองไม่ได้ทุกช่องทาง แล้วทีมงานค่อยกรอกเลขที่นั่งจริง
 * กลับเข้าการจองผ่านหน้าแอดมิน
 */
class FlightScheduleSeatSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'ทริปญี่ปุ่น',
            'slug' => 'japan-trip',
            'type' => 'trekking',
            'location' => 'Hokkaido',
            'difficulty' => 'easy',
            'duration_days' => 5,
            'max_participants' => 20,
            'price_per_person' => 49000,
            'status' => 'active',
            'destination_type' => 'international',
            'country_code' => 'JP',
        ]);
    }

    private function makeSchedule(Trip $trip, string $transportType): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(2)->toDateString(),
            'return_date' => now()->addMonths(2)->addDays(4)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => $transportType,
            'status' => 'open',
        ]);
    }

    private function passengers(int $count): array
    {
        return collect(range(1, $count))->map(fn ($i) => [
            'title' => 'นาย',
            'name' => "ผู้เดินทาง {$i}",
            'phone' => '0812345678',
            'email' => "p{$i}@example.test",
        ])->all();
    }

    public function test_seat_endpoint_reports_no_seat_map_for_a_flight_round(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 'flight');

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/schedules/{$schedule->id}/seats");

        $response->assertOk()
            ->assertJsonPath('data.has_seat_map', false)
            ->assertJsonPath('data.seats', [])
            ->assertJsonPath('data.total_seats', 20);

        $this->assertNotEmpty($response->json('data.seat_selection_disabled_reason'));
    }

    public function test_seat_endpoint_still_returns_a_map_for_a_van_round(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 'van');

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/schedules/{$schedule->id}/seats")
            ->assertOk()
            ->assertJsonPath('data.has_seat_map', true);
    }

    public function test_locking_a_seat_on_a_flight_round_is_rejected(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 'flight');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/schedules/{$schedule->id}/seats/lock", [
                'seat_ids' => ['A1'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'รอบนี้เดินทางโดยเครื่องบิน ที่นั่งจัดโดยสายการบิน ไม่ต้องเลือกที่นั่งเอง');
    }

    public function test_booking_a_flight_round_without_seats_succeeds(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule($this->makeTrip(), 'flight');
        $user = User::factory()->create();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
        );

        $this->assertDatabaseCount('booking_seats', 0);
        $this->assertSame(2, $schedule->fresh()->booked_seats);
        $this->assertSame(2, $booking->passengers()->count());
    }

    /**
     * แอปรุ่นเก่าที่ยังจำผังที่นั่งไว้อาจส่ง seat_ids ติดมา — ต้องไม่ทำให้จองไม่ผ่าน
     * และต้องไม่บันทึกเบอร์ที่นั่งของรถลงในรอบที่บินไป
     */
    public function test_seat_ids_sent_for_a_flight_round_are_ignored(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule($this->makeTrip(), 'flight');
        $user = User::factory()->create();

        app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(1),
            seatIds: ['A1'],
        );

        $this->assertDatabaseCount('booking_seats', 0);
        $this->assertSame(1, $schedule->fresh()->booked_seats);
    }

    public function test_rescheduling_onto_a_flight_round_releases_the_old_seats(): void
    {
        Mail::fake();

        $trip = $this->makeTrip();
        $vanRound = $this->makeSchedule($trip, 'van');
        $flightRound = $this->makeSchedule($trip, 'flight');
        $user = User::factory()->create();

        $service = app(BookingService::class);
        $booking = $service->createBooking(
            userId: $user->id,
            scheduleId: $vanRound->id,
            passengers: $this->passengers(2),
            seatIds: ['A1', 'B1'],
        );

        $this->assertDatabaseCount('booking_seats', 2);

        // ไม่ส่งที่นั่งใหม่มาเลย — รอบปลายทางบินไป จึงไม่ต้องเลือก
        $rescheduled = $service->rescheduleBooking($booking, $flightRound->id);

        $this->assertSame($flightRound->id, $rescheduled->schedule_id);
        $this->assertDatabaseCount('booking_seats', 0);
        $this->assertSame(0, $vanRound->fresh()->booked_seats);
        $this->assertSame(2, $flightRound->fresh()->booked_seats);
    }

    public function test_admin_can_create_a_flight_round_and_assign_seats_to_passengers(): void
    {
        Mail::fake();

        $trip = $this->makeTrip();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/schedules', [
                'trip_id' => $trip->id,
                'departure_date' => now()->addMonths(3)->toDateString(),
                'return_date' => now()->addMonths(3)->addDays(4)->toDateString(),
                'total_seats' => 20,
                'transport_type' => 'flight',
            ])
            ->assertCreated();

        $schedule = TripSchedule::where('transport_type', 'flight')->firstOrFail();
        $customer = User::factory()->create();

        $booking = app(BookingService::class)->createBooking(
            userId: $customer->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
        );

        // ทีมงานกรอกเลขที่นั่งจากสายการบินให้ทีละคน
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'seat_ids' => ['12A', '12B'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('booking_seats', [
            'booking_id' => $booking->id,
            'schedule_id' => $schedule->id,
            'seat_id' => '12A',
        ]);
        $this->assertDatabaseHas('booking_seats', [
            'booking_id' => $booking->id,
            'schedule_id' => $schedule->id,
            'seat_id' => '12B',
        ]);
    }
}
