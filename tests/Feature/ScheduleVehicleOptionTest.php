<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\ScheduleVehicleOption;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ประเภทรถของรอบเดินทาง — รอบเดียวกันวิ่งทั้งรถบัสและรถตู้ คนละราคา
 *
 * ราคาเก็บเป็น "ส่วนต่างต่อคน" ไม่ใช่ราคาเต็ม จึงบวกท้ายราคาที่ลูกค้าเห็นอยู่แล้ว
 * (ราคารอบ หรือราคาโซนของจุดขึ้นรถซึ่งทับราคารอบ) โดยไม่ต้องตั้งราคาซ้ำทุกคู่
 */
class ScheduleVehicleOptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Vehicle Option Trip',
            'slug' => 'vehicle-option-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Nan',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 40,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 40,
            'booked_seats' => 0,
            'transport_type' => 'bus',
            'status' => 'open',
        ]);
    }

    private function makeOption(TripSchedule $schedule, string $label, float $adjustment, ?int $seats = null): ScheduleVehicleOption
    {
        return ScheduleVehicleOption::create([
            'schedule_id' => $schedule->id,
            'label' => $label,
            'transport_type' => $label === 'รถบัส' ? 'bus' : 'van',
            'price_adjustment' => $adjustment,
            'seats' => $seats,
            'sort_order' => $adjustment == 0 ? 0 : 1,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $overrides */
    private function passengers(int $count = 1): array
    {
        return collect(range(1, $count))->map(fn ($n) => [
            'title' => 'นาย',
            'name' => "ผู้เดินทาง {$n}",
            'nickname' => "คนที่ {$n}",
            'id_card' => '1234567890123',
            'phone' => '0812345678',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0898765432',
        ])->all();
    }

    public function test_chosen_vehicle_adds_its_adjustment_for_every_passenger(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $van = $this->makeOption($schedule, 'รถตู้', 400);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(2),
                'vehicle_option_id' => $van->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.vehicle_option.label', 'รถตู้')
            ->assertJsonPath('data.vehicle_option.price_adjustment', 400);

        // (1500 ราคารอบ + 400 ส่วนต่างรถตู้) × 2 คน
        $this->assertEquals(3800, (float) Booking::first()->total_amount);
        $this->assertSame(2, (int) $van->fresh()->booked_seats);
    }

    public function test_adjustment_stacks_on_top_of_the_pickup_zone_price(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $van = $this->makeOption($schedule, 'รถตู้', 400);

        // ราคาจุดรับทับราคารอบทั้งก้อน (1,700 ไม่ใช่ 1,500+1,700)
        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'pathumthani',
            'region_label' => 'ปทุมธานี',
            'pickup_location' => 'ฟิวเจอร์พาร์ค รังสิต',
            'price' => 1700,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
                'pickup_point_id' => $point->id,
                'vehicle_option_id' => $van->id,
            ])
            ->assertCreated();

        $this->assertEquals(2100, (float) Booking::first()->total_amount);
    }

    public function test_a_vehicle_that_is_full_cannot_be_booked(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $van = $this->makeOption($schedule, 'รถตู้', 400, seats: 2);

        // รอบยังเหลือที่นั่งอีกเยอะ — โควตาที่หมดคือของรถตู้คันเดียว
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(3),
                'vehicle_option_id' => $van->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'รถตู้เหลือ 2 ที่ ไม่พอสำหรับ 3 ท่าน');

        $this->assertSame(0, Booking::count());
        $this->assertSame(40, $schedule->fresh()->available_seats);
    }

    public function test_a_client_that_cannot_choose_falls_back_to_the_normal_price(): void
    {
        // แอปรุ่นก่อนหน้า/LIFF ที่ยังไม่มีช่องเลือก ต้องไม่ถูกชาร์จส่วนต่างของรถ
        // ที่เขาไม่เคยเห็นหน้าจอให้กด
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $bus = $this->makeOption($schedule, 'รถบัส', 0);
        $this->makeOption($schedule, 'รถตู้', 400);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
            ])
            ->assertCreated();

        $booking = Booking::first();
        $this->assertEquals(1500, (float) $booking->total_amount);
        $this->assertSame($bus->id, $booking->vehicle_option_id);
    }

    public function test_a_client_that_can_choose_must_actually_choose(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $this->makeOption($schedule, 'รถตู้', 400);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
                'vehicle_option_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_option_id');
    }

    public function test_options_of_another_round_are_rejected(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $other = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $foreign = $this->makeOption($other, 'รถตู้', 400);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
                'vehicle_option_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ตัวเลือกยานพาหนะที่เลือกไม่อยู่ในรอบเดินทางนี้');
    }

    public function test_the_schedule_endpoint_lists_the_options_for_the_booking_page(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $this->makeOption($schedule, 'รถตู้', 400, seats: 9);

        $this->getJson('/api/v1/schedules/'.$schedule->id)
            ->assertOk()
            ->assertJsonPath('data.offers_vehicle_choice', true)
            ->assertJsonCount(2, 'data.vehicle_options')
            ->assertJsonPath('data.vehicle_options.1.label', 'รถตู้')
            ->assertJsonPath('data.vehicle_options.1.price_adjustment', 400)
            ->assertJsonPath('data.vehicle_options.1.available_seats', 9)
            // คันที่ไม่ได้กำหนดโควตาย่อยใช้ที่นั่งว่างรวมของรอบ
            ->assertJsonPath('data.vehicle_options.0.available_seats', null);
    }

    public function test_only_the_rounds_own_vehicle_keeps_the_seat_map(): void
    {
        // ผังที่นั่งของรอบเป็นของรถหลักคันเดียว และการล็อกที่นั่งคีย์ด้วย (รอบ, รหัสที่นั่ง)
        // คันที่สองจึงเป็นแบบไม่ระบุที่นั่ง ไม่งั้น A1 ของบัสกับ A1 ของตู้จะชนกัน
        $bus = Vehicle::create([
            'name' => 'บัส 40 ที่นั่ง', 'type' => 'bus', 'capacity' => 40, 'license_plate' => '10-1234',
        ]);
        $vanVehicle = Vehicle::create([
            'name' => 'ตู้ 9 ที่นั่ง', 'type' => 'van', 'capacity' => 9, 'license_plate' => '20-5678',
        ]);

        $schedule = $this->makeSchedule();
        $schedule->update(['vehicle_id' => $bus->id]);

        $busOption = $this->makeOption($schedule, 'รถบัส', 0);
        $busOption->update(['vehicle_id' => $bus->id]);
        $vanOption = $this->makeOption($schedule, 'รถตู้', 400);
        $vanOption->update(['vehicle_id' => $vanVehicle->id]);

        $this->getJson('/api/v1/schedules/'.$schedule->id)
            ->assertOk()
            ->assertJsonPath('data.vehicle_options.0.uses_seat_map', true)
            ->assertJsonPath('data.vehicle_options.1.uses_seat_map', false);

        // ที่นั่งที่ส่งมากับคันที่ไม่มีผังถูกทิ้ง ไม่ใช่ปฏิเสธการจอง
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
                'vehicle_option_id' => $vanOption->id,
                'seat_ids' => ['A1'],
            ])
            ->assertCreated();

        $this->assertSame(0, Booking::first()->seats()->count());
    }

    public function test_cancelling_frees_the_seat_on_that_vehicle(): void
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $van = $this->makeOption($schedule, 'รถตู้', 400, seats: 1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
                'vehicle_option_id' => $van->id,
            ])->assertCreated();

        Booking::first()->update(['status' => 'cancelled']);

        // คนถัดไปต้องจองรถตู้คันเดิมได้ แม้ตัวนับจะยังค้างจากใบที่ยกเลิกไป
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
                'vehicle_option_id' => $van->id,
            ])->assertCreated();
    }

    public function test_admin_manages_the_options_of_a_round(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $schedule = $this->makeSchedule();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/'.$schedule->id.'/vehicle-options', [
                'label' => 'รถตู้ VIP',
                'transport_type' => 'van',
                'price_adjustment' => 400,
                'seats' => 9,
                'note' => 'นั่งสบาย 9 ที่',
            ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'รถตู้ VIP')
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/schedules/'.$schedule->id.'/vehicle-options/'.$created, [
                'price_adjustment' => 500,
            ])
            ->assertOk()
            ->assertJsonPath('data.price_adjustment', 500);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/admin/schedules/'.$schedule->id.'/vehicle-options/'.$created)
            ->assertOk();

        $this->assertSame(0, ScheduleVehicleOption::count());
    }

    public function test_an_option_with_live_bookings_is_deactivated_instead_of_deleted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $van = $this->makeOption($schedule, 'รถตู้', 400);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(1),
                'vehicle_option_id' => $van->id,
            ])->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/admin/schedules/'.$schedule->id.'/vehicle-options/'.$van->id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertNotNull($van->fresh());
        // ใบจองเดิมยังบอกได้ว่าจ่ายค่าอะไรไป
        $this->assertSame('รถตู้', Booking::first()->vehicle_option_label);
    }

    public function test_admin_cannot_shrink_a_vehicle_below_what_is_already_sold(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $user = User::factory()->create();
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $van = $this->makeOption($schedule, 'รถตู้', 400, seats: 9);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(3),
                'vehicle_option_id' => $van->id,
            ])->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/schedules/'.$schedule->id.'/vehicle-options/'.$van->id, [
                'seats' => 2,
            ])
            ->assertStatus(422);
    }

    public function test_manual_booking_charges_the_chosen_vehicle(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $schedule = $this->makeSchedule();
        $this->makeOption($schedule, 'รถบัส', 0);
        $van = $this->makeOption($schedule, 'รถตู้', 400);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/bookings/manual', [
                'schedule_id' => $schedule->id,
                'customer_name' => 'สมชาย ใจดี',
                'phone' => '0812345678',
                'status' => 'pending',
                'vehicle_option_id' => $van->id,
                'passengers' => [
                    ['title' => 'นาย', 'name' => 'สมชาย ใจดี', 'phone' => '0812345678'],
                    ['title' => 'นาง', 'name' => 'สมหญิง ใจดี', 'phone' => '0812345679'],
                ],
            ])
            ->assertCreated();

        $booking = Booking::first();
        $this->assertEquals(3800, (float) $booking->total_amount);
        $this->assertSame('รถตู้', $booking->vehicle_option_label);
        $this->assertSame(2, (int) $van->fresh()->booked_seats);
    }
}
