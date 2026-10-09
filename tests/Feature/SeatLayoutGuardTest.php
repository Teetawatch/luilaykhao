<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ที่นั่งในใบจองต้องอยู่บนผังของรถเสมอ — ไม่งั้นผังโชว์ที่ว่างทั้งที่รอบเต็ม
 * และคนที่รหัสยังตรงกันไปตกผิดตำแหน่งบนตัวรถ (รอบ #312 บน production:
 * จองบนผัง 3×3 แล้วผังรถถูกเปลี่ยน หกในเก้าที่นั่งหายจากผัง)
 */
class SeatLayoutGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    /** ผัง 3 แถว × คอลัมน์ A B C — รูปเดียวกับผังที่รอบ #312 ถูกจองไว้ */
    private function gridLayout(array $columns = ['A', 'B', 'C'], int $rows = 3): array
    {
        $seats = [];
        for ($row = 1; $row <= $rows; $row++) {
            foreach ($columns as $index => $col) {
                $seats[] = ['id' => $col.$row, 'row' => $row, 'column' => $index + 1, 'col' => $col, 'label' => $col.$row];
            }
        }

        return ['rows' => $rows, 'columns' => $columns, 'seats' => $seats];
    }

    private function makeVehicle(?array $layout = null, string $name = 'รถตู้ 1'): Vehicle
    {
        return Vehicle::create([
            'name' => $name, 'type' => 'van', 'capacity' => 9,
            'seat_layout' => $layout ?? $this->gridLayout(),
        ]);
    }

    private function makeSchedule(?Vehicle $vehicle = null, array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'phu-'.uniqid(), 'type' => 'trekking',
            'location' => 'Loei', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 9, 'price_per_person' => 2990, 'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 9, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
            'vehicle_id' => $vehicle?->id,
        ], $overrides));
    }

    /** ใบจองที่นั่งตามรหัสที่ให้มาตรง ๆ (เลียนแบบข้อมูลที่มีอยู่แล้วในฐานข้อมูล) */
    private function seatBooking(TripSchedule $schedule, array $seatIds): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'payment_type' => 'full',
            'total_amount' => 2990 * count($seatIds),
            'paid_amount' => 2990 * count($seatIds),
            'qr_code' => Booking::generateQrCode(),
        ]);

        foreach ($seatIds as $i => $seatId) {
            BookingPassenger::create(['booking_id' => $booking->id, 'name' => "ผู้เดินทาง {$i}"]);
            BookingSeat::create([
                'booking_id' => $booking->id,
                'schedule_id' => $schedule->id,
                'seat_id' => $seatId,
                'passenger_name' => "ผู้เดินทาง {$i}",
            ]);
        }
        $schedule->syncBookedSeats();

        return $booking;
    }

    private function passengers(int $count): array
    {
        return collect(range(1, $count))->map(fn ($i) => [
            'title' => 'นาย', 'name' => "ผู้เดินทาง {$i}", 'phone' => '0812345678',
        ])->all();
    }

    // ─── รหัสที่ไม่มีบนผังห้ามเข้า ─────────────────────────────

    public function test_customer_booking_rejects_a_seat_that_is_not_on_the_map(): void
    {
        $schedule = $this->makeSchedule($this->makeVehicle());

        try {
            app(BookingService::class)->createBooking(
                userId: User::factory()->create()->id,
                scheduleId: $schedule->id,
                passengers: $this->passengers(1),
                seatIds: ['E2'],
            );
            $this->fail('ที่นั่งที่ไม่มีบนผังต้องจองไม่ได้');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ไม่มีที่นั่ง E2', $e->getMessage());
        }

        $this->assertDatabaseCount('booking_seats', 0);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_customer_booking_on_the_map_still_works(): void
    {
        $schedule = $this->makeSchedule($this->makeVehicle());

        $booking = app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
            seatIds: ['B1', 'C3'],
        );

        $this->assertSame(['B1', 'C3'], $booking->seats->pluck('seat_id')->sort()->values()->all());
    }

    public function test_locking_a_seat_that_is_not_on_the_map_is_rejected(): void
    {
        $schedule = $this->makeSchedule($this->makeVehicle());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/seats/lock", ['seat_ids' => ['A1', 'D4']])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'ไม่มีที่นั่ง D4 ในผังที่นั่งของรถคันนี้ กรุณาโหลดผังใหม่แล้วเลือกอีกครั้ง']);
    }

    public function test_admin_cannot_type_a_seat_that_is_not_on_the_map(): void
    {
        $schedule = $this->makeSchedule($this->makeVehicle());
        $booking = $this->seatBooking($schedule, ['A1']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", ['seat_ids' => ['Z9']])
            ->assertStatus(422);

        // ธุรกรรมถูกย้อน — ที่นั่งเดิมยังอยู่ ไม่ใช่หายไปทั้งแถว
        $this->assertSame(['A1'], $booking->seats()->pluck('seat_id')->all());
    }

    public function test_admin_can_still_type_any_seat_number_on_a_flight_round(): void
    {
        $schedule = $this->makeSchedule(null, ['transport_type' => 'flight']);
        $booking = $this->seatBooking($schedule, []);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'ผู้โดยสาร']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", ['seat_ids' => ['32K']])
            ->assertOk();

        $this->assertSame(['32K'], $booking->seats()->pluck('seat_id')->all());
    }

    public function test_admin_manual_booking_rejects_a_seat_that_is_not_on_the_map(): void
    {
        $schedule = $this->makeSchedule($this->makeVehicle());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'passengers' => [[
                    'title' => 'นาย', 'name' => 'สมชาย ใจดี', 'phone' => '0810000000',
                    'id_card' => '1234567890123', 'emergency_contact' => 'สมหญิง',
                    'emergency_phone' => '0820000000', 'halal_food' => false,
                ]],
                'seat_ids' => ['E2'],
                'status' => 'pending',
                'payment_type' => 'full',
                'send_email' => false,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'ไม่มีที่นั่ง E2 ในผังที่นั่งของรถคันนี้']);

        $this->assertDatabaseCount('bookings', 0);
    }

    // ─── แก้ผัง/เปลี่ยนรถที่ทำให้ที่นั่งที่ขายไปหลุดผัง ห้ามบันทึก ───

    public function test_editing_a_vehicle_layout_that_drops_a_booked_seat_is_rejected(): void
    {
        $vehicle = $this->makeVehicle();
        $schedule = $this->makeSchedule($vehicle);
        $booking = $this->seatBooking($schedule, ['A1', 'B2']);

        // ผังใหม่เหลือแค่คอลัมน์ A — B2 ที่ขายไปแล้วจะหายจากผัง
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}", [
                'name' => $vehicle->name, 'type' => 'van', 'capacity' => 9,
                'seat_layout' => $this->gridLayout(['A']),
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'บันทึกไม่ได้ เพราะที่นั่งที่ลูกค้าจองไว้แล้วจะหายไปจากผัง: '
                .$booking->booking_ref.' (B2) — ย้ายที่นั่งของใบจองเหล่านี้ในหน้าแก้ไขการจองก่อน หรือวาดผังให้มีรหัสที่นั่งเหล่านี้อยู่']);

        $this->assertCount(9, $vehicle->fresh()->seat_layout['seats']);
    }

    public function test_editing_a_vehicle_layout_that_keeps_every_booked_seat_is_allowed(): void
    {
        $vehicle = $this->makeVehicle();
        $schedule = $this->makeSchedule($vehicle);
        $this->seatBooking($schedule, ['A1', 'B2']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}", [
                'name' => $vehicle->name, 'type' => 'van', 'capacity' => 9,
                'seat_layout' => $this->gridLayout(['A', 'B']),
            ])
            ->assertOk();

        $this->assertCount(6, $vehicle->fresh()->seat_layout['seats']);
    }

    public function test_finished_rounds_do_not_block_editing_a_vehicle(): void
    {
        $vehicle = $this->makeVehicle();
        $past = $this->makeSchedule($vehicle, [
            'departure_date' => now()->subWeek()->toDateString(),
            'return_date' => now()->subWeek()->addDay()->toDateString(),
        ]);
        $this->seatBooking($past, ['C3']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}", [
                'name' => $vehicle->name, 'type' => 'van', 'capacity' => 9,
                'seat_layout' => $this->gridLayout(['A']),
            ])
            ->assertOk();
    }

    public function test_a_round_already_off_its_map_can_still_be_repaired(): void
    {
        // สถานะเดียวกับ #312: จองบนผัง 3×3 แล้วผังรถถูกเปลี่ยนเป็นคอลัมน์ A อย่างเดียว
        $vehicle = $this->makeVehicle($this->gridLayout(['A']));
        $schedule = $this->makeSchedule($vehicle);
        $this->seatBooking($schedule, ['A1', 'B1', 'C1']);

        // แก้เรื่องอื่นที่ไม่ทำให้แย่ลง — ต้องไม่ถูกล็อกไว้เพราะของที่หลุดอยู่ก่อนแล้ว
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}", [
                'name' => 'รถตู้ 1 (ทะเบียนใหม่)', 'type' => 'van', 'capacity' => 9,
                'seat_layout' => $this->gridLayout(['A']),
            ])
            ->assertOk();

        // วาดผังกลับเป็น 3×3 — ทางซ่อมหลัก
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$vehicle->id}", [
                'name' => 'รถตู้ 1 (ทะเบียนใหม่)', 'type' => 'van', 'capacity' => 9,
                'seat_layout' => $this->gridLayout(),
            ])
            ->assertOk();

        $this->artisan('seats:audit')->assertSuccessful();
    }

    public function test_switching_a_round_to_a_vehicle_without_the_booked_seats_is_rejected(): void
    {
        $grid = $this->makeVehicle();
        $other = $this->makeVehicle($this->gridLayout(['A', 'D', 'E']), 'รถตู้ 2');
        $schedule = $this->makeSchedule($grid);
        $this->seatBooking($schedule, ['B1']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}", ['vehicle_id' => $other->id])
            ->assertStatus(422);

        $this->assertSame($grid->id, $schedule->fresh()->vehicle_id);
    }

    public function test_adding_a_vehicle_option_to_a_round_that_sold_seats_on_its_only_van_is_rejected(): void
    {
        $vehicle = $this->makeVehicle();
        $schedule = $this->makeSchedule($vehicle);
        $this->seatBooking($schedule, ['A1']);

        // พอมีตัวเลือก หน้าจองวาดเฉพาะผังของตัวเลือก ที่นั่ง A1 ของคันเดิมจะไม่มีผังไหนแสดง
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/vehicle-options", [
                'label' => 'รถบัส', 'transport_type' => 'bus', 'seats' => 40,
            ])
            ->assertStatus(422);

        $this->assertSame(0, $schedule->vehicleOptions()->count());
    }

    // ─── ตรวจรายวัน + หน้า "สิ่งที่รอคุณ" ───────────────────────

    public function test_audit_and_action_queue_report_rounds_with_seats_off_the_map(): void
    {
        $vehicle = $this->makeVehicle($this->gridLayout(['A']));
        $schedule = $this->makeSchedule($vehicle);
        $booking = $this->seatBooking($schedule, ['A1', 'B1']);

        $this->artisan('seats:audit')
            ->expectsOutputToContain("#{$schedule->id} ".$schedule->departure_date->toDateString()
                ." ภูกระดึง — หลุดผัง 1 ที่: B1 ({$booking->booking_ref})")
            ->assertFailed();

        $group = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertOk()
            ->json('data.groups'))
            ->firstWhere('key', 'seat_layout_conflicts');

        $this->assertSame(1, $group['count']);
        $this->assertStringContainsString('B1', $group['items'][0]['detail']);
    }
}
