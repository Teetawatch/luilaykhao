<?php

namespace Tests\Feature;

use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
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
            'slug' => 'chat-trip',
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
}
