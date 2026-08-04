<?php

namespace Tests\Feature;

use App\Events\ChatJoined;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ScheduleStaffAssignment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ChatRoomEventService;
use App\Services\ScheduleSeatNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ข้อความเหตุการณ์ที่เด้งเข้าห้องแชทเอง — เพื่อนร่วมทริปเข้ามา / ทีมงานประจำรอบ /
 * รถพร้อม / รอบการันตีออกเดินทาง / รอบเต็ม
 */
class ChatRoomEventTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Chat Event Trip',
            'slug' => 'chat-event-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    /**
     * จองในทรานแซกชันเหมือน BookingService ของจริง — ประกาศในห้องต้องเกิดหลัง
     * commit เท่านั้น (ตอนนั้นผู้โดยสารถูกบันทึกครบแล้ว)
     */
    private function bookOnto(TripSchedule $schedule, User $user, int $seats = 1, string $status = 'confirmed'): Booking
    {
        return DB::transaction(function () use ($schedule, $user, $seats, $status) {
            $booking = Booking::create([
                'booking_ref' => Booking::generateRef(),
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'qr_code' => Booking::generateQrCode(),
                'status' => $status,
                'total_amount' => 1500 * $seats,
            ]);

            for ($i = 0; $i < $seats; $i++) {
                BookingPassenger::create([
                    'booking_id' => $booking->id,
                    'title' => 'Mr.',
                    'name' => 'Passenger '.($i + 1),
                    'phone' => '0812345678',
                ]);
            }

            return $booking;
        });
    }

    private function systemBodies(TripSchedule $schedule): array
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->whereNotNull('system_key')
            ->pluck('body', 'system_key')
            ->all();
    }

    public function test_second_booking_announces_a_new_trip_mate_in_the_room(): void
    {
        $schedule = $this->makeSchedule();
        $first = User::factory()->create(['nickname' => 'เอ']);
        $second = User::factory()->create(['nickname' => 'บี']);

        $this->bookOnto($schedule, $first, 2);
        // คนแรกยังไม่มีใครในห้องให้บอก
        $this->assertSame([], $this->systemBodies($schedule));

        $booking = $this->bookOnto($schedule, $second, 3);

        $bodies = $this->systemBodies($schedule);
        $this->assertArrayHasKey("member_joined:{$booking->id}", $bodies);
        $body = $bodies["member_joined:{$booking->id}"];
        $this->assertStringContainsString('บี และเพื่อนอีก 2 คน', $body);
        $this->assertStringContainsString('5 คน', $body);

        // ห้องเริ่มด้วยข้อความต้อนรับเสมอ ไม่ใช่ข้อความเหตุการณ์
        $this->assertSame(
            'system',
            ChatMessage::where('schedule_id', $schedule->id)->orderBy('id')->first()->sender_role,
        );
        $this->assertNull(
            ChatMessage::where('schedule_id', $schedule->id)->orderBy('id')->first()->system_key,
        );
    }

    public function test_member_announcement_is_not_repeated_when_booking_is_saved_again(): void
    {
        $schedule = $this->makeSchedule();
        $this->bookOnto($schedule, User::factory()->create());
        $booking = $this->bookOnto($schedule, User::factory()->create(), 1, 'pending');

        $booking->update(['status' => 'confirmed']);
        $booking->update(['status' => 'completed']);

        $this->assertSame(
            1,
            ChatMessage::where('schedule_id', $schedule->id)
                ->where('system_key', "member_joined:{$booking->id}")
                ->count(),
        );
    }

    public function test_nothing_is_announced_for_a_cancelled_round(): void
    {
        $schedule = $this->makeSchedule(['status' => 'cancelled']);
        $this->bookOnto($schedule, User::factory()->create());
        $this->bookOnto($schedule, User::factory()->create());

        $this->assertSame(0, ChatMessage::where('schedule_id', $schedule->id)->count());
    }

    public function test_nothing_is_announced_after_the_round_has_departed(): void
    {
        $schedule = $this->makeSchedule([
            'departure_date' => now()->subDays(3)->toDateString(),
            'return_date' => now()->subDays(2)->toDateString(),
        ]);
        $this->bookOnto($schedule, User::factory()->create());
        $this->bookOnto($schedule, User::factory()->create());

        $this->assertSame(0, ChatMessage::where('schedule_id', $schedule->id)->count());
    }

    public function test_assigned_staff_is_introduced_in_the_room(): void
    {
        $schedule = $this->makeSchedule();
        $staff = User::factory()->create(['nickname' => 'พี่ต้น']);

        $events = app(ChatRoomEventService::class);
        $events->staffAssigned($schedule, $staff);
        $events->staffAssigned($schedule, $staff);

        $bodies = $this->systemBodies($schedule);
        $this->assertArrayHasKey("staff_joined:{$staff->id}", $bodies);
        $this->assertStringContainsString('พี่ต้น', $bodies["staff_joined:{$staff->id}"]);
        $this->assertSame(
            1,
            ChatMessage::where('schedule_id', $schedule->id)
                ->where('system_key', "staff_joined:{$staff->id}")
                ->count(),
        );
    }

    public function test_admin_staff_assignment_endpoint_posts_the_introduction(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('staff', 'web');

        $schedule = $this->makeSchedule();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $staff = User::factory()->create(['nickname' => 'พี่หนึ่ง']);
        $staff->assignRole('staff');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/staff", [
                'staff_ids' => [$staff->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('chat_messages', [
            'schedule_id' => $schedule->id,
            'system_key' => "staff_joined:{$staff->id}",
        ]);
        $this->assertDatabaseHas('schedule_staff_assignments', [
            'schedule_id' => $schedule->id,
            'user_id' => $staff->id,
        ]);
    }

    public function test_vehicle_ready_is_announced_once_per_vehicle(): void
    {
        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1',
            'type' => 'van',
            'license_plate' => '1กก 1234',
            'capacity' => 12,
            'driver_name' => 'ลุงสมชาย',
            'driver_phone' => '0891112222',
            'status' => 'active',
        ]);
        $schedule = $this->makeSchedule(['vehicle_id' => $vehicle->id]);

        $events = app(ChatRoomEventService::class);
        $events->vehicleReady($schedule->fresh());
        $events->vehicleReady($schedule->fresh());

        $bodies = $this->systemBodies($schedule);
        $this->assertArrayHasKey("vehicle_ready:{$vehicle->id}", $bodies);
        $this->assertStringContainsString('1กก 1234', $bodies["vehicle_ready:{$vehicle->id}"]);
        $this->assertStringContainsString('ลุงสมชาย', $bodies["vehicle_ready:{$vehicle->id}"]);
    }

    public function test_crossing_the_guarantee_threshold_announces_in_the_room(): void
    {
        $guarantee = TripSchedule::guaranteeMinSeats();
        $schedule = $this->makeSchedule([
            'total_seats' => $guarantee + 4,
            'booked_seats' => $guarantee,
        ]);

        app(ScheduleSeatNotifier::class)->seatsIncreased(
            $schedule->id,
            $guarantee - 1,
            $guarantee,
        );

        $this->assertArrayHasKey('guaranteed', $this->systemBodies($schedule));
    }

    public function test_selling_out_announces_in_the_room(): void
    {
        $schedule = $this->makeSchedule(['total_seats' => 6, 'booked_seats' => 6]);

        app(ScheduleSeatNotifier::class)->seatsIncreased($schedule->id, 5, 6);

        $this->assertArrayHasKey('sold_out', $this->systemBodies($schedule));
    }

    public function test_joined_signal_carries_the_sender_role(): void
    {
        Event::fake([ChatJoined::class]);

        $schedule = $this->makeSchedule();
        $staff = User::factory()->create();
        ScheduleStaffAssignment::create([
            'schedule_id' => $schedule->id,
            'user_id' => $staff->id,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/joined")
            ->assertOk();

        Event::assertDispatched(ChatJoined::class, fn (ChatJoined $e) => $e->role === 'staff');
    }
}
