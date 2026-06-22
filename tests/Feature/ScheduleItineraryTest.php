<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ScheduleItineraryItem;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduleItineraryTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Itinerary Trip',
            'slug' => 'itinerary-trip-'.uniqid(),
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

    private function makeAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeStaff(TripSchedule $schedule): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function makeCustomer(TripSchedule $schedule): User
    {
        $customer = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'Passenger',
            'phone' => '0812345678',
        ]);

        return $customer;
    }

    public function test_admin_can_create_update_delete_item(): void
    {
        $schedule = $this->makeSchedule();
        $admin = $this->makeAdmin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/itinerary", [
                'item_date' => $schedule->departure_date->toDateString(),
                'time' => '06:00',
                'title' => 'ออกเดินทางจากกรุงเทพฯ',
                'detail' => 'นัดพบปั๊ม ปตท. พระราม 2',
            ])
            ->assertCreated()
            ->assertJsonPath('data.time', '06:00')
            ->assertJsonPath('data.title', 'ออกเดินทางจากกรุงเทพฯ')
            ->json('data.id');

        $this->assertDatabaseHas('schedule_itinerary_items', [
            'id' => $created,
            'schedule_id' => $schedule->id,
            'title' => 'ออกเดินทางจากกรุงเทพฯ',
            'time' => '06:00',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/itinerary/{$created}", [
                'title' => 'ออกเดินทาง (แก้ไข)',
                'time' => '06:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'ออกเดินทาง (แก้ไข)')
            ->assertJsonPath('data.time', '06:30');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/schedules/{$schedule->id}/itinerary/{$created}")
            ->assertOk();

        $this->assertDatabaseMissing('schedule_itinerary_items', ['id' => $created]);
    }

    public function test_assigned_staff_can_read_but_not_manage(): void
    {
        $schedule = $this->makeSchedule();
        $admin = $this->makeAdmin();
        $staff = $this->makeStaff($schedule);

        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id, 'created_by' => $admin->id,
            'item_date' => $schedule->departure_date->toDateString(),
            'time' => '08:00', 'title' => 'อาหารเช้า',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/itinerary")
            ->assertOk()
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.items.0.title', 'อาหารเช้า');

        // สตาฟไม่มี endpoint จัดการในบล็อก auth ปกติ — admin route ต้องโดน role middleware ปัด
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/itinerary", [
                'title' => 'สตาฟลองเพิ่ม',
            ])
            ->assertForbidden();
    }

    public function test_customer_cannot_read_itinerary(): void
    {
        $schedule = $this->makeSchedule();
        $customer = $this->makeCustomer($schedule);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/itinerary")
            ->assertForbidden();
    }

    public function test_items_ordered_by_date_then_time_then_sort(): void
    {
        $schedule = $this->makeSchedule();
        $admin = $this->makeAdmin();
        $day1 = $schedule->departure_date->toDateString();
        $day2 = $schedule->return_date->toDateString();

        // สร้างสลับลำดับเจตนา — index ต้องเรียง วัน → เวลา → sort_order
        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id, 'item_date' => $day2,
            'time' => '07:00', 'title' => 'วันสอง เช้า', 'sort_order' => 0,
        ]);
        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id, 'item_date' => $day1,
            'time' => '12:00', 'title' => 'วันหนึ่ง เที่ยง', 'sort_order' => 0,
        ]);
        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id, 'item_date' => $day1,
            'time' => '06:00', 'title' => 'วันหนึ่ง เช้า', 'sort_order' => 1,
        ]);
        // เวลาเท่ากันใช้ sort_order ตัดสิน (น้อยกว่ามาก่อน)
        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id, 'item_date' => $day1,
            'time' => '06:00', 'title' => 'วันหนึ่ง เช้า (ก่อน)', 'sort_order' => 0,
        ]);

        $titles = collect(
            $this->actingAs($admin, 'sanctum')
                ->getJson("/api/v1/schedules/{$schedule->id}/itinerary")
                ->assertOk()
                ->json('data.items')
        )->pluck('title')->all();

        $this->assertSame([
            'วันหนึ่ง เช้า (ก่อน)',
            'วันหนึ่ง เช้า',
            'วันหนึ่ง เที่ยง',
            'วันสอง เช้า',
        ], $titles);
    }

    public function test_time_must_be_valid_format(): void
    {
        $schedule = $this->makeSchedule();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/itinerary", [
                'title' => 'เวลาผิดรูปแบบ',
                'time' => '6 โมง',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['time']);
    }

    public function test_title_required_on_create(): void
    {
        $schedule = $this->makeSchedule();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/itinerary", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }
}
