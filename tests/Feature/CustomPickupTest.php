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

    public function test_pickup_region_matching_a_point_drops_custom_pickup(): void
    {
        // เอกสารกำกับสัญญา backend: ถ้าส่ง pickup_region ที่ตรงกับจุดรับตายตัวมาด้วย
        // จุดตายตัวจะชนะและหมุดจะถูกมองข้าม — หน้าจองจึงต้องไม่ส่ง region ตอนปักหมุดเอง
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
                'pickup_region' => 'bangkok', // มาจาก ?region= — ทำให้หมุดถูกมองข้าม
                'custom_pickup_label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
                'custom_pickup_lat' => 14.4521,
                'custom_pickup_lng' => 101.3721,
            ])
            ->assertCreated();

        $this->assertNull(Booking::first()->custom_pickup_status);
    }

    public function test_custom_pickup_stored_when_region_omitted(): void
    {
        // เลียนแบบ payload ที่หน้าจองแก้แล้ว: ปักหมุดเอง = ไม่ส่ง region/จุดตายตัว
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
                'pickup_region' => null,
                'pickup_point_id' => null,
                'custom_pickup_label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
                'custom_pickup_lat' => 14.4521,
                'custom_pickup_lng' => 101.3721,
            ])
            ->assertCreated()
            ->assertJsonPath('data.custom_pickup.status', 'approved');

        $booking = Booking::first();
        $this->assertNull($booking->pickup_point_id);
        $this->assertNull($booking->passengers()->first()->pickup_point_id);
    }

    public function test_round_with_pickup_points_requires_a_pickup(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);

        // ไม่ส่งจุดรับใด ๆ ทั้งที่รอบมีจุดขึ้นรถ → ถูกปฏิเสธ
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_point_id');

        // เลือกจุดขึ้นรถ → ผ่าน
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'pickup_point_id' => $point->id,
                'passengers' => $this->passengerPayload(),
            ])
            ->assertCreated();
    }

    public function test_round_without_pickup_points_allows_booking_without_pickup(): void
    {
        // รอบที่ไม่มีจุดขึ้นรถตั้งไว้ → backend ไม่บังคับ (เช่น LIFF ที่ไม่มีปุ่มปักหมุดเอง)
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
            ])
            ->assertCreated();
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

    /** จุดรับสองโซน: กรุงเทพฯ ราคาเท่าราคารอบ, ปากช่อง แพงกว่า */
    private function makeZonedPickupPoints(TripSchedule $schedule): void
    {
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 1500,
            'latitude' => 13.8022,
            'longitude' => 100.5540,
            'sort_order' => 1,
        ]);
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'korat',
            'region_label' => 'นครราชสีมา',
            'pickup_location' => 'ปากช่อง',
            'price' => 1900,
            'latitude' => 14.7080,
            'longitude' => 101.4160,
            'sort_order' => 2,
        ]);
    }

    public function test_custom_pin_is_priced_like_the_nearest_pickup_point(): void
    {
        // หมุดอยู่แถวเขาใหญ่ = ใกล้จุดปากช่อง (1,900) มากกว่าหมอชิต (1,500)
        // เดิมการปักหมุดเองล้างจุดรับทิ้ง ราคาจึงร่วงกลับไปเป็นราคารอบ (1,500)
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeZonedPickupPoints($schedule);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
                'custom_pickup_label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
                'custom_pickup_lat' => 14.4521,
                'custom_pickup_lng' => 101.3721,
            ])
            ->assertCreated();

        $booking = Booking::first();
        $this->assertSame('approved', $booking->custom_pickup_status);
        $this->assertEquals(1900, (float) $booking->total_amount);
        // ราคาโซนถูกคิดรวมในค่าทริปแล้ว จึงไม่มีค่าบริการหมุดแยกอีกก้อน
        $this->assertEquals(0, (float) $booking->custom_pickup_price);
    }

    public function test_custom_pin_never_prices_below_the_round_price(): void
    {
        // หมุดอยู่ในกรุงเทพฯ = ใกล้จุดหมอชิตที่ราคาเท่าราคารอบพอดี
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeZonedPickupPoints($schedule);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengerPayload(),
                'custom_pickup_label' => 'หน้าหมู่บ้าน',
                'custom_pickup_lat' => 13.7563,
                'custom_pickup_lng' => 100.5018,
            ])
            ->assertCreated();

        $this->assertEquals(1500, (float) Booking::first()->total_amount);
    }

    public function test_custom_pin_zone_price_applies_to_every_passenger(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeZonedPickupPoints($schedule);

        $passengers = [
            $this->passengerPayload()[0],
            [...$this->passengerPayload()[0], 'name' => 'สมหญิง ใจดี', 'id_card' => '9876543210123'],
        ];

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $passengers,
            customPickup: [
                'label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
                'lat' => 14.4521,
                'lng' => 101.3721,
                'note' => null,
            ],
        );

        $this->assertEquals(3800, (float) $booking->total_amount);
    }

    public function test_custom_pin_falls_back_to_round_price_when_points_have_no_coordinates(): void
    {
        // จุดรับที่ยังไม่มีพิกัด (แอดมินยังไม่ได้ใส่ลิงก์แผนที่) วัดระยะไม่ได้
        // → คิดราคารอบตามเดิม ดีกว่าเดาโซนผิด
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'korat',
            'region_label' => 'นครราชสีมา',
            'pickup_location' => 'ปากช่อง',
            'price' => 1900,
            'sort_order' => 1,
        ]);

        $booking = app(BookingService::class)->createBooking(
            userId: $user->id,
            scheduleId: $schedule->id,
            passengers: $this->passengerPayload(),
            customPickup: [
                'label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
                'lat' => 14.4521,
                'lng' => 101.3721,
                'note' => null,
            ],
        );

        $this->assertEquals(1500, (float) $booking->total_amount);
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
        // จุดรับรายผู้โดยสารที่ค้างต้องถูกล้างด้วย ไม่งั้นหน้าสตาฟจัดกลุ่มเข้าจุดเก่า
        $this->assertNull($booking->passengers()->first()->pickup_point_id);
    }
}
