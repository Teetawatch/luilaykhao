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
use App\Services\BookingMemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * เช็คอินรายคน: ใบจอง 4 คน มาจริง 3 — QR รายคน, ติ๊กคนที่มา, เพื่อนเห็นบัตรของตัวเอง
 */
class PassengerCheckInTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'staff']);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');
    }

    public function test_every_passenger_gets_their_own_boarding_code(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี', 'ซี', 'ดี']);

        $codes = $passengers->pluck('qr_code');

        $this->assertCount(4, $codes->filter()->unique());
        $this->assertFalse($codes->contains($booking->qr_code));
        $this->assertStringStartsWith('QR-', $codes->first());
    }

    public function test_group_scan_checks_in_only_the_people_staff_ticked(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี', 'ซี', 'ดี']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/lookup', ['qr_code' => $booking->qr_code])
            ->assertOk()
            ->assertJsonCount(4, 'meta.passengers')
            ->assertJsonPath('meta.scanned_passenger_id', null)
            ->assertJsonPath('meta.can_check_in', true);

        $present = $passengers->take(3)->pluck('id')->all();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->qr_code,
                'passenger_ids' => $present,
            ])
            ->assertOk()
            ->assertJsonPath('data.checked_in', true)
            ->assertJsonPath('meta.checked_in_passengers', 3)
            ->assertJsonPath('meta.total_passengers', 4)
            ->assertJsonPath('meta.can_check_in', true)
            ->assertJsonPath('message', 'เช็คอินสำเร็จ 3 คน (ใบนี้ขึ้นรถแล้ว 3/4)');

        $this->assertNotNull($passengers[0]->fresh()->checked_in_at);
        $this->assertNull($passengers[3]->fresh()->checked_in_at);
        $this->assertTrue((bool) $booking->fresh()->checked_in);
    }

    public function test_personal_code_checks_in_that_person_only_and_late_friend_can_board_later(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/lookup', ['qr_code' => $passengers[1]->qr_code])
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref)
            ->assertJsonPath('meta.scanned_passenger_id', $passengers[1]->id)
            ->assertJsonPath('message', 'พบบัตรขึ้นรถของ บี');

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[1]->qr_code])
            ->assertOk()
            ->assertJsonPath('message', 'เช็คอิน บี สำเร็จ (ใบนี้ขึ้นรถแล้ว 1/2)');

        $this->assertNull($passengers[0]->fresh()->checked_in_at);
        $this->assertNotNull($passengers[1]->fresh()->checked_in_at);

        // สแกนซ้ำ — บอกชื่อคนที่ขึ้นไปแล้ว
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[1]->qr_code])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'บี เช็คอินแล้วเมื่อ '.$passengers[1]->fresh()->checked_in_at->timezone('Asia/Bangkok')->format('d/m/Y H:i')]);

        // คิวออฟไลน์ส่งซ้ำ — ตอบ 200 ให้ปล่อยรายการทิ้ง
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $passengers[1]->qr_code,
                'checked_in_at' => now()->subMinutes(5)->toIso8601String(),
            ])
            ->assertOk();

        // QR ของใบจองยังใช้ได้กับคนที่เหลือ
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $booking->qr_code])
            ->assertOk()
            ->assertJsonPath('meta.checked_in_passengers', 2)
            ->assertJsonPath('meta.can_check_in', false);
    }

    public function test_old_staff_app_without_passenger_ids_boards_everyone_except_not_going(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี', 'ซี']);
        $passengers[2]->forceFill(['not_going_at' => now()])->save();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $booking->booking_ref])
            ->assertOk()
            ->assertJsonPath('meta.checked_in_passengers', 2);

        $this->assertNull($passengers[2]->fresh()->checked_in_at);
    }

    public function test_not_going_passenger_who_shows_up_can_still_be_checked_in(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);
        $passengers[1]->forceFill(['not_going_at' => now()])->save();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[1]->qr_code])
            ->assertOk();

        $fresh = $passengers[1]->fresh();
        $this->assertNotNull($fresh->checked_in_at);
        $this->assertNull($fresh->not_going_at);
    }

    public function test_rejects_passenger_ids_from_another_booking_and_empty_selection(): void
    {
        [, $booking] = $this->bookingWith(['เอ']);
        [, , $others] = $this->bookingWith(['คนอื่น']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->qr_code,
                'passenger_ids' => [$others[0]->id],
            ])
            ->assertStatus(422);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->qr_code,
                'passenger_ids' => [],
            ])
            ->assertStatus(422);

        $this->assertFalse((bool) $booking->fresh()->checked_in);
        $this->assertNull($others[0]->fresh()->checked_in_at);
    }

    public function test_staff_from_another_round_cannot_use_a_personal_code(): void
    {
        [, , $passengers] = $this->bookingWith(['เอ'], assignStaff: false);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[0]->qr_code])
            ->assertStatus(403);
    }

    public function test_missing_friend_keeps_the_pickup_point_open(): void
    {
        [$schedule, $booking, $passengers] = $this->bookingWith(['เอ', 'บี'], points: 2);
        [$pointA] = $schedule->pickupPoints()->orderBy('sort_order')->get();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', [
                'qr_code' => $booking->qr_code,
                'passenger_ids' => [$passengers[0]->id],
            ])
            ->assertOk();

        $this->assertNull($pointA->fresh()->completed_at, 'บียังไม่มา จุดนี้ยังไม่ครบ');

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[1]->qr_code])
            ->assertOk();

        $this->assertNotNull($pointA->fresh()->completed_at);
    }

    public function test_friend_waiting_at_a_later_stop_is_not_skipped(): void
    {
        [$schedule, $booking, $passengers] = $this->bookingWith(['เอ', 'บี'], points: 2);
        [$pointA, $pointB] = $schedule->pickupPoints()->orderBy('sort_order')->get();
        $passengers[1]->update(['pickup_point_id' => $pointB->id]);

        // เดิม: เช็คอินยกใบที่จุดแรก แล้วจุดของเพื่อนถูกปิดไปด้วยทั้งที่เพื่อนยังยืนรอ
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[0]->qr_code])
            ->assertOk();

        $this->assertNotNull($pointA->fresh()->completed_at);
        $this->assertNull($pointB->fresh()->completed_at);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/staff/schedules/{$schedule->id}/pickup-points")
            ->assertOk()
            ->assertJsonPath('data.points.1.waiting_count', 1);
    }

    public function test_staff_can_undo_a_mistaken_tick(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[0]->qr_code])
            ->assertOk();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/undo', [
                'qr_code' => $booking->qr_code,
                'passenger_id' => $passengers[0]->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.checked_in', false);

        $this->assertNull($passengers[0]->fresh()->checked_in_at);
        $this->assertFalse((bool) $booking->fresh()->checked_in);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/undo', [
                'qr_code' => $booking->qr_code,
                'passenger_id' => $passengers[1]->id,
            ])
            ->assertStatus(422);
    }

    public function test_booking_level_writes_keep_passengers_in_sync(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);

        // แอดมินกดเช็คอินทั้งใบจากหลังบ้าน
        $booking->update(['checked_in' => true, 'checked_in_at' => now()]);
        $this->assertSame(2, BookingPassenger::where('booking_id', $booking->id)->whereNotNull('checked_in_at')->count());

        $booking->update(['checked_in' => false, 'checked_in_at' => null]);
        $this->assertSame(0, BookingPassenger::where('booking_id', $booking->id)->whereNotNull('checked_in_at')->count());

        // เช็คอินรายคนแล้ว ใบจองถูกพลิกตาม — ไม่ลากคนที่เหลือขึ้นรถไปด้วย
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[0]->qr_code])
            ->assertOk();
        $this->assertNull($passengers[1]->fresh()->checked_in_at);
    }

    public function test_absent_linked_friend_is_not_told_they_checked_in(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);
        $friend = User::factory()->create();
        $absent = User::factory()->create();
        $this->member($booking, $friend, $passengers[0]);
        $this->member($booking, $absent, $passengers[1]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/staff/check-in/confirm', ['qr_code' => $passengers[0]->qr_code])
            ->assertOk();

        $this->assertTrue(SmartNotification::where('user_id', $friend->id)->where('type', 'checked_in')->exists());
        $this->assertFalse(SmartNotification::where('user_id', $absent->id)->where('type', 'checked_in')->exists());

        $owner = SmartNotification::where('user_id', $booking->user_id)->where('type', 'checked_in')->first();
        $this->assertNotNull($owner);
        $this->assertStringContainsString('1 จาก 2 คน', $owner->body);
    }

    public function test_owner_sees_every_pass_and_the_group_code(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.check_in_passes.viewer_role', 'owner')
            ->assertJsonPath('data.check_in_passes.group.code', $booking->qr_code)
            ->assertJsonCount(2, 'data.check_in_passes.passes')
            ->assertJsonPath('data.check_in_passes.passes.1.code', $passengers[1]->qr_code)
            // รหัสรายคนไม่หลุดไปกับรายชื่อผู้โดยสาร
            ->assertJsonMissingPath('data.passengers.0.qr_code');
    }

    public function test_linked_friend_sees_only_their_own_pass(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);
        $friend = User::factory()->create();
        $this->member($booking, $friend, $passengers[1]);

        $passes = $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.check_in_passes.viewer_role', 'member')
            ->assertJsonPath('data.check_in_passes.group', null)
            ->assertJsonPath('data.check_in_passes.mine_passenger_id', $passengers[1]->id)
            ->json('data.check_in_passes.passes');

        $this->assertCount(1, $passes);
        $this->assertSame($passengers[1]->qr_code, $passes[0]['code']);
        $this->assertTrue($passes[0]['is_mine']);
        $this->assertNull($passes[0]['pass_url']);
    }

    public function test_unlinked_friend_picks_who_they_are(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี', 'ซี']);
        $friend = User::factory()->create();
        $other = User::factory()->create();
        $this->member($booking, $friend, null);
        $this->member($booking, $other, $passengers[0]);

        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.check_in_passes.group.code', $booking->qr_code)
            ->assertJsonCount(0, 'data.check_in_passes.passes')
            ->assertJsonCount(2, 'data.check_in_passes.pickable_passengers');

        // ชื่อที่มีคนผูกไว้แล้วเลือกไม่ได้
        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/members/me/passenger", ['passenger_id' => $passengers[0]->id])
            ->assertStatus(422);

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/members/me/passenger", ['passenger_id' => $passengers[2]->id])
            ->assertOk()
            ->assertJsonPath('data.check_in_passes.mine_passenger_id', $passengers[2]->id)
            ->assertJsonPath('data.check_in_passes.passes.0.code', $passengers[2]->qr_code);

        // เลือกแล้วเลือกซ้ำไม่ได้
        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/members/me/passenger", ['passenger_id' => $passengers[1]->id])
            ->assertStatus(422);

        // คนนอกใบจองเลือกไม่ได้
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/members/me/passenger", ['passenger_id' => $passengers[1]->id])
            ->assertStatus(422);
    }

    public function test_one_invite_per_passenger_but_owner_who_stays_home_can_invite_everyone(): void
    {
        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);
        $service = app(BookingMemberService::class);

        $service->createInvite($booking, $passengers[0]->id, null, $booking->user_id);
        $service->createInvite($booking, $passengers[1]->id, null, $booking->user_id);

        $this->expectExceptionMessage('ผู้เดินทางคนนี้มีคำเชิญหรือเข้าร่วมในแอปแล้ว');
        $service->createInvite($booking, $passengers[1]->id, null, $booking->user_id);
    }

    public function test_admin_web_check_in_accepts_personal_codes(): void
    {
        Role::create(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        [, $booking, $passengers] = $this->bookingWith(['เอ', 'บี']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/check-in', ['qr_code' => $passengers[1]->qr_code])
            ->assertOk()
            ->assertJsonPath('message', 'เช็คอิน บี สำเร็จ (ขึ้นรถแล้ว 1/2)');

        $this->assertNull($passengers[0]->fresh()->checked_in_at);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/check-in', ['qr_code' => $passengers[1]->qr_code])
            ->assertStatus(422);

        // QR ของใบจอง = คนที่เหลือทั้งหมด
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/check-in', ['qr_code' => $booking->qr_code])
            ->assertOk();
        $this->assertNotNull($passengers[0]->fresh()->checked_in_at);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/check-in', ['qr_code' => $booking->qr_code])
            ->assertStatus(422);
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $names
     * @return array{0: TripSchedule, 1: Booking, 2: Collection<int, BookingPassenger>}
     */
    private function bookingWith(array $names, int $points = 0, bool $assignStaff = true): array
    {
        $owner = User::factory()->create();

        $trip = Trip::create([
            'title' => 'ทริปทดสอบเช็คอินรายคน',
            'slug' => 'per-passenger-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เขาใหญ่',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => count($names),
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $firstPoint = null;
        for ($i = 1; $i <= $points; $i++) {
            $point = SchedulePickupPoint::create([
                'schedule_id' => $schedule->id,
                'region' => 'bangkok',
                'region_label' => 'กรุงเทพ',
                'pickup_location' => 'จุดที่ '.$i,
                'price' => 0,
                'sort_order' => $i,
            ]);
            $firstPoint ??= $point;
        }

        if ($assignStaff) {
            $schedule->staff()->attach($this->staff->id, ['assigned_by' => $this->staff->id]);
        }

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $firstPoint?->id,
            'total_amount' => 1500 * count($names),
            'paid_amount' => 1500 * count($names),
        ]);

        $passengers = collect($names)->map(fn ($name) => BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => $name,
            'nickname' => $name,
        ]));

        return [$schedule->fresh('pickupPoints'), $booking->fresh('user'), $passengers->map->fresh()->values()];
    }

    private function member(Booking $booking, User $user, ?BookingPassenger $passenger): BookingMember
    {
        return BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'passenger_id' => $passenger?->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
            'invited_by' => $booking->user_id,
            'accepted_at' => now(),
        ]);
    }
}
