<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * จุดรับรายคนในหน้า "จองแทนลูกค้า"
 *
 * กลุ่มเดียวกันขึ้นคนละจุดเป็นเรื่องปกติ — ไปรับที่รังสิตสองคน ที่ลาดพร้าวอีกคน
 * ในใบจองใบเดียว เดิมหน้านี้มีจุดรับช่องเดียวทั้งใบ แอดมินจึงต้องแยกเป็นสองใบจอง
 * หรือปล่อยให้ข้อมูลผิด แล้วสตาฟหน้างานไปเจอเอาตอนรถออก
 *
 * ราคาจุดรับคือ "ราคาต่อคนของโซนนั้น" ไม่ใช่ค่าบริการที่บวกเพิ่ม ยอดรวมจึงต้อง
 * บวกทีละคน ไม่ใช่คูณราคาเดียวด้วยจำนวนคน
 */
class AdminManualBookingPickupTest extends TestCase
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

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Manual Pickup Trip', 'slug' => 'manual-pickup-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function makePoint(TripSchedule $schedule, string $name, float $price): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => $name,
            'price' => $price,
        ]);
    }

    private function passengerPayload(string $name, array $overrides = []): array
    {
        return array_merge([
            'title' => 'นาย',
            'name' => $name,
            'phone' => '0810000000',
            'id_card' => '1234567890123',
            'emergency_contact' => 'สมหญิง',
            'emergency_phone' => '0820000000',
            'halal_food' => false,
        ], $overrides);
    }

    public function test_each_passenger_can_board_at_their_own_point(): void
    {
        $schedule = $this->makeSchedule();
        $rangsit = $this->makePoint($schedule, 'ปั๊ม ปตท. รังสิต', 2200);
        $ladprao = $this->makePoint($schedule, 'BTS ลาดพร้าว', 2500);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'email' => 'pickup@example.test',
                'pickup_point_id' => $rangsit->id,
                'passengers' => [
                    $this->passengerPayload('สมชาย ใจดี'),
                    $this->passengerPayload('สมหญิง ใจงาม'),
                    $this->passengerPayload('สมศักดิ์ ใจกล้า', ['pickup_point_id' => $ladprao->id]),
                ],
                'status' => 'pending',
                'payment_type' => 'full',
                'send_email' => false,
            ]);

        $res->assertCreated();
        $booking = Booking::with('passengers')->findOrFail($res->json('data.id'));

        // สองคนแรกไม่ได้เลือกเอง = ใช้จุดรับหลักของใบจอง
        $byName = $booking->passengers->keyBy('name');
        $this->assertSame($rangsit->id, $byName['สมชาย ใจดี']->pickup_point_id);
        $this->assertSame($rangsit->id, $byName['สมหญิง ใจงาม']->pickup_point_id);
        $this->assertSame($ladprao->id, $byName['สมศักดิ์ ใจกล้า']->pickup_point_id);

        // 2200 + 2200 + 2500 — ไม่ใช่ 2200 × 3
        $this->assertEqualsWithDelta(6900, (float) $booking->total_amount, 0.01);
        $this->assertSame($rangsit->id, $booking->pickup_point_id);
    }

    /**
     * ไม่ได้เลือกจุดหลักไว้ แต่ผู้โดยสารเลือกกันเอง — หัวการจองต้องไม่ว่าง
     * เพราะใบเสร็จ อีเมล และหน้าที่ยัง fallback มาที่หัวจะไม่มีอะไรให้แสดง
     */
    public function test_the_booking_head_falls_back_to_the_first_chosen_point(): void
    {
        $schedule = $this->makeSchedule();
        $ladprao = $this->makePoint($schedule, 'BTS ลาดพร้าว', 2500);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'email' => 'head@example.test',
                'passengers' => [$this->passengerPayload('สมชาย ใจดี', ['pickup_point_id' => $ladprao->id])],
                'status' => 'pending',
                'payment_type' => 'full',
                'send_email' => false,
            ]);

        $res->assertCreated();
        $booking = Booking::findOrFail($res->json('data.id'));

        $this->assertSame($ladprao->id, $booking->pickup_point_id);
        $this->assertSame('bangkok', $booking->pickup_region);
        $this->assertEqualsWithDelta(2500, (float) $booking->total_amount, 0.01);
    }

    /** จุดรับเป็นของรอบ — id ของรอบอื่นต้องไม่ถูกรับไว้เงียบ ๆ */
    public function test_a_point_from_another_round_is_rejected(): void
    {
        $schedule = $this->makeSchedule();
        $other = $this->makePoint($this->makeSchedule(), 'จุดของรอบอื่น', 3000);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'email' => 'wrong@example.test',
                'passengers' => [$this->passengerPayload('สมชาย ใจดี', ['pickup_point_id' => $other->id])],
                'status' => 'pending',
                'payment_type' => 'full',
                'send_email' => false,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Booking::count());
    }

    /** จอยทริปไม่มีจุดรับ ราคาจึงต้องไม่ถูกจุดรับรายคนดึงไป */
    public function test_join_trip_ignores_per_passenger_pickups(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update(['join_trip_enabled' => true, 'join_trip_price' => 900]);
        $ladprao = $this->makePoint($schedule, 'BTS ลาดพร้าว', 2500);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0810000000',
                'email' => 'join@example.test',
                'is_join_trip' => true,
                'passengers' => [
                    $this->passengerPayload('สมชาย ใจดี', ['pickup_point_id' => $ladprao->id]),
                    $this->passengerPayload('สมหญิง ใจงาม'),
                ],
                'status' => 'pending',
                'payment_type' => 'full',
                'send_email' => false,
            ]);

        $res->assertCreated();
        $booking = Booking::with('passengers')->findOrFail($res->json('data.id'));

        $this->assertEqualsWithDelta(1800, (float) $booking->total_amount, 0.01);
        $this->assertNull($booking->pickup_point_id);
        $this->assertTrue($booking->passengers->every(fn ($p) => $p->pickup_point_id === null));
    }
}
