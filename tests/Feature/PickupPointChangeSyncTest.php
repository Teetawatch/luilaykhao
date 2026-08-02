<?php

namespace Tests\Feature;

use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * จุดรับถูกเก็บสองที่: หัวการจอง (bookings.pickup_point_id) และรายคน
 * (booking_passengers.pickup_point_id) — หน้าสตาฟ/คนขับอ่านรายคนก่อนเสมอ
 * การเปลี่ยนจุดรับจึงต้องย้ายผู้โดยสารที่ยังยืนจุดเดิมตามไปด้วย
 */
class PickupPointChangeSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Test Trip',
            'slug' => 'test-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makePickup(TripSchedule $schedule, string $region, string $location, int $sort): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => $region,
            'region_label' => $region === 'bangkok' ? 'กรุงเทพฯ' : 'นนทบุรี',
            'pickup_location' => $location,
            'price' => 1500,
            'sort_order' => $sort,
        ]);
    }

    private function passengers(int $count, ?int $pickupPointIdForSecond = null): array
    {
        return collect(range(1, $count))->map(fn ($i) => array_filter([
            'title' => 'นาย',
            'name' => "ผู้เดินทาง {$i}",
            'phone' => '0812345678',
            'pickup_point_id' => $i === 2 ? $pickupPointIdForSecond : null,
        ], fn ($v) => $v !== null))->all();
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_changing_pickup_point_moves_passengers_standing_at_the_old_point(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $old = $this->makePickup($schedule, 'bangkok', 'BTS หมอชิต', 1);
        $new = $this->makePickup($schedule, 'nonthaburi', 'เซ็นทรัล รัตนาธิเบศร์', 2);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
            pickupPointId: $old->id,
        );

        $this->assertSame(
            [$old->id, $old->id],
            $booking->passengers()->pluck('pickup_point_id')->map(fn ($id) => (int) $id)->all(),
        );

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'pickup_point_id' => $new->id,
                'pickup_region' => $new->region,
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($new->id, $booking->pickup_point_id);
        // รายคนต้องย้ายตาม ไม่งั้นหน้าสตาฟยังจัดกลุ่มเข้าจุดเดิม
        foreach ($booking->passengers as $passenger) {
            $this->assertSame($new->id, (int) $passenger->pickup_point_id);
        }
    }

    public function test_admin_changing_pickup_point_keeps_a_passenger_who_picked_a_different_point(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $old = $this->makePickup($schedule, 'bangkok', 'BTS หมอชิต', 1);
        $ownChoice = $this->makePickup($schedule, 'nonthaburi', 'เซ็นทรัล รัตนาธิเบศร์', 2);
        $new = $this->makePickup($schedule, 'bangkok', 'อนุสาวรีย์ชัยฯ', 3);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2, $ownChoice->id),
            pickupPointId: $old->id,
        );

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'pickup_point_id' => $new->id,
                'pickup_region' => $new->region,
            ])
            ->assertOk();

        $passengers = $booking->fresh()->passengers->sortBy('id')->values();
        $this->assertSame($new->id, (int) $passengers[0]->pickup_point_id);
        // คนที่เลือกจุดของตัวเองไว้ต่างหาก ต้องไม่ถูกทับ
        $this->assertSame($ownChoice->id, (int) $passengers[1]->pickup_point_id);
    }

    public function test_customer_change_pickup_moves_passengers_standing_at_the_old_point(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $old = $this->makePickup($schedule, 'bangkok', 'BTS หมอชิต', 1);
        $ownChoice = $this->makePickup($schedule, 'nonthaburi', 'เซ็นทรัล รัตนาธิเบศร์', 2);
        $new = $this->makePickup($schedule, 'bangkok', 'อนุสาวรีย์ชัยฯ', 3);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2, $ownChoice->id),
            pickupPointId: $old->id,
        );

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/change-pickup", [
                'pickup_point_id' => $new->id,
            ])
            ->assertOk();

        $passengers = $booking->fresh()->passengers->sortBy('id')->values();
        $this->assertSame($new->id, (int) $passengers[0]->pickup_point_id);
        $this->assertSame($ownChoice->id, (int) $passengers[1]->pickup_point_id);
    }

    /** แอดมินตั้งจุดรับให้แต่ละคนคนละจุดจากหน้าจัดการการจอง */
    public function test_admin_can_set_a_different_pickup_point_per_passenger(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $old = $this->makePickup($schedule, 'bangkok', 'BTS หมอชิต', 1);
        $other = $this->makePickup($schedule, 'nonthaburi', 'เซ็นทรัล รัตนาธิเบศร์', 2);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
            pickupPointId: $old->id,
        );

        $existing = $booking->passengers->sortBy('id')->values();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'passengers' => [
                    ['id' => $existing[0]->id, 'name' => $existing[0]->name, 'pickup_point_id' => $old->id],
                    ['id' => $existing[1]->id, 'name' => $existing[1]->name, 'pickup_point_id' => $other->id],
                ],
            ])
            ->assertOk();

        $passengers = $booking->fresh()->passengers->sortBy('id')->values();
        $this->assertSame($old->id, (int) $passengers[0]->pickup_point_id);
        $this->assertSame($other->id, (int) $passengers[1]->pickup_point_id);
    }

    /**
     * ส่งจุดรายคนมาพร้อมกับเปลี่ยนจุดของการจอง — ค่ารายคนต้องเป็นใหญ่
     * ไม่งั้นการย้ายตามจุดของการจองจะทับสิ่งที่แอดมินเพิ่งเลือกให้ทีละคน
     */
    public function test_explicit_per_passenger_pickup_wins_over_the_booking_level_move(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $old = $this->makePickup($schedule, 'bangkok', 'BTS หมอชิต', 1);
        $new = $this->makePickup($schedule, 'bangkok', 'อนุสาวรีย์ชัยฯ', 2);
        $other = $this->makePickup($schedule, 'nonthaburi', 'เซ็นทรัล รัตนาธิเบศร์', 3);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2),
            pickupPointId: $old->id,
        );

        $existing = $booking->passengers->sortBy('id')->values();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'pickup_point_id' => $new->id,
                'passengers' => [
                    ['id' => $existing[0]->id, 'name' => $existing[0]->name, 'pickup_point_id' => $new->id],
                    ['id' => $existing[1]->id, 'name' => $existing[1]->name, 'pickup_point_id' => $other->id],
                ],
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($new->id, $booking->pickup_point_id);

        $passengers = $booking->passengers->sortBy('id')->values();
        $this->assertSame($new->id, (int) $passengers[0]->pickup_point_id);
        $this->assertSame($other->id, (int) $passengers[1]->pickup_point_id);
    }

    /** เว้นว่าง = กลับไปใช้จุดของการจอง */
    public function test_blank_per_passenger_pickup_clears_the_passengers_own_point(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $old = $this->makePickup($schedule, 'bangkok', 'BTS หมอชิต', 1);
        $ownChoice = $this->makePickup($schedule, 'nonthaburi', 'เซ็นทรัล รัตนาธิเบศร์', 2);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(2, $ownChoice->id),
            pickupPointId: $old->id,
        );

        $existing = $booking->passengers->sortBy('id')->values();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'passengers' => [
                    ['id' => $existing[0]->id, 'name' => $existing[0]->name, 'pickup_point_id' => $old->id],
                    ['id' => $existing[1]->id, 'name' => $existing[1]->name, 'pickup_point_id' => ''],
                ],
            ])
            ->assertOk();

        $passengers = $booking->fresh()->passengers->sortBy('id')->values();
        $this->assertSame($old->id, (int) $passengers[0]->pickup_point_id);
        $this->assertNull($passengers[1]->pickup_point_id);
    }

    /**
     * ที่นั่งส่งมาเรียงตามผู้โดยสาร คนที่ยังไม่มีที่นั่งจะเว้นช่องว่างไว้ —
     * ชื่อบนที่นั่งต้องไม่เลื่อนไปเป็นคนถัดไป
     */
    public function test_a_blank_seat_in_the_middle_does_not_shift_the_names(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $point = $this->makePickup($schedule, 'bangkok', 'BTS หมอชิต', 1);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengers(3),
            pickupPointId: $point->id,
        );

        $existing = $booking->passengers->sortBy('id')->values();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'seat_ids' => ['A1', '', 'A3'],
                'passengers' => $existing->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(),
            ])
            ->assertOk();

        $seats = $booking->fresh()->seats->sortBy('seat_id')->values();
        $this->assertCount(2, $seats);
        $this->assertSame('A1', $seats[0]->seat_id);
        $this->assertSame($existing[0]->name, $seats[0]->passenger_name);
        $this->assertSame('A3', $seats[1]->seat_id);
        // คนที่สาม ไม่ใช่คนที่สอง
        $this->assertSame($existing[2]->name, $seats[1]->passenger_name);
    }
}
