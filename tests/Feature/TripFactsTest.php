<?php

namespace Tests\Feature;

use App\Jobs\NotifyTripCrewAssignedJob;
use App\Jobs\SendTripReminderNotificationsJob;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\ScheduleItineraryItem;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripFactsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TripFactsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง 2 วัน 1 คืน',
            'slug' => 'facts-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3200,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function pickupPoint(TripSchedule $schedule, array $overrides = []): SchedulePickupPoint
    {
        return SchedulePickupPoint::create(array_merge([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี',
            'pickup_time' => '19:30',
            'price' => 0,
            'map_url' => 'https://maps.app.goo.gl/abc',
            'sort_order' => 0,
        ], $overrides));
    }

    private function itineraryItem(
        TripSchedule $schedule,
        ?string $time,
        string $title,
    ): ScheduleItineraryItem {
        return ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id,
            'item_date' => $schedule->departure_date->toDateString(),
            'time' => $time,
            'title' => $title,
            'sort_order' => $schedule->itineraryItems()->count(),
        ]);
    }

    private function bookOnto(
        User $user,
        TripSchedule $schedule,
        ?SchedulePickupPoint $point = null,
    ): Booking {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3200,
            'pickup_point_id' => $point?->id,
            'pickup_region' => $point?->region,
        ]);
    }

    private function vehicleFor(TripSchedule $schedule): Vehicle
    {
        $vehicle = Vehicle::create([
            'name' => 'ตู้ 1',
            'type' => 'van',
            'capacity' => 12,
            'license_plate' => 'ฮก 1234 กรุงเทพมหานคร',
            'color' => 'ขาว',
            'driver_name' => 'สมชาย ใจดี',
            'driver_phone' => '0812345678',
        ]);
        $schedule->update(['vehicle_id' => $vehicle->id]);

        return $vehicle;
    }

    public function test_facts_answer_the_four_questions_customers_ask(): void
    {
        Bus::fake();
        Role::findOrCreate('staff');
        $schedule = $this->makeSchedule();
        $point = $this->pickupPoint($schedule);
        $this->vehicleFor($schedule);

        $staff = User::factory()->create(['nickname' => 'ต้น', 'phone' => '0899999999']);
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id);

        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule, $point);

        $facts = app(TripFactsService::class)->forUser($customer, $schedule->fresh());

        $this->assertSame('19:30', $facts['pickup']['time']);
        $this->assertSame('ปั๊ม ปตท. วิภาวดี', $facts['pickup']['location']);
        $this->assertSame('https://maps.app.goo.gl/abc', $facts['pickup']['map_url']);
        $this->assertSame('ฮก 1234 กรุงเทพมหานคร', $facts['vehicle']['license_plate']);
        $this->assertSame('สมชาย ใจดี', $facts['driver']['name']);
        $this->assertSame('0812345678', $facts['driver']['phone']);
        $this->assertSame('ต้น', $facts['staff'][0]['name']);
        $this->assertSame('0899999999', $facts['staff'][0]['phone']);
    }

    public function test_facts_return_null_instead_of_hiding_unknown_crew(): void
    {
        $schedule = $this->makeSchedule();
        $point = $this->pickupPoint($schedule);
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule, $point);

        $facts = app(TripFactsService::class)->forUser($customer, $schedule);

        // ยังไม่จัดรถ/สตาฟ — ต้องเป็น null ให้ client แสดงสถานะ "รอทีมงานยืนยัน"
        // ไม่ใช่หายไปเฉย ๆ จนลูกค้าคิดว่าไม่มีข้อมูล
        $this->assertNull($facts['vehicle']);
        $this->assertNull($facts['driver']);
        $this->assertSame([], $facts['staff']);
        $this->assertNotNull($facts['pickup']);
    }

    public function test_facts_use_the_approved_custom_pickup_when_there_is_one(): void
    {
        $schedule = $this->makeSchedule();
        $point = $this->pickupPoint($schedule);
        $customer = User::factory()->create();
        $booking = $this->bookOnto($customer, $schedule, $point);
        $booking->update([
            'custom_pickup_status' => 'approved',
            'custom_pickup_label' => 'หน้าหมู่บ้านสายไหม',
            'custom_pickup_lat' => 13.9,
            'custom_pickup_lng' => 100.6,
        ]);

        $facts = app(TripFactsService::class)->forUser($customer, $schedule);

        $this->assertTrue($facts['pickup']['is_custom']);
        $this->assertSame('หน้าหมู่บ้านสายไหม', $facts['pickup']['location']);
        $this->assertStringContainsString('13.9,100.6', $facts['pickup']['map_url']);
    }

    public function test_member_can_fetch_trip_info_from_the_chat_room(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $point = $this->pickupPoint($schedule);
        $this->vehicleFor($schedule);
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule, $point);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/trip-info")
            ->assertOk()
            ->assertJsonPath('data.pickup.time', '19:30')
            ->assertJsonPath('data.driver.phone', '0812345678')
            ->assertJsonPath('data.vehicle.license_plate', 'ฮก 1234 กรุงเทพมหานคร');

        $outsider = User::factory()->create();
        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/trip-info")
            ->assertStatus(403);
    }

    public function test_only_staff_can_post_the_trip_summary_into_the_room(): void
    {
        Bus::fake();
        Role::findOrCreate('staff');
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);
        $this->vehicleFor($schedule);

        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/trip-summary")
            ->assertStatus(403);

        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/trip-summary")
            ->assertCreated()
            ->assertJsonPath('data.sender_role', 'system');

        $body = ChatMessage::where('sender_role', 'system')->latest('id')->value('body');
        $this->assertStringContainsString('ปั๊ม ปตท. วิภาวดี', $body);
        $this->assertStringContainsString('19:30', $body);
        $this->assertStringContainsString('ฮก 1234', $body);
        $this->assertStringContainsString('0812345678', $body);
    }

    public function test_trip_info_carries_the_itinerary_for_the_quick_ask_sheet(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        // ยังไม่มีกำหนดการ — ต้องเป็น null เพื่อให้แอปซ่อนปุ่มถามไปเลย
        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/trip-info")
            ->assertOk()
            ->assertJsonPath('data.itinerary', null);

        $this->itineraryItem($schedule, '05:30', 'ออกเดินทางจากกรุงเทพฯ');
        $this->itineraryItem($schedule, '09:00', 'ถึงจุดเริ่มเดิน');

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/trip-info")
            ->assertOk()
            ->assertJsonPath('data.itinerary.source', 'schedule')
            ->assertJsonPath('data.itinerary.total', 2)
            ->assertJsonPath('data.itinerary.items.0.title', 'ออกเดินทางจากกรุงเทพฯ')
            ->assertJsonPath('data.itinerary.items.0.time', '05:30');
    }

    public function test_trip_info_itinerary_is_capped_but_reports_the_real_total(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        $total = TripFactsService::ITINERARY_LIMIT + 4;
        for ($i = 1; $i <= $total; $i++) {
            $this->itineraryItem($schedule, null, "จุดที่ {$i}");
        }

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/trip-info")
            ->assertOk()
            ->assertJsonPath('data.itinerary.total', $total)
            ->assertJsonCount(TripFactsService::ITINERARY_LIMIT, 'data.itinerary.items');
    }

    public function test_only_staff_can_post_the_itinerary_into_the_room(): void
    {
        Bus::fake();
        Role::findOrCreate('staff');
        $schedule = $this->makeSchedule();

        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id);

        // รอบที่ยังไม่มีกำหนดการ — บอกไปตรง ๆ ดีกว่าโพสต์ข้อความเปล่าเข้าห้อง
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/trip-itinerary")
            ->assertStatus(422);

        $this->itineraryItem($schedule, '05:30', 'ออกเดินทางจากกรุงเทพฯ');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/trip-itinerary")
            ->assertStatus(403);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/trip-itinerary")
            ->assertCreated()
            ->assertJsonPath('data.sender_role', 'system');

        $body = ChatMessage::where('sender_role', 'system')->latest('id')->value('body');
        $this->assertStringContainsString('กำหนดการเดินทาง', $body);
        $this->assertStringContainsString('05:30 น. ออกเดินทางจากกรุงเทพฯ', $body);
    }

    public function test_customers_are_notified_when_a_vehicle_is_assigned(): void
    {
        $schedule = $this->makeSchedule();
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        $this->vehicleFor($schedule);   // saved() → NotifyTripCrewAssignedJob
        (new NotifyTripCrewAssignedJob($schedule->id))->handle();

        $notification = SmartNotification::where('user_id', $customer->id)
            ->where('type', 'trip_crew_vehicle')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('ฮก 1234', $notification->body);
        $this->assertStringContainsString('สมชาย ใจดี', $notification->body);

        // ยิงซ้ำด้วยข้อมูลเดิม (แอดมินกดบันทึกซ้ำ) ต้องไม่เด้งซ้ำ
        (new NotifyTripCrewAssignedJob($schedule->id))->handle();
        $this->assertSame(
            1,
            SmartNotification::where('user_id', $customer->id)
                ->where('type', 'trip_crew_vehicle')
                ->count(),
        );

        // แต่ถ้าเปลี่ยนรถจริง ต้องแจ้งใหม่
        $schedule->vehicle->update(['license_plate' => 'ขข 9999 เชียงใหม่']);
        (new NotifyTripCrewAssignedJob($schedule->fresh()->id))->handle();
        $this->assertSame(
            2,
            SmartNotification::where('user_id', $customer->id)
                ->where('type', 'trip_crew_vehicle')
                ->count(),
        );
    }

    public function test_crew_notification_is_skipped_for_past_or_cancelled_rounds(): void
    {
        $past = $this->makeSchedule([
            'departure_date' => now()->subDays(3)->toDateString(),
            'return_date' => now()->subDays(2)->toDateString(),
        ]);
        $customer = User::factory()->create();
        $this->bookOnto($customer, $past);
        $this->vehicleFor($past);

        (new NotifyTripCrewAssignedJob($past->id))->handle();

        $this->assertSame(
            0,
            SmartNotification::where('type', 'trip_crew_vehicle')->count(),
        );
    }

    public function test_day_before_reminder_carries_pickup_and_plate(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(2)->toDateString(),
        ]);
        $point = $this->pickupPoint($schedule);
        $this->vehicleFor($schedule);
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule, $point);

        (new SendTripReminderNotificationsJob)->handle();

        $body = SmartNotification::where('user_id', $customer->id)
            ->where('type', 'trip_reminder')
            ->value('body');

        $this->assertNotNull($body);
        $this->assertStringContainsString('19:30', $body);
        $this->assertStringContainsString('ปั๊ม ปตท. วิภาวดี', $body);
        $this->assertStringContainsString('ฮก 1234', $body);
        $this->assertStringContainsString('0812345678', $body);
    }
}
