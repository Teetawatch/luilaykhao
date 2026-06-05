<?php

namespace Tests\Feature;

use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Chat Trip',
            'slug' => 'chat-trip-'.uniqid(),
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

    private function bookOnto(User $user, TripSchedule $schedule, string $status = 'confirmed'): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'Passenger',
            'phone' => '0812345678',
        ]);

        return $booking;
    }

    public function test_customer_with_active_booking_can_post_and_read_messages(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $this->bookOnto($user, $schedule);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'body' => 'สวัสดีครับ ทริปนี้เจอกันกี่โมง',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sender_role', 'customer')
            ->assertJsonPath('data.is_mine', true);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'สวัสดีครับ ทริปนี้เจอกันกี่โมง');

        Bus::assertDispatched(SendChatPushJob::class);
    }

    public function test_customer_can_send_image_message(): void
    {
        Bus::fake();
        Storage::fake('public');
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $this->bookOnto($user, $schedule);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'image' => UploadedFile::fake()->image('photo.jpg', 800, 600),
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', null);

        $imageUrl = $response->json('data.image_url');
        $this->assertNotEmpty($imageUrl);

        $message = ChatMessage::firstOrFail();
        Storage::disk('public')->assertExists($message->image_path);
    }

    public function test_message_requires_body_or_image(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $this->bookOnto($user, $schedule);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('body');
    }

    public function test_non_member_cannot_access_chat(): void
    {
        $schedule = $this->makeSchedule();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertStatus(403);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'body' => 'hello',
            ])
            ->assertStatus(403);
    }

    public function test_assigned_staff_posts_with_staff_role(): void
    {
        Bus::fake();
        Role::findOrCreate('staff');
        $schedule = $this->makeSchedule();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'body' => 'เจอกัน 6 โมงเช้าที่ปั๊มนะครับ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sender_role', 'staff');
    }

    public function test_admin_posts_with_admin_role(): void
    {
        Bus::fake();
        Role::findOrCreate('admin');
        $schedule = $this->makeSchedule();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'body' => 'แจ้งเปลี่ยนแปลงกำหนดการเล็กน้อยครับ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sender_role', 'admin');
    }

    public function test_after_id_returns_only_newer_messages_oldest_first(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $this->bookOnto($user, $schedule);

        $first = ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $user->id, 'sender_role' => 'customer', 'body' => 'หนึ่ง']);
        ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $user->id, 'sender_role' => 'customer', 'body' => 'สอง']);
        ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $user->id, 'sender_role' => 'customer', 'body' => 'สาม']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages?after_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'สอง')
            ->assertJsonPath('data.messages.1.body', 'สาม')
            ->assertJsonPath('data.has_more', false);
    }

    public function test_unread_count_and_mark_read(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->bookOnto($alice, $schedule);
        $this->bookOnto($bob, $schedule);

        // Alice ส่ง 2 ข้อความ
        ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $alice->id, 'sender_role' => 'customer', 'body' => 'หนึ่ง']);
        ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $alice->id, 'sender_role' => 'customer', 'body' => 'สอง']);

        $this->actingAs($bob, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/unread-count")
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->actingAs($bob, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/read")
            ->assertOk();

        $this->actingAs($bob, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/unread-count")
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_room_returns_members_vehicle_and_read_positions(): void
    {
        Bus::fake();
        Role::findOrCreate('staff');
        $schedule = $this->makeSchedule();

        $vehicle = Vehicle::create([
            'name' => 'รถตู้คันที่ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'กข 1234',
            'driver_name' => 'พี่สมชาย',
            'driver_phone' => '0801112222',
        ]);
        $schedule->vehicle_id = $vehicle->id;
        $schedule->save();

        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->bookOnto($alice, $schedule);
        $this->bookOnto($bob, $schedule);
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        // Alice ส่ง 2 ข้อความ, Bob อ่านถึงข้อความแรกเท่านั้น
        $first = ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $alice->id, 'sender_role' => 'customer', 'body' => 'หนึ่ง']);
        $second = ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $alice->id, 'sender_role' => 'customer', 'body' => 'สอง']);

        $this->actingAs($bob, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/read", ['message_id' => $first->id])
            ->assertOk();

        $response = $this->actingAs($alice, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertOk()
            ->assertJsonPath('data.member_count', 3)
            ->assertJsonPath('data.vehicle.name', 'รถตู้คันที่ 1')
            ->assertJsonPath('data.vehicle.driver_name', 'พี่สมชาย');

        $members = collect($response->json('data.members'));
        $this->assertEqualsCanonicalizing(
            ['customer', 'customer', 'staff'],
            $members->pluck('role')->all(),
        );

        // Bob อ่านถึงข้อความแรก → last_read_message_id ตรงกับ id ข้อความแรก
        $bobMember = $members->firstWhere('id', $bob->id);
        $this->assertSame($first->id, $bobMember['last_read_message_id']);
        $this->assertLessThan($second->id, $bobMember['last_read_message_id']);

        // Alice เห็นตัวเองเป็น is_me
        $this->assertTrue($members->firstWhere('id', $alice->id)['is_me']);
    }

    public function test_room_forbidden_for_non_member(): void
    {
        $schedule = $this->makeSchedule();
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertForbidden();
    }

    private function makeStaff(TripSchedule $schedule): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    public function test_reply_stores_and_presents_excerpt(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->bookOnto(User::factory()->create(), $schedule)->user;

        $original = ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $user->id,
            'sender_role' => 'customer', 'body' => 'จุดนัดพบที่ไหนครับ',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'body' => 'ปั๊ม ปตท. พระราม 2 ครับ',
                'reply_to_id' => $original->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.reply_to.id', $original->id)
            ->assertJsonPath('data.reply_to.body', 'จุดนัดพบที่ไหนครับ');
    }

    public function test_reply_to_message_from_another_schedule_is_rejected(): void
    {
        $scheduleA = $this->makeSchedule();
        $scheduleB = $this->makeSchedule();
        $user = User::factory()->create();
        $this->bookOnto($user, $scheduleA);

        $foreign = ChatMessage::create([
            'schedule_id' => $scheduleB->id, 'user_id' => $user->id,
            'sender_role' => 'customer', 'body' => 'ห้องอื่น',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$scheduleA->id}/chat/messages", [
                'body' => 'reply ข้ามห้อง',
                'reply_to_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('reply_to_id');
    }

    public function test_staff_can_pin_and_room_returns_pinned(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);

        $message = ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $staff->id,
            'sender_role' => 'staff', 'body' => 'นัดเจอ 6 โมงเช้า',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$message->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.pinned_message.id', $message->id);

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertOk()
            ->assertJsonPath('data.pinned_message.id', $message->id)
            ->assertJsonPath('data.can_moderate', true);
    }

    public function test_pinning_a_new_message_replaces_the_previous_pin(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);

        $first = ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $staff->id, 'sender_role' => 'staff', 'body' => 'เก่า']);
        $second = ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $staff->id, 'sender_role' => 'staff', 'body' => 'ใหม่']);

        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$first->id}/pin")->assertOk();
        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$second->id}/pin")->assertOk();

        $this->assertNull($first->fresh()->pinned_at);
        $this->assertNotNull($second->fresh()->pinned_at);
    }

    public function test_customer_cannot_pin(): void
    {
        $schedule = $this->makeSchedule();
        $customer = User::factory()->create();
        $this->bookOnto($customer, $schedule);

        $message = ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $customer->id,
            'sender_role' => 'customer', 'body' => 'ขอปักหมุดเอง',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$message->id}/pin")
            ->assertForbidden();
    }

    public function test_unpin_clears_pinned_message(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);
        $message = ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $staff->id, 'sender_role' => 'staff', 'body' => 'หมุด']);

        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$message->id}/pin")->assertOk();
        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$message->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.pinned_message', null);

        $this->assertNull($message->fresh()->pinned_at);
    }

    public function test_reaction_toggles_on_and_off(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $this->bookOnto($user, $schedule);
        $message = ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $user->id, 'sender_role' => 'customer', 'body' => 'เย้']);

        // Add 👍
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$message->id}/react", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.emoji', '👍')
            ->assertJsonPath('data.reactions.0.count', 1);

        // Toggle off
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$message->id}/react", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonCount(0, 'data.reactions');

        $this->assertDatabaseCount('chat_message_reactions', 0);
    }

    public function test_reaction_rejects_unknown_emoji(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $this->bookOnto($user, $schedule);
        $message = ChatMessage::create(['schedule_id' => $schedule->id, 'user_id' => $user->id, 'sender_role' => 'customer', 'body' => 'x']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$message->id}/react", ['emoji' => '💩'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('emoji');
    }

    public function test_typing_ok_for_member_and_forbidden_for_stranger(): void
    {
        $schedule = $this->makeSchedule();
        $member = User::factory()->create();
        $this->bookOnto($member, $schedule);
        $stranger = User::factory()->create();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/typing")
            ->assertOk();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/typing")
            ->assertForbidden();
    }

    public function test_depart_posts_system_message_into_chat(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->makeStaff($schedule);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/depart")
            ->assertOk();

        $this->assertDatabaseHas('chat_messages', [
            'schedule_id' => $schedule->id,
            'sender_role' => 'system',
        ]);
    }
}
