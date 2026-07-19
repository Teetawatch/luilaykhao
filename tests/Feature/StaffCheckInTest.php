<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffCheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_staff_can_lookup_booking_then_confirm_check_in(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/lookup', [
                'qr_code' => $booking->qr_code,
            ])
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref)
            ->assertJsonPath('data.checked_in', false)
            ->assertJsonPath('data.passengers.0.name', 'Test Passenger')
            ->assertJsonPath('meta.can_check_in', true);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'checked_in' => false,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->qr_code,
            ])
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref)
            ->assertJsonPath('data.checked_in', true);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'checked_in' => true,
        ]);
    }

    public function test_check_in_notifies_the_booker_and_blocks_a_second_scan(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $booking->qr_code])
            ->assertOk()
            ->assertJsonPath('data.checked_in', true);

        // A push/in-app notification is fired to the booking owner.
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $booking->user_id,
            'type' => 'checked_in',
        ]);

        // Scanning the same QR again is rejected — cannot check in twice.
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $booking->qr_code])
            ->assertStatus(422);

        // And no duplicate notification was created by the blocked attempt.
        $this->assertEquals(
            1,
            SmartNotification::where('user_id', $booking->user_id)
                ->where('type', 'checked_in')
                ->count(),
        );
    }

    public function test_check_in_also_notifies_companions_with_accounts(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // An active companion who has their own account.
        $companion = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $companion->id,
            'role' => 'member',
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $booking->qr_code])
            ->assertOk();

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $companion->id,
            'type' => 'checked_in',
        ]);
    }

    public function test_unassigned_staff_cannot_lookup_booking_for_another_schedule(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [, $booking] = $this->createConfirmedBooking();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/lookup', [
                'qr_code' => $booking->qr_code,
            ])
            ->assertForbidden();
    }

    public function test_manifest_surfaces_passenger_safety_info_and_care_alert_count(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // Booker has an uploaded avatar → exposed as the passenger photo.
        $booking->user->update(['avatar' => 'https://example.com/a.jpg']);

        // Add a passenger who needs care attention.
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Care Passenger',
            'phone' => '0811111111',
            'allergies' => 'ถั่ว',
            'health_notes' => 'หอบหืด',
            'halal_food' => true,
            'blood_group' => 'O',
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0822222222',
        ]);

        // The rendered list is pickup_groups[].passengers — assert there.
        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->assertJsonPath('data.pickup_groups.0.passengers.0.avatar_url', 'https://example.com/a.jpg')
            ->assertJsonPath('data.pickup_groups.0.passengers.1.allergies', 'ถั่ว')
            ->assertJsonPath('data.pickup_groups.0.passengers.1.health_notes', 'หอบหืด')
            ->assertJsonPath('data.pickup_groups.0.passengers.1.halal_food', true)
            ->assertJsonPath('data.pickup_groups.0.passengers.1.blood_group', 'O')
            ->assertJsonPath('data.pickup_groups.0.passengers.1.emergency_contact', 'แม่')
            ->assertJsonPath('data.pickup_groups.0.passengers.1.emergency_phone', '0822222222')
            // 'Test Passenger' has no flags, the new one does → exactly one alert.
            ->assertJsonPath('data.summary.care_alerts', 1);
    }

    public function test_manifest_surfaces_customer_selected_addons(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $booking->update([
            'selected_addons' => [
                ['name' => 'เช่าเต็นท์', 'unit_price' => 200, 'price_type' => 'per_booking', 'quantity' => 1, 'total_price' => 200],
                ['name' => 'อาหารฮาลาล', 'unit_price' => 100, 'price_type' => 'per_person', 'quantity' => 2, 'total_price' => 200],
            ],
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->assertJsonPath('data.bookings.0.selected_addons.0.name', 'เช่าเต็นท์')
            ->assertJsonPath('data.bookings.0.selected_addons.1.name', 'อาหารฮาลาล')
            ->assertJsonPath('data.bookings.0.selected_addons.1.quantity', 2)
            ->assertJsonPath('data.summary.addon_requests', 1);
    }

    public function test_completing_a_pickup_point_notifies_next_stop_passengers(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $firstBooking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // Two pickup points in route order.
        $stop1 = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'Stop 1',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $stop2 = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'Stop 2',
            'price' => 0,
            'sort_order' => 2,
        ]);

        $firstBooking->update(['pickup_point_id' => $stop1->id]);

        // A customer waiting at stop 2.
        $nextCustomer = User::factory()->create();
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $nextCustomer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $stop2->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/pickup-points/{$stop1->id}/complete", [
                'completed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.next_point.id', $stop2->id)
            ->assertJsonPath('data.notified', 1);

        $this->assertNotNull($stop1->fresh()->completed_at);

        // The next-stop customer received a heads-up notification.
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $nextCustomer->id,
            'type' => 'pickup_approaching',
        ]);

        // Undo clears completion without notifying again.
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/pickup-points/{$stop1->id}/complete", [
                'completed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.completed_at', null);

        $this->assertNull($stop1->fresh()->completed_at);
    }

    public function test_lookup_reports_how_many_travellers_the_pickup_point_receives(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $bookingA] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $stop = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);

        // Scanned booking: 2 travellers at this stop, already checked in.
        $bookingA->update(['pickup_point_id' => $stop->id, 'checked_in' => true]);
        BookingPassenger::create(['booking_id' => $bookingA->id, 'name' => 'Second Traveller']);

        // Another booking at the same stop: 1 traveller, not yet checked in.
        $bookingB = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $stop->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
        BookingPassenger::create(['booking_id' => $bookingB->id, 'name' => 'Other Traveller']);

        // A booking at a different (implicit) stop must not be counted.
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/lookup', ['qr_code' => $bookingA->qr_code])
            ->assertOk()
            ->assertJsonPath('meta.pickup_group.label', 'BTS หมอชิต')
            ->assertJsonPath('meta.pickup_group.total_passengers', 3)
            ->assertJsonPath('meta.pickup_group.checked_in_passengers', 2)
            ->assertJsonPath('meta.pickup_group.this_booking_passengers', 2);
    }

    public function test_qr_check_in_auto_completes_pickup_point_and_notifies_next_stop(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $bookingA] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $stop1 = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'Stop 1',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $stop2 = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'Stop 2',
            'price' => 0,
            'sort_order' => 2,
        ]);

        $bookingA->update(['pickup_point_id' => $stop1->id]);

        // A second booking also at stop 1 — both must check in to complete it.
        $bookingA2 = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $stop1->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        // A customer waiting at stop 2 — should be notified when stop 1 is done.
        $nextCustomer = User::factory()->create();
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $nextCustomer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $stop2->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        // First check-in: stop 1 still has a waiting booking → not completed yet.
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $bookingA->qr_code])
            ->assertOk();

        $this->assertNull($stop1->fresh()->completed_at);
        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $nextCustomer->id,
            'type' => 'pickup_approaching',
        ]);

        // Last check-in at stop 1 → auto-complete + notify stop 2.
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $bookingA2->qr_code])
            ->assertOk();

        $this->assertNotNull($stop1->fresh()->completed_at);
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $nextCustomer->id,
            'type' => 'pickup_approaching',
        ]);
    }

    public function test_manifest_shows_custom_pin_pickup_group(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // Customer pinned their own pickup on the map (no fixed pickup point).
        $booking->update([
            'pickup_point_id' => null,
            'custom_pickup_label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
            'custom_pickup_lat' => 14.51234,
            'custom_pickup_lng' => 101.37890,
            'custom_pickup_note' => 'รอตรงร้านกาแฟ',
            'custom_pickup_status' => Booking::CUSTOM_PICKUP_APPROVED,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->assertJsonPath('data.pickup_groups.0.is_custom', true)
            ->assertJsonPath('data.pickup_groups.0.label', 'ปั๊ม ปตท. ทางเข้าเขาใหญ่')
            ->assertJsonPath('data.pickup_groups.0.notes', 'รอตรงร้านกาแฟ')
            ->assertJsonPath('data.pickup_groups.0.lat', 14.51234)
            ->assertJsonPath('data.pickup_groups.0.lng', 101.3789)
            ->assertJsonPath('data.pickup_groups.0.map_url', 'https://www.google.com/maps/search/?api=1&query=14.51234,101.3789');
    }

    public function test_custom_pin_wins_over_stale_per_passenger_pickup_point(): void
    {
        Role::create(['name' => 'staff']);

        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // Booking originally had a fixed pickup point, so the passenger still
        // carries that pickup_point_id even after switching to a custom pin.
        $stalePoint = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต',
            'price' => 0,
            'sort_order' => 1,
        ]);
        $booking->passengers()->update(['pickup_point_id' => $stalePoint->id]);
        $booking->update([
            'pickup_point_id' => null,
            'custom_pickup_label' => 'ปั๊ม ปตท. ทางเข้าเขาใหญ่',
            'custom_pickup_lat' => 14.51234,
            'custom_pickup_lng' => 101.37890,
            'custom_pickup_status' => Booking::CUSTOM_PICKUP_APPROVED,
        ]);

        // The manifest must group the passenger under the custom pin, not the
        // stale fixed point.
        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$schedule->id}/manifest")
            ->assertOk()
            ->assertJsonPath('data.pickup_groups.0.is_custom', true)
            ->assertJsonPath('data.pickup_groups.0.label', 'ปั๊ม ปตท. ทางเข้าเขาใหญ่')
            ->assertJsonCount(1, 'data.pickup_groups');
    }

    /**
     * ผู้โดยสารเลือกจุดรับรายคนได้ (booking_passengers.pickup_point_id) ซึ่งอาจ
     * ต่างจากจุดรับระดับการจอง จุดจะครบก็ต่อเมื่อ "ทุกคนที่ยืนรออยู่จุดนั้นจริง"
     * เช็คอินแล้ว ไม่ใช่แค่การจองที่ผูกจุดนั้นไว้ที่หัวการจอง
     */
    public function test_point_waits_for_passenger_level_pickup_before_auto_completing(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $bookingA] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        [$stop1, $stop2] = $this->createTwoStops($schedule);

        $bookingA->update(['pickup_point_id' => $stop1->id]);

        // การจองอีกใบผูกไว้ที่ stop 2 ที่หัวการจอง แต่ผู้โดยสารคนนี้เลือกขึ้น stop 1
        // → stop 1 ยังต้องรอเขา แม้ไม่มีการจองใบไหนที่หัวการจองชี้ stop 1 อีกแล้ว
        $bookingB = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $stop2->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $bookingB->id,
            'name' => 'Rides From Stop 1',
            'phone' => '0800000001',
            'pickup_point_id' => $stop1->id,
        ]);

        $nextCustomer = User::factory()->create();
        $bookingC = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $nextCustomer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $stop2->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $bookingC->id,
            'name' => 'Waits At Stop 2',
            'phone' => '0800000002',
        ]);

        // เช็คอิน bookingA — stop 1 ยังเหลือผู้โดยสารของ bookingB ที่เลือกจุดนี้
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $bookingA->qr_code])
            ->assertOk();

        $this->assertNull(
            $stop1->fresh()->completed_at,
            'stop 1 ต้องยังไม่ปิด เพราะยังมีผู้โดยสารที่เลือกจุดนี้รายคนยังไม่เช็คอิน',
        );
        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $nextCustomer->id,
            'type' => 'pickup_approaching',
        ]);

        // เช็คอิน bookingB — ตอนนี้ไม่เหลือใครที่ stop 1 → ปิดจุดและแจ้งจุดถัดไป
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $bookingB->qr_code])
            ->assertOk();

        $this->assertNotNull($stop1->fresh()->completed_at);
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $nextCustomer->id,
            'type' => 'pickup_approaching',
        ]);

        unset($bookingC);
    }

    /**
     * การจองใบเดียวที่ผู้โดยสารกระจายอยู่หลายจุด — เมื่อเช็คอิน (เช็คอินเป็นราย
     * การจอง) ต้องปิดได้ทุกจุดที่คนของการจองนี้เป็นคนสุดท้าย ไม่ใช่แค่จุดที่หัวการจอง
     */
    public function test_check_in_completes_every_point_the_booking_covers(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$schedule, $booking] = $this->createConfirmedBooking();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        [$stop1, $stop2] = $this->createTwoStops($schedule);
        $stop3 = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'Stop 3',
            'price' => 0,
            'sort_order' => 3,
        ]);

        // การจองนี้: หัวการจองอยู่ stop 2 แต่มีผู้โดยสารอีกคนขึ้นที่ stop 1
        $booking->update(['pickup_point_id' => $stop2->id]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Boards At Stop 1',
            'phone' => '0800000003',
            'pickup_point_id' => $stop1->id,
        ]);

        $nextCustomer = User::factory()->create();
        $bookingAtStop3 = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $nextCustomer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $stop3->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $bookingAtStop3->id,
            'name' => 'Waits At Stop 3',
            'phone' => '0800000004',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $booking->qr_code])
            ->assertOk();

        // การสแกนครั้งเดียวเก็บครบทั้ง stop 1 และ stop 2 → ทั้งคู่ต้องปิด
        $this->assertNotNull($stop1->fresh()->completed_at, 'stop 1 ต้องถูกปิดด้วย');
        $this->assertNotNull($stop2->fresh()->completed_at, 'stop 2 ต้องถูกปิด');
        $this->assertNull($stop3->fresh()->completed_at);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $nextCustomer->id,
            'type' => 'pickup_approaching',
        ]);
    }

    /** @return array{0: SchedulePickupPoint, 1: SchedulePickupPoint} */
    private function createTwoStops(TripSchedule $schedule): array
    {
        return [
            SchedulePickupPoint::create([
                'schedule_id' => $schedule->id,
                'region' => 'bangkok',
                'region_label' => 'กรุงเทพฯ',
                'pickup_location' => 'Stop 1',
                'price' => 0,
                'sort_order' => 1,
            ]),
            SchedulePickupPoint::create([
                'schedule_id' => $schedule->id,
                'region' => 'bangkok',
                'region_label' => 'กรุงเทพฯ',
                'pickup_location' => 'Stop 2',
                'price' => 0,
                'sort_order' => 2,
            ]),
        ];
    }

    private function createConfirmedBooking(): array
    {
        $customer = User::factory()->create();
        $trip = Trip::create([
            'title' => 'Staff Check-in Trip',
            'slug' => 'staff-check-in-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'Test Passenger',
            'phone' => '0800000000',
        ]);

        return [$schedule, $booking];
    }
}
