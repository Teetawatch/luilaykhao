<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MoveBookingsCrossTripTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeSchedule(string $title): TripSchedule
    {
        $trip = Trip::create([
            'title' => $title,
            'slug' => str()->slug($title).'-'.uniqid(),
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
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    public function test_can_move_booking_to_a_different_trip(): void
    {
        $admin = $this->makeAdmin();
        $source = $this->makeSchedule('Source Trip');
        $target = $this->makeSchedule('Other Trip'); // ทริปคนละใบ

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $source->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
        ]);
        $passenger = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Mover',
            'phone' => '0800000000',
        ]);
        BookingSeat::create([
            'booking_id' => $booking->id,
            'schedule_id' => $source->id,
            'seat_id' => 'A1',
            'passenger_name' => 'Mover',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $source->id,
                'target_schedule_id' => $target->id,
                'passenger_ids' => [$passenger->id],
                'seat_assignments' => [$passenger->id => 'B2'],
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($target->id, $booking->schedule_id);
        $this->assertSame('B2', $booking->seats()->first()->seat_id);
        $this->assertSame($target->id, $booking->seats()->first()->schedule_id);
    }

    public function test_cross_trip_move_clears_unmappable_pickup_point(): void
    {
        $admin = $this->makeAdmin();
        $source = $this->makeSchedule('Source Trip');
        $target = $this->makeSchedule('Other Trip');

        // จุดรับของต้นทาง ที่ปลายทางไม่มีจุดตรงกัน
        $sourcePoint = SchedulePickupPoint::create([
            'schedule_id' => $source->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $source->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'pickup_point_id' => $sourcePoint->id,
            'pickup_region' => 'bangkok',
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Mover',
            'phone' => '0800000000',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $source->id,
                'target_schedule_id' => $target->id,
                'passenger_ids' => [$booking->passengers()->first()->id],
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($target->id, $booking->schedule_id);
        // ไม่ทิ้ง FK ที่ชี้จุดรับของรอบต้นทาง แต่คงชื่อภูมิภาคไว้เป็นข้อความ
        $this->assertNull($booking->pickup_point_id);
        $this->assertSame('bangkok', $booking->pickup_region);
    }

    public function test_cross_trip_move_maps_pickup_point_when_location_matches(): void
    {
        $admin = $this->makeAdmin();
        $source = $this->makeSchedule('Source Trip');
        $target = $this->makeSchedule('Other Trip');

        $sourcePoint = SchedulePickupPoint::create([
            'schedule_id' => $source->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $targetPoint = SchedulePickupPoint::create([
            'schedule_id' => $target->id,
            'region' => 'bkk',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต', // ชื่อจุดตรงกัน → จับคู่ได้
            'price' => 0,
            'sort_order' => 1,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $source->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'pickup_point_id' => $sourcePoint->id,
            'pickup_region' => 'bangkok',
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Mover',
            'phone' => '0800000000',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $source->id,
                'target_schedule_id' => $target->id,
                'passenger_ids' => [$booking->passengers()->first()->id],
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($targetPoint->id, $booking->pickup_point_id);
    }

    public function test_cross_trip_move_remaps_passenger_level_pickup_points(): void
    {
        $admin = $this->makeAdmin();
        $source = $this->makeSchedule('Source Trip');
        $target = $this->makeSchedule('Other Trip');

        // จุดรับชื่อเดียวกันคนละรอบ — เวลารับต่างกัน
        $sourcePoint = SchedulePickupPoint::create([
            'schedule_id' => $source->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'pickup_time' => '05:00',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $targetPoint = SchedulePickupPoint::create([
            'schedule_id' => $target->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'pickup_time' => '07:30',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $orphanPoint = SchedulePickupPoint::create([
            'schedule_id' => $source->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี',
            'pickup_time' => '05:30',
            'price' => 0,
            'sort_order' => 2,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $source->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 3000,
            'pickup_point_id' => $sourcePoint->id,
            'pickup_region' => 'bangkok',
        ]);
        $matched = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Mover',
            'phone' => '0800000000',
            'pickup_point_id' => $sourcePoint->id,
        ]);
        $unmatched = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Friend',
            'phone' => '0800000001',
            'pickup_point_id' => $orphanPoint->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $source->id,
                'target_schedule_id' => $target->id,
                'passenger_ids' => [$matched->id, $unmatched->id],
            ])
            ->assertOk();

        // จุดรับรายคนต้องชี้จุดของรอบใหม่ (เวลา 07:30) ไม่ใช่ของรอบเดิม
        $this->assertSame($targetPoint->id, $matched->fresh()->pickup_point_id);
        // จับคู่ไม่ได้ → ล้างทิ้ง ไม่ปล่อยให้ค้างชี้รอบเดิม
        $this->assertNull($unmatched->fresh()->pickup_point_id);
    }

    public function test_partial_move_remaps_pickup_points_of_moved_passengers_only(): void
    {
        $admin = $this->makeAdmin();
        $source = $this->makeSchedule('Source Trip');
        $target = $this->makeSchedule('Other Trip');

        $sourcePoint = SchedulePickupPoint::create([
            'schedule_id' => $source->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'pickup_time' => '05:00',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $targetPoint = SchedulePickupPoint::create([
            'schedule_id' => $target->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'pickup_time' => '07:30',
            'price' => 0,
            'sort_order' => 1,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $source->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 3000,
            'pickup_point_id' => $sourcePoint->id,
            'pickup_region' => 'bangkok',
        ]);
        $moving = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Mover',
            'phone' => '0800000000',
            'pickup_point_id' => $sourcePoint->id,
        ]);
        $staying = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Stayer',
            'phone' => '0800000001',
            'pickup_point_id' => $sourcePoint->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/schedules/move-bookings', [
                'source_schedule_id' => $source->id,
                'target_schedule_id' => $target->id,
                'passenger_ids' => [$moving->id],
            ])
            ->assertOk();

        $moving->refresh();
        $staying->refresh();

        $this->assertNotSame($booking->id, $moving->booking_id, 'ผู้โดยสารที่ย้ายต้องอยู่การจองใหม่');
        $this->assertSame($target->id, $moving->booking->schedule_id);
        $this->assertSame($targetPoint->id, $moving->pickup_point_id);
        // คนที่ไม่ได้ย้ายยังอยู่รอบเดิม จุดรับต้องไม่ถูกแตะ
        $this->assertSame($sourcePoint->id, $staying->pickup_point_id);
    }

    public function test_admin_edit_booking_schedule_change_remaps_pickup_points(): void
    {
        $admin = $this->makeAdmin();
        $source = $this->makeSchedule('Source Trip');
        $target = $this->makeSchedule('Other Trip');

        $sourcePoint = SchedulePickupPoint::create([
            'schedule_id' => $source->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'pickup_time' => '05:00',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $targetPoint = SchedulePickupPoint::create([
            'schedule_id' => $target->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'pickup_time' => '07:30',
            'price' => 0,
            'sort_order' => 1,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $source->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'pickup_point_id' => $sourcePoint->id,
            'pickup_region' => 'bangkok',
        ]);
        $passenger = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Mover',
            'phone' => '0800000000',
            'pickup_point_id' => $sourcePoint->id,
        ]);
        BookingSeat::create([
            'booking_id' => $booking->id,
            'schedule_id' => $source->id,
            'seat_id' => 'A1',
            'passenger_name' => 'Mover',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}", [
                'schedule_id' => $target->id,
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($target->id, $booking->schedule_id);
        $this->assertSame($targetPoint->id, $booking->pickup_point_id);
        $this->assertSame($targetPoint->id, $passenger->fresh()->pickup_point_id);
        // แถวที่นั่งต้องย้ายตามรอบด้วย ไม่ค้างกินที่นั่งรอบเดิม
        $this->assertSame($target->id, $booking->seats()->first()->schedule_id);
    }
}
