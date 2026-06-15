<?php

namespace Tests\Feature;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Models\ChatMessage;
use App\Models\ChatReaction;
use App\Models\ChatRead;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeEndedTripChatsJobTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(string $returnDate, ?string $departureDate = null): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Trip', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => 1000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate ?? $returnDate,
            'return_date' => $returnDate,
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    public function test_purges_chat_messages_images_reactions_and_reads_after_three_days(): void
    {
        Storage::fake(MediaDisk::name());
        $user = User::factory()->create();
        $schedule = $this->schedule(now()->subDays(4)->toDateString());

        $text = ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $user->id,
            'sender_role' => 'customer', 'body' => 'hello',
        ]);
        $imageMsg = ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $user->id,
            'sender_role' => 'customer', 'body' => null, 'image_path' => 'chat/pic.jpg',
        ]);
        Storage::disk(MediaDisk::name())->put('chat/pic.jpg', 'binary');
        ChatReaction::create([
            'message_id' => $imageMsg->id, 'user_id' => $user->id, 'emoji' => '👍',
        ]);
        ChatRead::create([
            'schedule_id' => $schedule->id, 'user_id' => $user->id, 'last_read_message_id' => $imageMsg->id,
        ]);

        (new PurgeEndedTripChatsJob)->handle();

        $this->assertDatabaseMissing('chat_messages', ['id' => $text->id]);
        $this->assertDatabaseMissing('chat_messages', ['id' => $imageMsg->id]);
        $this->assertDatabaseMissing('chat_message_reactions', ['message_id' => $imageMsg->id]);
        $this->assertDatabaseMissing('chat_reads', ['schedule_id' => $schedule->id]);
        Storage::disk(MediaDisk::name())->assertMissing('chat/pic.jpg');
    }

    public function test_does_not_purge_before_three_days_have_passed(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule(now()->subDays(1)->toDateString());

        $msg = ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $user->id,
            'sender_role' => 'customer', 'body' => 'still here',
        ]);

        (new PurgeEndedTripChatsJob)->handle();

        $this->assertDatabaseHas('chat_messages', ['id' => $msg->id]);
    }
}
