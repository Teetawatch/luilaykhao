<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แอดมินลบผู้โดยสารออกจากการจอง → ต้องคืนทั้งตัวนับ booked_seats และแถวที่นั่ง
 * บนผัง (booking_seats) ไม่งั้นเบอร์ที่นั่งนั้นค้างจนคนอื่นจองซ้ำไม่ได้
 */
class AdminPassengerSeatReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $trip = Trip::create([
            'title' => 'Seat Trip', 'slug' => 'seat-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Pai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 5000, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $seatIds
     */
    private function makeBooking(array $names, array $seatIds): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'confirmed',
            'payment_type' => 'full',
            'total_amount' => 5000 * count($names),
            'paid_amount' => 5000 * count($names),
            'qr_code' => Booking::generateQrCode(),
        ]);

        foreach ($names as $name) {
            BookingPassenger::create(['booking_id' => $booking->id, 'name' => $name]);
        }
        foreach ($seatIds as $index => $seatId) {
            BookingSeat::create([
                'booking_id' => $booking->id,
                'schedule_id' => $this->schedule->id,
                'seat_id' => $seatId,
                'passenger_name' => $names[$index] ?? null,
            ]);
        }
        $this->schedule->syncBookedSeats();

        return $booking;
    }

    public function test_removing_a_passenger_frees_the_counter_and_the_seat_row(): void
    {
        $booking = $this->makeBooking(['A', 'B', 'C'], ['A1', 'A2', 'A3']);
        $this->assertSame(3, (int) $this->schedule->fresh()->booked_seats);

        $keep = $booking->passengers()->orderBy('id')->take(2)->get();

        // เลียนแบบฟอร์มแอดมินเวอร์ชันเก่าที่ส่งที่นั่งเดิมครบทั้ง 3 กลับมา
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'passengers' => $keep->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(),
                'seat_ids' => ['A1', 'A2', 'A3'],
            ])
            ->assertOk();

        $this->assertSame(2, $booking->passengers()->count());
        $this->assertSame(2, (int) $this->schedule->fresh()->booked_seats);
        $this->assertSame(['A1', 'A2'], $booking->seats()->orderBy('id')->pluck('seat_id')->all());
    }

    public function test_freed_seat_can_be_booked_by_another_booking(): void
    {
        $booking = $this->makeBooking(['A', 'B'], ['A1', 'A2']);
        $keep = $booking->passengers()->orderBy('id')->first();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'passengers' => [['id' => $keep->id, 'name' => $keep->name]],
                'seat_ids' => ['A1', 'A2'],
            ])
            ->assertOk();

        // A2 ว่างแล้ว การจองอื่นต้องหยิบไปใช้ได้ (unique schedule_id+seat_id ไม่ชน)
        $other = $this->makeBooking(['D'], []);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$other->booking_ref}", [
                'seat_ids' => ['A2'],
            ])
            ->assertOk();

        $this->assertSame(['A2'], $other->seats()->pluck('seat_id')->all());
    }

    public function test_seat_names_are_remapped_to_the_remaining_passengers(): void
    {
        $booking = $this->makeBooking(['A', 'B', 'C'], ['A1', 'A2', 'A3']);

        // ลบคนแรกออก เหลือ B, C — ชื่อบนที่นั่งต้องเลื่อนตาม
        $keep = $booking->passengers()->orderBy('id')->get()->slice(1)->values();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'passengers' => $keep->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(),
                'seat_ids' => ['A1', 'A2', 'A3'],
            ])
            ->assertOk();

        $this->assertSame(
            ['A1' => 'B', 'A2' => 'C'],
            $booking->seats()->orderBy('id')->pluck('passenger_name', 'seat_id')->all()
        );
    }

    public function test_seats_are_untouched_when_passenger_count_matches(): void
    {
        $booking = $this->makeBooking(['A', 'B'], ['A1', 'A2']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'seat_ids' => ['A1', 'A2'],
            ])
            ->assertOk();

        $this->assertSame(['A1', 'A2'], $booking->seats()->orderBy('id')->pluck('seat_id')->all());
        $this->assertSame(2, (int) $this->schedule->fresh()->booked_seats);
    }
}
