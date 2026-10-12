<?php

namespace Tests\Feature;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * ข้อความเสียงในห้องแชททริป — อัปโหลด, แสดงผล, แจ้งเตือน, ลบ และล้างพร้อมห้อง
 */
class ChatVoiceMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaDisk::name());
    }

    private function makeSchedule(string $departure = '+1 month'): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปเสียง',
            'slug' => 'voice-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $date = now()->modify($departure);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $date->toDateString(),
            'return_date' => $date->copy()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function member(TripSchedule $schedule, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'ผู้โดยสาร']);

        return $user;
    }

    private function voice(int $kb = 40, string $name = 'voice.m4a', string $mime = 'audio/mp4'): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, $mime);
    }

    private function sendVoice(User $user, TripSchedule $schedule, array $payload = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->post("/api/v1/schedules/{$schedule->id}/chat/messages", array_merge([
                'audio' => $this->voice(),
                'audio_duration_ms' => 12400,
            ], $payload), ['Accept' => 'application/json']);
    }

    public function test_member_sends_a_voice_message(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $data = $this->sendVoice($user, $schedule)->assertCreated()->json('data');

        $this->assertNull($data['body']);
        $this->assertNull($data['image_url']);
        $this->assertSame(12400, $data['audio_duration_ms']);
        $this->assertStringEndsWith('.m4a', $data['audio_url']);

        $message = ChatMessage::firstOrFail();
        $this->assertStringStartsWith('chat/audio/', $message->audio_path);
        Storage::disk(MediaDisk::name())->assertExists($message->audio_path);

        // เปิดห้องแล้วเห็นข้อความเสียงเดียวกัน (ห้องมีข้อความแล้ว จึงไม่มีข้อความต้อนรับแทรก)
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.audio_duration_ms', 12400)
            ->assertJsonPath('data.messages.0.audio_url', $data['audio_url']);

        Bus::assertDispatched(SendChatPushJob::class);
    }

    public function test_server_detected_mp4_audio_is_accepted_too(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        // libmagic บนเซิร์ฟเวอร์บางเครื่องอ่าน .m4a เป็น video/mp4
        $this->sendVoice($user, $schedule, ['audio' => $this->voice(mime: 'video/mp4')])->assertCreated();
        $this->sendVoice($user, $schedule, ['audio' => $this->voice(mime: 'audio/x-m4a')])->assertCreated();
    }

    public function test_voice_message_validation(): void
    {
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        // ต้องบอกความยาว
        $this->sendVoice($user, $schedule, ['audio_duration_ms' => null])
            ->assertStatus(422)->assertJsonValidationErrorFor('audio_duration_ms');
        // กดพลาดสั้น ๆ ไม่นับเป็นข้อความ
        $this->sendVoice($user, $schedule, ['audio_duration_ms' => 200])
            ->assertStatus(422)->assertJsonValidationErrorFor('audio_duration_ms');
        // ยาวเกินที่แอปอัดได้
        $this->sendVoice($user, $schedule, ['audio_duration_ms' => (ChatMessage::AUDIO_MAX_SECONDS + 10) * 1000])
            ->assertStatus(422)->assertJsonValidationErrorFor('audio_duration_ms');
        // เสียงส่งเดี่ยว ๆ ไม่มีข้อความหรือรูปประกอบ
        $this->sendVoice($user, $schedule, ['body' => 'ฟังหน่อย'])
            ->assertStatus(422);
        $this->sendVoice($user, $schedule, ['image' => UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(422);
        // ไม่ใช่ไฟล์เสียง
        $this->sendVoice($user, $schedule, ['audio' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrorFor('audio');
        // ใหญ่เกิน
        $this->sendVoice($user, $schedule, ['audio' => $this->voice(ChatMessage::AUDIO_MAX_KB + 1)])
            ->assertStatus(422)->assertJsonValidationErrorFor('audio');

        $this->assertSame(0, ChatMessage::whereNotNull('audio_path')->count());
    }

    public function test_non_member_cannot_send_a_voice_message(): void
    {
        $schedule = $this->makeSchedule();
        $outsider = User::factory()->create();

        $this->sendVoice($outsider, $schedule)->assertForbidden();
        $this->assertSame([], Storage::disk(MediaDisk::name())->allFiles('chat'));
    }

    public function test_push_preview_says_it_is_a_voice_message_with_its_length(): void
    {
        Bus::fake([SendChatPushJob::class]);
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule, ['nickname' => 'มานะ']);
        $other = $this->member($schedule);

        $this->sendVoice($sender, $schedule, ['audio_duration_ms' => 72_000])->assertCreated();

        $bodies = [];
        $this->mock(FcmService::class, function (MockInterface $m) use (&$bodies) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title, $body) use (&$bodies) {
                $bodies[(int) $userId] = $body;
            });
        });

        $message = ChatMessage::firstWhere('audio_path', '!=', null);
        app()->call([new SendChatPushJob($message->id, $sender->id), 'handle']);

        $this->assertSame('มานะ: 🎤 ข้อความเสียง 1:12', $bodies[$other->id] ?? null);
    }

    public function test_deleting_a_voice_message_removes_the_file_and_it_cannot_be_edited(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $id = $this->sendVoice($user, $schedule)->json('data.id');
        $path = ChatMessage::find($id)->audio_path;

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$id}", ['body' => 'แก้เป็นตัวหนังสือ'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/chat/messages/{$id}")
            ->assertOk()
            ->assertJsonPath('data.is_deleted', true)
            ->assertJsonPath('data.audio_url', null)
            ->assertJsonPath('data.audio_duration_ms', null);

        Storage::disk(MediaDisk::name())->assertMissing($path);
        $this->assertNull(ChatMessage::find($id)->audio_path);
    }

    public function test_lists_and_quotes_show_a_voice_message_for_what_it_is(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);
        $other = $this->member($schedule);

        $voiceId = $this->sendVoice($user, $schedule)->json('data.id');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/chat/my-conversations')
            ->assertJsonPath('data.0.last_message.has_audio', true)
            ->assertJsonPath('data.0.last_message.audio_duration_ms', 12400);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'body' => 'ได้ยินแล้วครับ',
                'reply_to_id' => $voiceId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.reply_to.has_audio', true)
            ->assertJsonPath('data.reply_to.has_image', false);
    }

    public function test_purge_removes_voice_files_with_the_room(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule('-10 days');
        $user = $this->member($schedule);

        $id = $this->sendVoice($user, $schedule)->json('data.id');
        $path = ChatMessage::find($id)->audio_path;
        Storage::disk(MediaDisk::name())->assertExists($path);

        (new PurgeEndedTripChatsJob)->handle();

        Storage::disk(MediaDisk::name())->assertMissing($path);
        $this->assertSame(0, ChatMessage::where('schedule_id', $schedule->id)->count());
    }
}
