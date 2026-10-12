<?php

namespace Tests\Feature;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ChatPrivateMessage;
use App\Models\ChatPrivateThread;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ถามทีมงานแบบส่วนตัว — เธรดแยกระหว่างลูกทริปหนึ่งคนกับทีมงานของรอบ
 */
class ChatPrivateTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{user: int, title: string, body: string, data: array}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake(MediaDisk::name());
        // สร้างไว้ก่อนมี request แรก — แคชบทบาทของ spatie จะได้ไม่ค้างเป็นชุดว่าง
        Role::findOrCreate('staff');

        $this->pushes = [];
        $this->mock(FcmService::class, function (MockInterface $m) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title, $body, $data = []) {
                $this->pushes[] = ['user' => (int) $userId, 'title' => $title, 'body' => $body, 'data' => $data];
            });
        });
    }

    private function makeSchedule(string $departure = '+3 days'): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'private-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
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

    private function traveller(TripSchedule $schedule, string $nickname = 'มานี'): User
    {
        $user = User::factory()->create(['nickname' => $nickname]);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => $nickname]);

        return $user;
    }

    private function staff(TripSchedule $schedule, string $nickname = 'พี่ต้น'): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create(['nickname' => $nickname]);
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function url(TripSchedule $schedule, string $path = 'messages'): string
    {
        return "/api/v1/schedules/{$schedule->id}/chat/private/{$path}";
    }

    private function ask(User $user, TripSchedule $schedule, string $body, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson($this->url($schedule), ['body' => $body, ...$extra]);
    }

    private function pushesTo(User $user): array
    {
        return array_values(array_filter($this->pushes, fn ($p) => $p['user'] === $user->id));
    }

    public function test_traveller_asks_the_team_privately_and_staff_are_notified(): void
    {
        $schedule = $this->makeSchedule();
        $mani = $this->traveller($schedule);
        $staffA = $this->staff($schedule, 'พี่ต้น');
        $staffB = $this->staff($schedule, 'พี่ฝน');

        $data = $this->ask($mani, $schedule, 'เป็นประจำเดือน มีผ้าอนามัยสำรองไหมคะ')
            ->assertCreated()
            ->json('data');

        $this->assertTrue($data['message']['is_mine']);
        $this->assertTrue($data['message']['from_customer']);
        $this->assertSame($mani->id, $data['thread']['thread_user_id']);

        // ไม่หลุดเข้าห้องรวม
        $this->assertSame(0, ChatMessage::count());

        foreach ([$staffA, $staffB] as $staff) {
            $push = $this->pushesTo($staff);
            $this->assertCount(1, $push);
            $this->assertSame('🔒 มานี ถามทีมงานแบบส่วนตัว', $push[0]['title']);
            $this->assertStringContainsString('ผ้าอนามัย', $push[0]['body']);
            $this->assertSame('chat_private', $push[0]['data']['type']);
            $this->assertSame((string) $mani->id, $push[0]['data']['user_id']);
        }
        $this->assertSame([], $this->pushesTo($mani));
    }

    public function test_staff_reply_reaches_only_the_owner_and_read_state_is_shared_by_the_team(): void
    {
        $schedule = $this->makeSchedule();
        $mani = $this->traveller($schedule);
        $other = $this->traveller($schedule, 'ชูใจ');
        $staffA = $this->staff($schedule, 'พี่ต้น');
        $staffB = $this->staff($schedule, 'พี่ฝน');

        $this->ask($mani, $schedule, 'ปวดท้องค่ะ')->assertCreated();

        // ทีมงานเห็นในกล่องข้อความ ยังไม่อ่าน 1
        $this->actingAs($staffB, 'sanctum')->getJson($this->url($schedule, 'threads'))
            ->assertOk()
            ->assertJsonCount(1, 'data.threads')
            ->assertJsonPath('data.threads.0.customer.nickname', 'มานี')
            ->assertJsonPath('data.threads.0.unread', 1)
            ->assertJsonPath('data.threads.0.last_message.preview', 'ปวดท้องค่ะ');

        $this->pushes = [];
        $reply = $this->ask($staffA, $schedule, 'เดี๋ยวพี่เอายาไปให้ที่นั่งนะครับ', ['user_id' => $mani->id])
            ->assertCreated()
            ->json('data.message');
        $this->assertSame('staff', $reply['sender_role']);
        $this->assertFalse($reply['from_customer']);

        $this->assertCount(1, $this->pushesTo($mani));
        $this->assertSame('🔒 ทีมงานตอบกลับ', $this->pushesTo($mani)[0]['title']);
        $this->assertStringContainsString('พี่ต้น: เดี๋ยวพี่เอายาไป', $this->pushesTo($mani)[0]['body']);
        $this->assertSame([], $this->pushesTo($other));
        $this->assertSame([], $this->pushesTo($staffB), 'คำถามมีคนตอบแล้ว ทีมงานคนอื่นไม่ต้องเด้ง');

        // สตาฟคนหนึ่งตอบ = ทีมอ่านแล้ว
        $this->actingAs($staffB, 'sanctum')->getJson($this->url($schedule, 'threads'))
            ->assertJsonPath('data.threads.0.unread', 0);

        // เจ้าของเห็นทั้งเธรด ยังไม่อ่าน 1 จากทีมงาน
        $this->actingAs($mani, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.private_chat.role', 'customer')
            ->assertJsonPath('data.private_chat.unread', 1);
        $this->actingAs($mani, 'sanctum')->getJson('/api/v1/chat/my-conversations')
            ->assertJsonPath('data.0.private_unread', 1);

        $this->actingAs($mani, 'sanctum')->getJson($this->url($schedule))
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.viewer_role', 'customer')
            ->assertJsonPath('data.messages.1.user.nickname', 'พี่ต้น');

        $this->actingAs($mani, 'sanctum')->postJson($this->url($schedule, 'read'))
            ->assertOk()
            ->assertJsonPath('data.thread.customer_read_id', $reply['id']);
        $this->actingAs($mani, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.private_chat.unread', 0);
    }

    public function test_travellers_cannot_see_each_others_threads(): void
    {
        $schedule = $this->makeSchedule();
        $mani = $this->traveller($schedule);
        $chujai = $this->traveller($schedule, 'ชูใจ');
        $this->staff($schedule);

        $this->ask($mani, $schedule, 'เรื่องส่วนตัวมาก')->assertCreated();

        // ขอเธรดของคนอื่น
        $this->actingAs($chujai, 'sanctum')->getJson($this->url($schedule).'?user_id='.$mani->id)
            ->assertForbidden();
        $this->ask($chujai, $schedule, 'แอบส่ง', ['user_id' => $mani->id])->assertForbidden();
        $this->actingAs($chujai, 'sanctum')->getJson($this->url($schedule, 'threads'))->assertForbidden();

        // เธรดของตัวเองว่าง ไม่เห็นของคนอื่นปนมา
        $this->actingAs($chujai, 'sanctum')->getJson($this->url($schedule))
            ->assertOk()
            ->assertJsonCount(0, 'data.messages')
            ->assertJsonPath('data.thread', null);

        // คนนอกรอบ
        $outsider = User::factory()->create();
        $this->ask($outsider, $schedule, 'สวัสดี')->assertForbidden();
    }

    public function test_staff_must_target_a_traveller_of_this_round(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->staff($schedule);
        $otherStaff = $this->staff($schedule, 'พี่ฝน');
        $stranger = User::factory()->create();

        $this->ask($staff, $schedule, 'ทัก')->assertStatus(422);
        $this->ask($staff, $schedule, 'ทัก', ['user_id' => $stranger->id])->assertStatus(422);
        $this->ask($staff, $schedule, 'ทัก', ['user_id' => $otherStaff->id])->assertStatus(422);

        // ทีมงานเริ่มคุยก่อนได้
        $mani = $this->traveller($schedule);
        $this->ask($staff, $schedule, 'คุณมานีมีค่าใช้จ่ายเพิ่ม 200 บาทนะครับ', ['user_id' => $mani->id])->assertCreated();
        $this->assertCount(1, $this->pushesTo($mani));
    }

    public function test_room_summary_tells_staff_how_many_are_waiting(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->staff($schedule);
        $a = $this->traveller($schedule);
        $b = $this->traveller($schedule, 'ชูใจ');

        $this->ask($a, $schedule, 'ข้อ 1')->assertCreated();
        $this->ask($a, $schedule, 'ข้อ 2')->assertCreated();
        $this->ask($b, $schedule, 'ข้อ 3')->assertCreated();

        $this->actingAs($staff, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.private_chat.role', 'staff')
            ->assertJsonPath('data.private_chat.unread', 3)
            ->assertJsonPath('data.private_chat.unread_threads', 2);
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/chat/my-conversations')
            ->assertJsonPath('data.0.private_unread', 3);

        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule, 'read'), ['user_id' => $a->id])->assertOk();

        $this->actingAs($staff, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.private_chat.unread', 1)
            ->assertJsonPath('data.private_chat.unread_threads', 1);
    }

    public function test_traveller_sees_who_will_read_and_released_staff_still_get_questions_after_the_trip(): void
    {
        $schedule = $this->makeSchedule();
        $mani = $this->traveller($schedule);

        // ยังไม่มีสตาฟ — แอปจะบอกให้ทักศูนย์ช่วยเหลือ
        $this->actingAs($mani, 'sanctum')->getJson($this->url($schedule))
            ->assertJsonCount(0, 'data.staff');

        $staff = $this->staff($schedule, 'พี่ต้น');
        $this->actingAs($mani, 'sanctum')->getJson($this->url($schedule))
            ->assertJsonPath('data.staff.0.nickname', 'พี่ต้น');

        // จบทริป สตาฟถูกปลดตีสี่ — คำถามหลังทริปยังต้องถึงคนที่ดูแลรอบนี้
        $schedule->staff()->updateExistingPivot($staff->id, ['released_at' => now()]);
        $this->ask($mani, $schedule, 'ลืมแว่นไว้บนรถค่ะ')->assertCreated();
        $this->assertCount(1, $this->pushesTo($staff));
    }

    public function test_voice_and_image_messages_and_deleting_your_own(): void
    {
        $schedule = $this->makeSchedule();
        $mani = $this->traveller($schedule);
        $staff = $this->staff($schedule);

        $voice = $this->actingAs($mani, 'sanctum')->post($this->url($schedule), [
            'audio' => UploadedFile::fake()->create('voice.m4a', 30, 'audio/mp4'),
            'audio_duration_ms' => 9000,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.message');

        $this->assertNull($voice['body']);
        $this->assertSame(9000, $voice['audio_duration_ms']);
        $this->assertStringContainsString('🎤 ข้อความเสียง 0:09', $this->pushesTo($staff)[0]['body']);

        $image = $this->actingAs($mani, 'sanctum')->post($this->url($schedule), [
            'image' => UploadedFile::fake()->image('rash.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.message');
        $this->assertNotEmpty($image['image_url']);

        $voicePath = ChatPrivateMessage::find($voice['id'])->audio_path;
        $this->assertStringStartsWith('chat/private/audio/', $voicePath);
        Storage::disk(MediaDisk::name())->assertExists($voicePath);

        // ทีมงานลบข้อความของลูกทริปไม่ได้
        $this->actingAs($staff, 'sanctum')->deleteJson($this->url($schedule, "messages/{$voice['id']}"))->assertForbidden();

        $this->actingAs($mani, 'sanctum')->deleteJson($this->url($schedule, "messages/{$voice['id']}"))
            ->assertOk()
            ->assertJsonPath('data.message.is_deleted', true)
            ->assertJsonPath('data.message.audio_url', null);
        Storage::disk(MediaDisk::name())->assertMissing($voicePath);
    }

    public function test_message_validation(): void
    {
        $schedule = $this->makeSchedule();
        $mani = $this->traveller($schedule);

        $this->actingAs($mani, 'sanctum')->postJson($this->url($schedule), [])
            ->assertStatus(422)->assertJsonValidationErrorFor('body');
        $this->actingAs($mani, 'sanctum')->postJson($this->url($schedule), ['body' => str_repeat('ก', 2001)])
            ->assertStatus(422);
        $this->assertSame(0, ChatPrivateThread::count());
    }

    public function test_realtime_channels_are_limited_to_the_owner_and_the_team(): void
    {
        $schedule = $this->makeSchedule();
        $mani = $this->traveller($schedule);
        $chujai = $this->traveller($schedule, 'ชูใจ');
        $staff = $this->staff($schedule);

        // ตัวกระจายข่าวในเทสอนุญาตทุกช่อง — เรียกเงื่อนไขของช่องใน routes/channels.php ตรง ๆ
        $channels = collect(Broadcast::getChannels());
        $authorize = function (User $user, string $channel) use ($channels): bool {
            foreach ($channels as $pattern => $callback) {
                $regex = '/^'.preg_replace('/\\\\\{[^}]+\\\\\}/', '([^\.]+)', preg_quote($pattern, '/')).'$/';
                if (preg_match($regex, $channel, $m)) {
                    return (bool) $callback($user, ...array_slice($m, 1));
                }
            }
            $this->fail("ไม่พบช่อง {$channel}");
        };

        $own = "chat.private.{$schedule->id}.{$mani->id}";
        $staffChannel = "chat.private-staff.{$schedule->id}";

        $this->assertTrue($authorize($mani, $own));
        $this->assertFalse($authorize($chujai, $own));
        $this->assertTrue($authorize($staff, $own));
        $this->assertTrue($authorize($staff, $staffChannel));
        $this->assertFalse($authorize($mani, $staffChannel));
        // ช่องห้องรวมไม่ถูกจับคู่ผิดเป็นช่องส่วนตัว
        $this->assertTrue($authorize($chujai, "chat.schedule.{$schedule->id}"));
    }

    public function test_threads_are_purged_with_the_room_after_the_trip(): void
    {
        $schedule = $this->makeSchedule('-10 days');
        $mani = $this->traveller($schedule);
        $this->staff($schedule);

        $id = $this->actingAs($mani, 'sanctum')->post($this->url($schedule), [
            'audio' => UploadedFile::fake()->create('voice.m4a', 30, 'audio/mp4'),
            'audio_duration_ms' => 4000,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.message.id');
        $path = ChatPrivateMessage::find($id)->audio_path;

        // รอบที่ยังไม่จบไม่โดน
        $live = $this->makeSchedule('+2 days');
        $liveUser = $this->traveller($live);
        $this->ask($liveUser, $live, 'ยังอยู่')->assertCreated();

        (new PurgeEndedTripChatsJob)->handle();

        $this->assertSame(0, ChatPrivateThread::where('schedule_id', $schedule->id)->count());
        $this->assertSame(0, ChatPrivateMessage::where('id', $id)->count());
        Storage::disk(MediaDisk::name())->assertMissing($path);
        $this->assertSame(1, ChatPrivateThread::where('schedule_id', $live->id)->count());
    }
}
