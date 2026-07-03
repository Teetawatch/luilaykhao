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

    public function test_booking_with_custom_pickup_is_accepted_immediately_without_surcharge(): void
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
            // รับจุดรับอัตโนมัติทันที ลูกค้าชำระเงินได้เลย ไม่ต้องรอเจ้าหน้าที่
            ->assertJsonPath('data.custom_pickup.status', 'approved')
            ->assertJsonPath('data.custom_pickup.label', 'ปั๊ม ปตท. ทางเข้าเขาใหญ่');

        $booking = Booking::first();
        $this->assertSame('approved', $booking->custom_pickup_status);
        $this->assertNotNull($booking->custom_pickup_resolved_at);
        // ไม่คิดค่าบริการเพิ่ม — ราคาเท่าราคาทริปปกติ
        $this->assertEquals(0, (float) $booking->custom_pickup_price);
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

    public function test_admin_pinning_custom_pickup_clears_fixed_pickup_point(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

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

        // การจองเดิมมีจุดรับตายตัว
        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengerPayload(),
            pickupPointId: $pickup->id,
        );
        $this->assertSame($pickup->id, $booking->pickup_point_id);

        // แอดมินปักหมุดจุดรับเองในหน้าแก้ไข
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'custom_pickup_label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
                'custom_pickup_lat' => 14.51234,
                'custom_pickup_lng' => 101.37890,
                'custom_pickup_note' => 'รอตรงร้านกาแฟ',
            ])
            ->assertOk();

        $booking->refresh();
        // จุดรับตายตัวต้องถูกล้าง เพื่อให้หมุดของลูกค้าแสดงในหน้าสตาฟ
        $this->assertNull($booking->pickup_point_id);
        $this->assertNull($booking->pickup_region);
        $this->assertSame('approved', $booking->custom_pickup_status);
        $this->assertSame('ปั๊ม ปตท. ทางเข้าเขาใหญ่', $booking->custom_pickup_label);
    }
}
