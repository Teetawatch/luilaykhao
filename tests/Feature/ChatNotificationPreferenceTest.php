<?php

namespace Tests\Feature;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\ChatRead;
use App\Models\ChatRoomPreference;
use App\Models\FcmToken;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\ChatService;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{user: int, title: string, body: string, data: array, display: array}> */
    private array $sent = [];

    private function makeSchedule(?string $departure = null): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'chat-notify-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Loei',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $departure ??= now()->addMonth()->toDateString();

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function member(TripSchedule $schedule, ?string $level = null): User
    {
        $user = User::factory()->create();
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);

        if ($level !== null) {
            app(ChatService::class)->setNotifyLevel($user, $schedule, $level);
        }

        return $user;
    }

    private function staff(TripSchedule $schedule): User
    {
        $staff = User::factory()->create();
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function postMessage(TripSchedule $schedule, User $sender, string $role = 'customer', array $mentions = []): ChatMessage
    {
        return ChatMessage::create([
            'schedule_id' => $schedule->id,
            'user_id' => $sender->id,
            'sender_role' => $role,
            'body' => 'ข้อความทดสอบ',
            'mentions' => $mentions ?: null,
        ]);
    }

    /** อ่านทุกอย่างในห้องแล้ว — ข้อความระบบตอนเข้าร่วมทริปจะได้ไม่ถูกนับปน */
    private function caughtUp(TripSchedule $schedule, User $user): void
    {
        app(ChatService::class)->markRead(
            $user,
            $schedule,
            (int) ChatMessage::where('schedule_id', $schedule->id)->max('id'),
        );
    }

    /** ส่งข้อความผ่าน job จริง แต่จับ push ไว้แทนการยิง FCM */
    private function push(ChatMessage $message, array $mentions = []): void
    {
        $sent = &$this->sent;
        $fcm = new class($sent) extends FcmService
        {
            public function __construct(private array &$sink) {}

            public function sendToUser(int $userId, string $title, string $body, array $data = [], array $display = []): void
            {
                $this->sink[] = compact('title', 'body', 'data', 'display') + ['user' => $userId];
            }
        };

        (new SendChatPushJob($message->id, (int) $message->user_id, $mentions))
            ->handle(app(ChatService::class), $fcm);
    }

    /** @return array<int, int> */
    private function recipients(): array
    {
        return collect($this->sent)->pluck('user')->sort()->values()->all();
    }

    private function sentTo(User $user): ?array
    {
        return collect($this->sent)->firstWhere('user', $user->id);
    }

    // ── การตั้งค่า ────────────────────────────────────────────────────────────

    public function test_member_sets_level_and_it_shows_in_room_and_conversations(): void
    {
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertOk()
            ->assertJsonPath('data.notify_level', 'all');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/notifications", ['level' => 'important'])
            ->assertOk()
            ->assertJsonPath('data.notify_level', 'important');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.notify_level', 'important');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/chat/my-conversations')
            ->assertOk()
            ->assertJsonPath('data.0.notify_level', 'important');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/notifications", ['level' => 'off'])
            ->assertOk();

        $this->assertSame(1, ChatRoomPreference::where('user_id', $user->id)->count());
        $this->assertSame('off', ChatRoomPreference::where('user_id', $user->id)->value('notify_level'));
    }

    public function test_back_to_all_removes_the_row(): void
    {
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule, 'off');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/notifications", ['level' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.notify_level', 'all');

        $this->assertDatabaseCount('chat_room_preferences', 0);
    }

    public function test_level_is_per_room(): void
    {
        $muted = $this->makeSchedule();
        $other = $this->makeSchedule();
        $user = $this->member($muted, 'off');
        $this->member($other);
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $other->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$other->id}/chat/room")
            ->assertJsonPath('data.notify_level', 'all');
    }

    public function test_rejects_unknown_level_and_strangers(): void
    {
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/notifications", ['level' => 'loud'])
            ->assertUnprocessable();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/notifications", ['level' => 'off'])
            ->assertForbidden();

        $this->assertDatabaseCount('chat_room_preferences', 0);
    }

    public function test_sending_a_message_still_queues_the_push_job(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule, 'off');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", ['body' => 'สวัสดี'])
            ->assertCreated();

        Bus::assertDispatched(SendChatPushJob::class);
    }

    // ── ใครได้ push ──────────────────────────────────────────────────────────

    public function test_customer_message_reaches_only_members_who_want_everything(): void
    {
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule);
        $all = $this->member($schedule);
        $important = $this->member($schedule, 'important');
        $off = $this->member($schedule, 'off');
        $staff = $this->staff($schedule);

        $this->push($this->postMessage($schedule, $sender));

        $this->assertSame(collect([$all->id, $staff->id])->sort()->values()->all(), $this->recipients());
        $this->assertNull($this->sentTo($important));
        $this->assertNull($this->sentTo($off));
    }

    public function test_team_message_also_reaches_important_but_never_off(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->staff($schedule);
        $all = $this->member($schedule);
        $important = $this->member($schedule, 'important');
        $off = $this->member($schedule, 'off');

        $this->push($this->postMessage($schedule, $staff, 'staff'));

        $this->assertNotNull($this->sentTo($all));
        $this->assertNotNull($this->sentTo($important));
        $this->assertNull($this->sentTo($off));
    }

    public function test_mention_reaches_important_but_not_off(): void
    {
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule);
        $important = $this->member($schedule, 'important');
        $off = $this->member($schedule, 'off');
        $mentions = [$important->id, $off->id];

        $this->push($this->postMessage($schedule, $sender, 'customer', $mentions), $mentions);

        $this->assertSame([$important->id], $this->recipients());
        $this->assertSame('1', $this->sentTo($important)['data']['mention']);
        $this->assertStringContainsString('กล่าวถึงคุณ', $this->sentTo($important)['body']);
    }

    public function test_blocked_pair_still_gets_nothing(): void
    {
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule);
        $blocker = $this->member($schedule);
        UserBlock::create(['blocker_id' => $blocker->id, 'blocked_id' => $sender->id]);

        $this->push($this->postMessage($schedule, $sender));

        $this->assertNull($this->sentTo($blocker));
    }

    public function test_deleted_or_hidden_message_sends_nothing(): void
    {
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule);
        $this->member($schedule);

        $deleted = $this->postMessage($schedule, $sender);
        $deleted->update(['is_deleted' => true]);
        $this->push($deleted);

        $hidden = $this->postMessage($schedule, $sender);
        ChatMessage::whereKey($hidden->id)->update(['hidden_at' => now()]);
        $this->push($hidden);

        $this->assertSame([], $this->sent);
    }

    public function test_member_who_already_read_it_is_skipped(): void
    {
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule);
        $watching = $this->member($schedule);
        $away = $this->member($schedule);

        $message = $this->postMessage($schedule, $sender);
        ChatRead::create([
            'schedule_id' => $schedule->id,
            'user_id' => $watching->id,
            'last_read_message_id' => $message->id,
        ]);

        $this->push($message);

        $this->assertSame([$away->id], $this->recipients());
    }

    // ── รวมแจ้งเตือน ─────────────────────────────────────────────────────────

    public function test_burst_rings_once_then_updates_the_same_notification_quietly(): void
    {
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule);
        $reader = $this->member($schedule);
        $this->caughtUp($schedule, $reader);

        $this->push($this->postMessage($schedule, $sender));
        $this->push($this->postMessage($schedule, $sender));
        $this->push($this->postMessage($schedule, $sender));

        $toReader = collect($this->sent)->where('user', $reader->id)->values();
        $this->assertCount(3, $toReader);

        $this->assertFalse($toReader[0]['display']['quiet']);
        $this->assertSame('chat_messages', $toReader[0]['display']['android_channel']);
        $this->assertSame('💬 ภูกระดึง', $toReader[0]['title']);

        $this->assertTrue($toReader[1]['display']['quiet']);
        $this->assertSame('chat_quiet', $toReader[1]['display']['android_channel']);
        $this->assertSame('1', $toReader[1]['data']['quiet']);
        $this->assertSame('💬 ภูกระดึง · 2 ข้อความใหม่', $toReader[1]['title']);
        $this->assertSame('💬 ภูกระดึง · 3 ข้อความใหม่', $toReader[2]['title']);

        // ทุกใบของห้องเดียวกันใช้ tag เดียวกัน จึงทับกันในถาด
        $tag = "chat-{$schedule->id}";
        $this->assertSame([$tag], $toReader->pluck('display.tag')->unique()->values()->all());
        $this->assertSame($tag, $toReader[0]['data']['tag']);
    }

    public function test_rings_again_after_the_cooldown(): void
    {
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule);
        $reader = $this->member($schedule);

        $this->push($this->postMessage($schedule, $sender));
        Cache::forget(SendChatPushJob::alertCacheKey($schedule->id, $reader->id));
        $this->push($this->postMessage($schedule, $sender));

        $this->assertFalse($this->sent[1]['display']['quiet']);
    }

    public function test_cooldown_is_per_room(): void
    {
        $first = $this->makeSchedule();
        $second = $this->makeSchedule();
        $sender = $this->member($first);
        $reader = $this->member($first);
        foreach ([$sender, $reader] as $u) {
            Booking::create([
                'booking_ref' => Booking::generateRef(),
                'user_id' => $u->id,
                'schedule_id' => $second->id,
                'qr_code' => Booking::generateQrCode(),
                'status' => 'confirmed',
                'total_amount' => 1500,
            ]);
        }

        $this->push($this->postMessage($first, $sender));
        $this->push($this->postMessage($second, $sender));

        $this->assertFalse($this->sent[0]['display']['quiet']);
        $this->assertFalse($this->sent[1]['display']['quiet']);
    }

    public function test_mention_always_rings_and_keeps_its_own_tag(): void
    {
        $schedule = $this->makeSchedule();
        $sender = $this->member($schedule);
        $reader = $this->member($schedule);

        $this->push($this->postMessage($schedule, $sender));
        $this->push($this->postMessage($schedule, $sender, 'customer', [$reader->id]), [$reader->id]);

        $mention = $this->sent[1];
        $this->assertFalse($mention['display']['quiet']);
        $this->assertSame("chat-{$schedule->id}-mention", $mention['display']['tag']);
        $this->assertSame("chat-{$schedule->id}", $mention['display']['thread']);
        $this->assertSame('📣 ภูกระดึง', $mention['title']);

        // การแท็กไม่กินโควตาเสียงของห้อง และไม่ถูกนับเป็นเหตุให้ใบถัดไปเงียบเพิ่ม
        $this->push($this->postMessage($schedule, $sender));
        $this->assertTrue($this->sent[2]['display']['quiet']);
    }

    // ── payload ที่ออกไป FCM จริง ────────────────────────────────────────────

    public function test_fcm_payload_collapses_and_quiets(): void
    {
        config(['services.fcm.project_id' => 'demo-project']);
        Cache::put('fcm_access_token', 'test-token', 60);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $user = User::factory()->create();
        FcmToken::create(['user_id' => $user->id, 'token' => 'device-1', 'platform' => 'android', 'is_active' => true]);

        app(FcmService::class)->sendToUser($user->id, 't', 'b', ['type' => 'chat_message'], [
            'tag' => 'chat-9',
            'thread' => 'chat-9',
            'quiet' => true,
            'android_channel' => 'chat_quiet',
        ]);
        app(FcmService::class)->sendToUser($user->id, 't', 'b', ['type' => 'chat_message'], [
            'tag' => 'chat-9',
            'quiet' => false,
            'android_channel' => 'chat_messages',
        ]);

        $requests = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(2, $requests);

        /** @var Request $quiet */
        $quiet = $requests[0];
        $message = $quiet->data()['message'];
        $this->assertSame('chat-9', $message['android']['notification']['tag']);
        $this->assertSame('chat_quiet', $message['android']['notification']['channel_id']);
        $this->assertArrayNotHasKey('sound', $message['android']['notification']);
        $this->assertSame('chat-9', $message['apns']['headers']['apns-collapse-id']);
        $this->assertSame('passive', $message['apns']['payload']['aps']['interruption-level']);
        $this->assertSame('chat-9', $message['apns']['payload']['aps']['thread-id']);
        $this->assertArrayNotHasKey('sound', $message['apns']['payload']['aps']);

        $loud = $requests[1]->data()['message'];
        $this->assertSame('chat_messages', $loud['android']['notification']['channel_id']);
        $this->assertSame('default', $loud['android']['notification']['sound']);
        $this->assertSame('active', $loud['apns']['payload']['aps']['interruption-level']);
        $this->assertSame('default', $loud['apns']['payload']['aps']['sound']);
    }

    public function test_fcm_payload_without_display_is_unchanged(): void
    {
        config(['services.fcm.project_id' => 'demo-project']);
        Cache::put('fcm_access_token', 'test-token', 60);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        $user = User::factory()->create();
        FcmToken::create(['user_id' => $user->id, 'token' => 'device-1', 'platform' => 'ios', 'is_active' => true]);

        app(FcmService::class)->sendToUser($user->id, 't', 'b', ['type' => 'payment']);

        $message = Http::recorded()->first()[0]->data()['message'];
        $this->assertSame('important_updates', $message['android']['notification']['channel_id']);
        $this->assertSame('PRIORITY_HIGH', $message['android']['notification']['notification_priority']);
        $this->assertArrayNotHasKey('tag', $message['android']['notification']);
        $this->assertSame(['apns-priority' => '10', 'apns-push-type' => 'alert'], $message['apns']['headers']);
        $this->assertSame('default', $message['apns']['payload']['aps']['sound']);
        $this->assertArrayNotHasKey('thread-id', $message['apns']['payload']['aps']);
    }

    // ── ล้างห้อง ─────────────────────────────────────────────────────────────

    public function test_purging_an_ended_room_drops_its_preferences(): void
    {
        $schedule = $this->makeSchedule(now()->subDays(10)->toDateString());
        $user = $this->member($schedule, 'off');
        $this->postMessage($schedule, $user);

        $keep = $this->makeSchedule();
        $this->member($keep, 'important');

        (new PurgeEndedTripChatsJob)->handle();

        $this->assertSame(0, ChatRoomPreference::where('schedule_id', $schedule->id)->count());
        $this->assertSame(1, ChatRoomPreference::where('schedule_id', $keep->id)->count());
    }
}
