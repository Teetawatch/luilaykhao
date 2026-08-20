<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingMemberTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Member Trip',
            'slug' => 'member-trip',
            'type' => 'trekking',
            'location' => 'Pai',
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

    /**
     * @param  array<int, array{name?: string, phone?: string, email?: string}>  $passengers
     */
    private function bookOnto(User $user, TripSchedule $schedule, array $passengers = [], string $status = 'confirmed'): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 1500,
        ]);

        $passengers = $passengers ?: [['name' => 'เจ้าของ', 'phone' => '0810000000']];
        foreach ($passengers as $p) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => 'Mr.',
                'name' => $p['name'] ?? 'Passenger',
                'phone' => $p['phone'] ?? null,
                'email' => $p['email'] ?? null,
            ]);
        }

        return $booking;
    }

    private function invite(User $owner, Booking $booking, array $body = []): string
    {
        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/invites", $body)
            ->assertCreated();

        return $response->json('data.invite_token');
    }

    public function test_owner_can_create_invite_and_friend_can_accept(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->bookOnto($owner, $schedule, [
            ['name' => 'เจ้าของ'],
            ['name' => 'เพื่อน'],
        ]);

        $token = $this->invite($owner, $booking, ['label' => 'บอม']);
        $this->assertNotEmpty($token);

        $friend = User::factory()->create();

        // พรีวิวก่อนรับ
        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/booking-invites/{$token}")
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref)
            ->assertJsonPath('data.invite_label', 'บอม')
            ->assertJsonPath('data.already_member', false);

        // รับคำเชิญ
        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref);

        $this->assertDatabaseHas('booking_members', [
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'status' => BookingMember::STATUS_ACTIVE,
            'invite_token' => null,
        ]);
    }

    public function test_companion_can_access_chat_and_tracking_after_accepting(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->bookOnto($owner, $schedule, [['name' => 'a'], ['name' => 'b']]);
        $token = $this->invite($owner, $booking);
        $friend = User::factory()->create();

        // ก่อนรับคำเชิญ — เข้าแชทไม่ได้
        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertStatus(403);

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$token}/accept")
            ->assertOk();

        // หลังรับคำเชิญ — เข้าแชทได้
        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertOk();

        // และดูข้อมูลติดตามรถได้
        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/tracking")
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $booking->booking_ref);
    }

    public function test_companion_sees_booking_in_their_list(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->bookOnto($owner, $schedule, [['name' => 'a'], ['name' => 'b']]);
        $token = $this->invite($owner, $booking);
        $friend = User::factory()->create();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$token}/accept")->assertOk();

        $this->actingAs($friend, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.booking_ref', $booking->booking_ref)
            ->assertJsonPath('data.0.viewer_is_owner', false);
    }

    public function test_roster_returns_pending_invite_link_to_owner_only(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->bookOnto($owner, $schedule, [
            ['name' => 'a'],
            ['name' => 'b'],
            ['name' => 'c'],
        ]);

        $token = $this->invite($owner, $booking, ['label' => 'บอม']);

        // เจ้าของเห็นลิงก์ของคำเชิญที่ยังไม่ถูกรับ จึงส่งซ้ำได้โดยไม่ต้องสร้างใบใหม่
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/members")
            ->assertOk()
            ->assertJsonPath('data.viewer_is_owner', true)
            ->assertJsonPath('data.max_members', 3)
            ->assertJsonPath('data.remaining_slots', 1)
            ->assertJsonPath('data.members.0.invite_token', $token)
            ->assertJsonPath('data.members.0.invite_url', url('/join/'.$token));

        // เพื่อนที่เข้าร่วมแล้วเห็นรายชื่อได้ แต่ไม่เห็นลิงก์คำเชิญของคนอื่น
        $friendToken = $this->invite($owner, $booking);
        $friend = User::factory()->create();
        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$friendToken}/accept")->assertOk();

        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/members")
            ->assertOk()
            ->assertJsonPath('data.viewer_is_owner', false)
            ->assertJsonPath('data.members.0.invite_token', null)
            ->assertJsonPath('data.members.0.invite_url', null);
    }

    public function test_non_owner_cannot_invite_or_revoke(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->bookOnto($owner, $schedule, [['name' => 'a'], ['name' => 'b']]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/invites", [])
            ->assertStatus(403);
    }

    public function test_owner_can_revoke_member(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->bookOnto($owner, $schedule, [['name' => 'a'], ['name' => 'b']]);
        $token = $this->invite($owner, $booking);
        $friend = User::factory()->create();
        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$token}/accept")->assertOk();

        $member = BookingMember::where('booking_id', $booking->id)->where('user_id', $friend->id)->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->booking_ref}/members/{$member->id}")
            ->assertOk();

        $this->assertDatabaseMissing('booking_members', ['id' => $member->id]);

        // เมื่อถูกถอนแล้ว เข้าแชทไม่ได้อีก
        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertStatus(403);
    }

    public function test_capacity_is_limited_to_passenger_count(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        // 2 ผู้โดยสาร = เชิญได้สูงสุด 1 คน (เจ้าของนับเป็นหนึ่ง)
        $booking = $this->bookOnto($owner, $schedule, [['name' => 'a'], ['name' => 'b']]);

        $this->invite($owner, $booking);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/invites", [])
            ->assertStatus(422);
    }

    public function test_invalid_or_used_token_cannot_be_accepted(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->bookOnto($owner, $schedule, [['name' => 'a'], ['name' => 'b']]);
        $token = $this->invite($owner, $booking);
        $friend = User::factory()->create();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$token}/accept")->assertOk();

        // โทเค็นถูกใช้แล้ว — ใช้ซ้ำไม่ได้
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$token}/accept")
            ->assertStatus(422);

        // โทเค็นมั่ว
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/booking-invites/doesnotexist/accept')
            ->assertStatus(422);
    }

    public function test_owner_cannot_accept_own_invite(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->bookOnto($owner, $schedule, [['name' => 'a'], ['name' => 'b']]);
        $token = $this->invite($owner, $booking);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$token}/accept")
            ->assertStatus(422);
    }

    public function test_backfill_links_passengers_by_phone(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create(['phone' => '0810000000']);
        $friend = User::factory()->create(['phone' => '0899999999']);

        $booking = $this->bookOnto($owner, $schedule, [
            ['name' => 'เจ้าของ', 'phone' => '0810000000'],
            ['name' => 'เพื่อนมีบัญชี', 'phone' => '0899999999'],
            ['name' => 'เพื่อนไม่มีบัญชี', 'phone' => '0877777777'],
        ]);

        $this->artisan('bookings:backfill-members')
            ->assertSuccessful();

        // เพื่อนที่เบอร์ตรงกับบัญชีถูกผูกเป็นสมาชิก, เจ้าของไม่ถูกผูกซ้ำ
        $this->assertDatabaseHas('booking_members', [
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseMissing('booking_members', [
            'booking_id' => $booking->id,
            'user_id' => $owner->id,
        ]);
        $this->assertSame(1, BookingMember::where('booking_id', $booking->id)->count());
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create(['phone' => '0810000000']);
        User::factory()->create(['phone' => '0899999999']);
        $booking = $this->bookOnto($owner, $schedule, [
            ['name' => 'a', 'phone' => '0810000000'],
            ['name' => 'b', 'phone' => '0899999999'],
        ]);

        $this->artisan('bookings:backfill-members --dry-run')->assertSuccessful();

        $this->assertSame(0, BookingMember::where('booking_id', $booking->id)->count());
    }
}
