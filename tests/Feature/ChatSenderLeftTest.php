<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\ChatMessage;
use App\Models\ScheduleStaffAssignment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ป้าย "ออกจากทริปแล้ว" — ข้อความเก่าของลูกทริปที่ไม่ได้อยู่ในรอบแล้วต้องติดธง
 * sender_left โดยไม่มีข้อความระบบเด้งเข้าห้อง
 */
class ChatSenderLeftTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $trip = Trip::create([
            'title' => 'Left Trip',
            'slug' => 'left-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function book(User $user, string $status = 'confirmed'): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 1500,
        ]);
    }

    private function say(User $user, string $body, string $role = 'customer'): ChatMessage
    {
        return ChatMessage::create([
            'schedule_id' => $this->schedule->id,
            'user_id' => $user->id,
            'sender_role' => $role,
            'body' => $body,
        ]);
    }

    /** @return array<string, bool> body => sender_left */
    private function flagsSeenBy(User $viewer): array
    {
        $messages = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/messages")
            ->assertOk()
            ->json('data.messages');

        return collect($messages)
            ->filter(fn ($m) => $m['sender_role'] !== 'system')
            ->mapWithKeys(fn ($m) => [$m['body'] => $m['sender_left']])
            ->all();
    }

    public function test_only_people_no_longer_on_the_round_are_flagged(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $revoked = User::factory()->create();
        $cancelled = User::factory()->create();
        $staff = User::factory()->create();

        $ownerBooking = $this->book($owner);
        BookingMember::create([
            'booking_id' => $ownerBooking->id,
            'user_id' => $friend->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);
        BookingMember::create([
            'booking_id' => $ownerBooking->id,
            'user_id' => $revoked->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_REVOKED,
        ]);
        $leaver = $this->book($cancelled);
        ScheduleStaffAssignment::create(['schedule_id' => $this->schedule->id, 'user_id' => $staff->id]);

        $this->say($owner, 'owner');
        $this->say($friend, 'friend');
        $this->say($revoked, 'revoked');
        $this->say($cancelled, 'cancelled');
        $this->say($staff, 'staff', 'staff');

        $leaver->update(['status' => 'cancelled']);

        $this->assertSame([
            'owner' => false,
            'friend' => false,
            'revoked' => true,
            'cancelled' => true,
            'staff' => false,
        ], $this->flagsSeenBy($owner));

        // ไม่ประกาศในห้อง — ห้องมีแค่ข้อความต้อนรับเป็นข้อความระบบ
        $this->assertSame(
            0,
            ChatMessage::where('schedule_id', $this->schedule->id)
                ->where('sender_role', 'system')
                ->where('body', 'like', '%ออก%')
                ->count(),
        );
    }

    public function test_finished_trip_does_not_mark_everyone_as_left(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $booking = $this->book($owner);
        ScheduleStaffAssignment::create(['schedule_id' => $this->schedule->id, 'user_id' => $staff->id]);

        $this->say($owner, 'owner');
        $booking->update(['status' => 'completed']);

        // ทริปจบแล้วลูกทริปเข้าห้องไม่ได้ แต่ทีมงานยังเปิดอ่านได้ — ต้องไม่เห็นทุกคน "ออก"
        $this->assertSame(['owner' => false], $this->flagsSeenBy($staff));
    }

    public function test_rebooking_the_same_round_clears_the_label(): void
    {
        $owner = User::factory()->create();
        $back = User::factory()->create();
        $this->book($owner);
        $first = $this->book($back);

        $this->say($back, 'back');
        $first->update(['status' => 'cancelled']);
        $this->assertTrue($this->flagsSeenBy($owner)['back']);

        $this->book($back);
        $this->assertFalse($this->flagsSeenBy($owner)['back']);
    }
}
