<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\TripActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * คนขับไม่ได้ใช้แอป สตาฟที่นั่งไปกับรถจึงเป็นทั้งคนกด "รถถึงแล้ว" และเป็น GPS
 * ของรถ — สองอย่างนี้คือสิ่งที่ทำให้ลูกค้าเลิกเดินหารถในลานจอด
 */
class StaffPickupArrivalTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_marks_arrival_and_the_waiting_customer_is_told_the_plate(): void
    {
        [$schedule, $point, $booking, $customer, $staff] = $this->scenario();

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'note' => 'จอดตรงข้าม 7-11 ป้ายสีเขียว',
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('data.notified'));
        $this->assertNotNull($response->json('data.points.0.arrived_at'));

        $push = SmartNotification::where('type', 'pickup_arrived')->firstOrFail();
        $this->assertSame($customer->id, $push->user_id);
        // ทะเบียนต้องมาก่อน เพราะนั่นคือสิ่งที่คนยืนอยู่ในลานจอดกำลังมองหา
        $this->assertStringContainsString('ฮก 8899', $push->body);
        $this->assertStringContainsString('จุดขึ้นรถหมอชิต', $push->body);
        $this->assertStringContainsString('7-11', $push->body);
        $this->assertSame($point->id, $push->data['pickup_point_id']);
    }

    public function test_the_parking_photo_lands_in_the_trip_chat(): void
    {
        Storage::fake('public');
        [$schedule, $point, , , $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->post("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'photo' => UploadedFile::fake()->image('parked.jpg'),
            ])
            ->assertOk();

        $message = ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->firstOrFail();
        $this->assertNotNull($message->image_path);
        $this->assertStringContainsString('ฮก 8899', $message->body);
        Storage::disk('public')->assertExists($message->image_path);
    }

    public function test_marking_twice_does_not_notify_or_post_twice(): void
    {
        [$schedule, $point, , , $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived")
            ->assertOk();

        $second = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'note' => 'ขยับมาจอดหน้าร้านกาแฟ',
            ])
            ->assertOk();

        $this->assertSame(0, $second->json('data.notified'));
        $this->assertSame(1, SmartNotification::where('type', 'pickup_arrived')->count());
        $this->assertSame(1, ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->count());
        // แต่ข้อความในห้องต้องอัปเดตตามของจริง
        $this->assertStringContainsString(
            'ขยับมาจอดหน้าร้านกาแฟ',
            ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->value('body'),
        );
    }

    public function test_a_photo_taken_later_is_added_to_the_message_already_posted(): void
    {
        Storage::fake('public');
        [$schedule, $point, , , $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived")
            ->assertOk();

        $this->assertNull(ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->value('image_path'));

        $this->actingAs($staff, 'sanctum')
            ->post("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'photo' => UploadedFile::fake()->image('parked.jpg'),
            ])
            ->assertOk();

        $this->assertNotNull(ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->value('image_path'));
    }

    public function test_companions_on_the_booking_are_told_too(): void
    {
        [$schedule, $point, $booking, , $staff] = $this->scenario();

        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'status' => BookingMember::STATUS_ACTIVE,
            'name' => 'เพื่อนร่วมทาง',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived")
            ->assertOk();

        $this->assertSame(2, SmartNotification::where('type', 'pickup_arrived')->count());
        $this->assertTrue(
            SmartNotification::where('type', 'pickup_arrived')->where('user_id', $friend->id)->exists(),
        );
    }

    public function test_people_waiting_at_another_point_are_left_alone(): void
    {
        [$schedule, $point, , , $staff] = $this->scenario();

        $other = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'จุดขึ้นรถรังสิต',
            'price' => 0,
            'sort_order' => 2,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$other->id}/arrived")
            ->assertOk()
            ->assertJsonPath('data.notified', 0);

        $this->assertSame(0, SmartNotification::where('type', 'pickup_arrived')->count());
        $this->assertNull($point->fresh()->arrived_at);
    }

    public function test_someone_already_checked_in_is_not_told_the_van_arrived(): void
    {
        [$schedule, $point, $booking, , $staff] = $this->scenario();
        $booking->update(['checked_in' => true, 'checked_in_at' => now()]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived")
            ->assertOk()
            ->assertJsonPath('data.notified', 0);
    }

    public function test_staff_can_undo_a_wrong_tap_including_the_chat_message(): void
    {
        Storage::fake('public');
        [$schedule, $point, , , $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->post("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'photo' => UploadedFile::fake()->image('parked.jpg'),
            ])
            ->assertOk();

        $path = ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->value('image_path');

        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived")
            ->assertOk();

        $this->assertNull($point->fresh()->arrived_at);
        $this->assertSame(0, ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_two_staff_tapping_at_once_do_not_break_each_other(): void
    {
        [$schedule, $point, , , $staff] = $this->scenario();

        $second = User::factory()->create();
        $second->assignRole('staff');
        $schedule->staff()->attach($second->id, ['assigned_by' => $staff->id]);

        // ข้อความในห้องมี system_key เป็น unique ระดับ DB — คนที่กดทีหลังต้องได้ผล
        // เหมือนกดตามปกติ ไม่ใช่ 500 กลางลานจอด
        ChatMessage::create([
            'schedule_id' => $schedule->id,
            'user_id' => null,
            'sender_role' => 'system',
            'system_key' => "pickup_arrived_{$point->id}",
            'body' => 'รถถึงแล้ว',
        ]);

        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'note' => 'จอดหน้าร้านกาแฟ',
            ])
            ->assertOk();

        $this->assertSame(1, ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->count());
        $this->assertStringContainsString(
            'จอดหน้าร้านกาแฟ',
            ChatMessage::where('system_key', "pickup_arrived_{$point->id}")->value('body'),
        );
    }

    public function test_staff_from_another_round_cannot_touch_this_one(): void
    {
        [$schedule, $point] = $this->scenario();

        $stranger = User::factory()->create();
        $stranger->assignRole('staff');

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived")
            ->assertStatus(403);
    }

    public function test_the_pickup_list_shows_who_is_still_waiting(): void
    {
        [$schedule, $point, , , $staff] = $this->scenario();

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points")
            ->assertOk();

        $this->assertSame('ฮก 8899', $response->json('data.vehicle.license_plate'));
        $this->assertSame(1, $response->json('data.points.0.waiting_count'));
        $this->assertTrue($response->json('data.can_share_location'));
    }

    public function test_staff_phone_becomes_the_vans_gps(): void
    {
        [$schedule, , , , $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/vehicle-location", [
                'latitude' => 13.7563,
                'longitude' => 100.5018,
                'speed' => 12.5,
            ])
            ->assertOk();

        $location = VehicleLocation::firstOrFail();
        $this->assertSame($schedule->vehicle_id, $location->vehicle_id);
        // เก็บไว้ด้วยว่าใครเป็นคนส่ง — พิกัดที่ไม่รู้ที่มาเชื่อไม่ได้
        $this->assertSame($staff->id, $location->user_id);
    }

    public function test_sharing_is_refused_outside_the_travel_window(): void
    {
        [$schedule, , , , $staff] = $this->scenario();
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(9)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/vehicle-location", [
                'latitude' => 13.7563,
                'longitude' => 100.5018,
            ])
            ->assertStatus(422);

        $this->assertSame(0, VehicleLocation::count());
    }

    public function test_a_van_leaving_the_night_before_can_share_from_the_afternoon(): void
    {
        [$schedule, , , , $staff] = $this->scenario();

        // รถออก 23:30 ของคืนก่อนวันทริป — departs_at เก็บเป็นเวลาไทยตรง ๆ
        $tomorrow = now('Asia/Bangkok')->addDay();
        $schedule->update([
            'departure_date' => $tomorrow->toDateString(),
            'return_date' => $tomorrow->copy()->addDay()->toDateString(),
            'departs_at' => now('Asia/Bangkok')->format('Y-m-d').' 23:30:00',
        ]);

        // 17:00 ของวันนี้ = 6 ชั่วโมงครึ่งก่อนรถออก ยังอยู่ในช่วงผ่อนผัน 12 ชม.
        $this->travelTo(Carbon::parse(
            now('Asia/Bangkok')->toDateString().' 17:00:00',
            'Asia/Bangkok',
        ));

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/vehicle-location", [
                'latitude' => 13.7563,
                'longitude' => 100.5018,
            ])
            ->assertOk();
    }

    public function test_a_round_without_a_van_cannot_share_a_vans_position(): void
    {
        [$schedule, , , , $staff] = $this->scenario();
        $schedule->update(['vehicle_id' => null]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/vehicle-location", [
                'latitude' => 13.7563,
                'longitude' => 100.5018,
            ])
            ->assertStatus(422);
    }

    public function test_the_lock_screen_card_says_which_van_and_where_it_parked(): void
    {
        [$schedule, $point, $booking, , $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'note' => 'จอดตรงข้าม 7-11',
            ])
            ->assertOk();

        // รถจอดอยู่ที่จุดรับพอดี — การ์ดต้องอยู่ขั้น "ถึงแล้ว"
        $point->update(['latitude' => 13.7563, 'longitude' => 100.5018]);
        $this->pushVehicleTo($staff, $schedule, 13.7563, 100.5018);

        $state = app(TripActivityService::class)->stateFor($booking->fresh()->load('schedule.vehicle', 'pickupPoint'));

        $this->assertSame('arrived', $state['stage']);
        $this->assertStringContainsString('ฮก 8899', $state['headline']);
        $this->assertStringContainsString('จอดตรงข้าม 7-11', $state['detail']);
    }

    public function test_the_lock_screen_card_believes_staff_when_there_is_no_gps(): void
    {
        [$schedule, $point, $booking, , $staff] = $this->scenario();

        // ไม่มีพิกัดรถเลยสักจุด — เดิมการ์ดค้างที่ "เตรียมตัว" ทั้งที่รถจอดอยู่ตรงหน้า
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived")
            ->assertOk();

        $state = app(TripActivityService::class)
            ->stateFor($booking->fresh()->load('schedule.vehicle', 'pickupPoint'));

        $this->assertSame('arrived', $state['stage']);
    }

    public function test_a_pickup_point_left_over_from_another_round_is_ignored(): void
    {
        [$schedule, $point, $booking, , $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived")
            ->assertOk();

        // ใบจองถูกย้ายไปอีกรอบ แต่ pickup_point_id ยังค้างชี้จุดของรอบเดิม
        $other = TripSchedule::create([
            'trip_id' => $schedule->trip_id,
            'vehicle_id' => $schedule->vehicle_id,
            'departure_date' => $schedule->departure_date->toDateString(),
            'return_date' => $schedule->return_date->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
        $booking->update(['schedule_id' => $other->id]);

        $state = app(TripActivityService::class)
            ->stateFor($booking->fresh()->load('schedule.vehicle', 'pickupPoint'));

        // รูป/โน้ตของอีกรอบต้องไม่ข้ามมา
        $this->assertNotSame('arrived', $state['stage']);
    }

    public function test_the_family_share_link_says_the_van_arrived_even_without_gps(): void
    {
        [$schedule, $point, $booking, , $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'note' => 'จอดตรงข้าม 7-11',
            ])
            ->assertOk();

        // ลิงก์ให้ที่บ้านติดตาม — สร้าง token ผ่านทางที่ลูกค้าใช้จริง
        $shareUrl = $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/tracking")
            ->json('data.share_url');
        $token = basename((string) $shareUrl);

        $response = $this->getJson("/api/v1/track/{$token}")->assertOk();

        // ยังไม่มีพิกัดรถสักจุด แต่หน้าเดิมเคยบอกว่า "รถยังไม่เริ่มส่งตำแหน่ง"
        $this->assertStringContainsString('รถถึงจุดรับแล้ว', $response->json('data.message'));
        $this->assertNotNull($response->json('data.pickup.arrived_at'));
        $this->assertSame('จอดตรงข้าม 7-11', $response->json('data.pickup.arrival_note'));
    }

    public function test_the_customer_payload_carries_the_parking_photo(): void
    {
        Storage::fake('public');
        [$schedule, $point, $booking, $customer, $staff] = $this->scenario();

        $this->actingAs($staff, 'sanctum')
            ->post("/api/v1/staff/schedules/{$schedule->id}/pickup-points/{$point->id}/arrived", [
                'photo' => UploadedFile::fake()->image('parked.jpg'),
                'note' => 'จอดตรงข้าม 7-11',
            ])
            ->assertOk();

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk();

        $this->assertNotNull($response->json('data.pickup_point.arrived_at'));
        $this->assertNotNull($response->json('data.pickup_point.arrival_photo_url'));
        $this->assertSame('จอดตรงข้าม 7-11', $response->json('data.pickup_point.arrival_note'));
    }

    private function pushVehicleTo(User $staff, TripSchedule $schedule, float $lat, float $lng): void
    {
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/staff/schedules/{$schedule->id}/vehicle-location", [
                'latitude' => $lat,
                'longitude' => $lng,
                'speed' => 0,
            ])
            ->assertOk();
    }

    /**
     * @return array{0: TripSchedule, 1: SchedulePickupPoint, 2: Booking, 3: User, 4: User}
     */
    private function scenario(): array
    {
        if (! Role::where('name', 'staff')->exists()) {
            Role::create(['name' => 'staff']);
        }

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $customer = User::factory()->create();

        $trip = Trip::create([
            'title' => 'ยอดดอยหลวง',
            'slug' => 'arrival-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้คันที่ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'ฮก 8899',
            'color' => 'ขาว',
            'driver_name' => 'พี่สมชาย',
            'driver_phone' => '0801112222',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'จุดขึ้นรถหมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $point->id,
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'คุณลูกค้า',
            'phone' => '0800000000',
            'pickup_point_id' => $point->id,
        ]);

        return [$schedule, $point, $booking, $customer, $staff];
    }
}
