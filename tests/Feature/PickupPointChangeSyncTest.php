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
}
