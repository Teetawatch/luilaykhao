<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomPickupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
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

    /** payload ผู้เดินทาง 1 คนสำหรับยิงผ่าน HTTP store */
    private function passengerPayload(): array
    {
        return [[
            'title' => 'นาย',
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'id_card' => '1234567890123',
            'phone' => '0812345678',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0898765432',
        ]];
    }

    public function test_booking_with_custom_pickup_is_saved_as_pending_without_surcharge(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
                'custom_pickup_label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
                'custom_pickup_lat' => 14.4521,
                'custom_pickup_lng' => 101.3721,
                'custom_pickup_note' => 'รอตรงร้านกาแฟ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.custom_pickup.status', 'pending')
            ->assertJsonPath('data.custom_pickup.label', 'ปั๊ม ปตท. ทางเข้าเขาใหญ่');

        $booking = Booking::first();
        $this->assertSame('pending', $booking->custom_pickup_status);
        $this->assertNull($booking->custom_pickup_price);
        // ราคายังเป็นราคาทริปปกติ ยังไม่บวกค่าจุดรับ
        $this->assertEquals(1500, (float) $booking->total_amount);
    }

    public function test_custom_pickup_validation_requires_label_and_coords_together(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
                'custom_pickup_lat' => 14.4521, // ขาด lng + label
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_pickup_lng', 'custom_pickup_label']);
    }

    public function test_admin_approves_custom_pickup_and_price_is_added_to_total(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengerPayload(),
            customPickup: [
                'label' => 'หน้าเซเว่นปากทาง',
                'lat' => 14.45,
                'lng' => 101.37,
                'note' => null,
            ],
        );

        $this->assertEquals(1500, (float) $booking->total_amount);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/custom-pickup", [
                'action' => 'approve',
                'price' => 300,
            ])
            ->assertOk()
            ->assertJsonPath('data.custom_pickup.status', 'approved')
            ->assertJsonPath('data.custom_pickup.price', '300.00');

        $booking->refresh();
        $this->assertSame('approved', $booking->custom_pickup_status);
        $this->assertEquals(300, (float) $booking->custom_pickup_price);
        // ค่าจุดรับถูกบวกเข้ายอดรวม
        $this->assertEquals(1800, (float) $booking->total_amount);
        $this->assertNotNull($booking->custom_pickup_resolved_at);
    }

    public function test_admin_rejects_custom_pickup_with_reason_and_no_charge(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengerPayload(),
            customPickup: ['label' => 'นอกเส้นทาง', 'lat' => 18.0, 'lng' => 99.0, 'note' => null],
        );

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/custom-pickup", [
                'action' => 'reject',
                'reject_reason' => 'จุดนี้อยู่นอกเส้นทางผ่าน',
            ])
            ->assertOk()
            ->assertJsonPath('data.custom_pickup.status', 'rejected');

        $booking->refresh();
        $this->assertSame('rejected', $booking->custom_pickup_status);
        $this->assertNull($booking->custom_pickup_price);
        $this->assertEquals(1500, (float) $booking->total_amount); // ไม่ถูกเก็บเงินเพิ่ม
        $this->assertSame('จุดนี้อยู่นอกเส้นทางผ่าน', $booking->custom_pickup_reject_reason);
    }

    public function test_cannot_resolve_a_pickup_that_is_not_pending(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengerPayload(),
            customPickup: ['label' => 'จุดหนึ่ง', 'lat' => 14.4, 'lng' => 101.3, 'note' => null],
        );

        // อนุมัติรอบแรก
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/custom-pickup", [
                'action' => 'approve', 'price' => 200,
            ])->assertOk();

        // อนุมัติซ้ำ — ต้องถูกปฏิเสธ
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/custom-pickup", [
                'action' => 'approve', 'price' => 999,
            ])->assertStatus(422);

        $this->assertEquals(1700, (float) $booking->fresh()->total_amount); // บวกแค่ครั้งเดียว
    }

    public function test_predefined_pickup_point_takes_precedence_over_custom(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $pickup = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 1800,
            'sort_order' => 1,
        ]);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengerPayload(),
            pickupPointId: $pickup->id,
            customPickup: ['label' => 'จุดเอง', 'lat' => 14.4, 'lng' => 101.3, 'note' => null],
        );

        $this->assertSame($pickup->id, $booking->pickup_point_id);
        $this->assertNull($booking->custom_pickup_status); // custom ถูกละไว้
        $this->assertEquals(1800, (float) $booking->total_amount);
    }
}
