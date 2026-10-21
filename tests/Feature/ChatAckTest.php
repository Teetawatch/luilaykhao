<?php

namespace Tests\Feature;

use App\Jobs\SendChatAckPushJob;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessageAck;
use App\Models\ChatRoomPreference;
use App\Services\ChatAckService;
use App\Services\ChatService;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

class ChatAckTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
    }

    public function test_staff_sends_an_announcement_that_needs_acknowledgement(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        $this->party($schedule, ['เอ']);
        $this->party($schedule, ['บี']);

        $res = $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, 'messages'), [
                'body' => 'พรุ่งนี้ตีสี่ครึ่งเจอกันที่จุดรับนะครับ',
                'request_ack' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.ack.count', 0)
            ->assertJsonPath('data.ack.total', 2);

        // เด้งแบบมีเสียงถึงทุกคน เหมือนโหวต (callToAction)
        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->messageId === $res->json('data.id') && $job->callToAction);
    }

    public function test_customers_cannot_request_acknowledgement(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['เอ']);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->chatUrl($schedule, 'messages'), ['body' => 'ทุกคนกดรับทราบ', 'request_ack' => true])
            ->assertForbidden();

        $message = app(ChatService::class)->postSystem($schedule, 'สรุปก่อนเดินทาง');
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/ack-request"))
            ->assertForbidden();
    }

    public function test_ack_on_an_existing_system_message_and_counts(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);
        $message = app(ChatService::class)->postSystem($schedule, "🚐 พรุ่งนี้ออกเดินทาง\nพกบัตรประชาชนด้วยนะครับ");

        Bus::fake([SendChatAckPushJob::class]);
        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/ack-request"))
            ->assertOk()
            ->assertJsonPath('data.ack.total', 2);
        Bus::assertDispatched(SendChatAckPushJob::class, fn ($job) => ! $job->nudge);

        $this->actingAs($a, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/ack"))
            ->assertOk()
            ->assertJsonPath('data.ack.count', 1)
            ->assertJsonPath('data.ack.acked_by_me', true);

        // กดซ้ำไม่นับเพิ่ม
        $this->actingAs($a, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/ack"))
            ->assertJsonPath('data.ack.count', 1);

        // อีกคนเห็นว่าตัวเองยังไม่กด
        $messages = $this->actingAs($b, 'sanctum')->getJson($this->chatUrl($schedule, 'messages'))->json('data.messages');
        $seen = collect($messages)->firstWhere('id', $message->id);
        $this->assertSame(1, $seen['ack']['count']);
        $this->assertFalse($seen['ack']['acked_by_me']);

        // ทีมงานไม่ต้องกด
        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/ack"))
            ->assertStatus(422);
    }

    public function test_staff_sees_who_has_not_acked_and_nudges_them_once_per_cooldown(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);
        $b->update(['nickname' => 'บีบี']);
        $message = app(ChatService::class)->postSystem($schedule, 'นัดรวมตัว 04:30');
        $acks = app(ChatAckService::class);
        $acks->request($staff, $message, notify: false);
        $acks->ack($a, $message->fresh());

        $detail = $this->actingAs($staff, 'sanctum')
            ->getJson($this->chatUrl($schedule, "messages/{$message->id}/acks"))
            ->assertOk()
            ->json('data');
        $this->assertSame([$a->id], array_column($detail['acked'], 'id'));
        $this->assertSame(['บีบี'], array_column($detail['pending'], 'name'));

        $this->actingAs($a, 'sanctum')
            ->getJson($this->chatUrl($schedule, "messages/{$message->id}/acks"))
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/acks/nudge"))
            ->assertOk()
            ->assertJsonPath('data.nudged', 1);

        // push ถึงเฉพาะคนที่ยังไม่กด
        $this->assertCount(1, $this->pushesTo($b));
        $this->assertStringContainsString('รอคุณกดรับทราบ', $this->pushesTo($b)[0]['body']);
        $this->assertCount(0, $this->pushesTo($a));

        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/acks/nudge"))
            ->assertStatus(422);

        $this->travel(ChatAckService::NUDGE_COOLDOWN_MINUTES + 1)->minutes();
        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/acks/nudge"))
            ->assertOk();
    }

    public function test_ack_push_respects_muted_rooms_and_skips_people_who_acked(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);
        [$c] = $this->party($schedule, ['ซี']);
        $message = app(ChatService::class)->postSystem($schedule, 'ประกาศสำคัญ');
        $acks = app(ChatAckService::class);
        $acks->request($staff, $message, notify: false);
        $acks->ack($a, $message->fresh());
        app(ChatService::class)->setNotifyLevel($b, $schedule, ChatRoomPreference::LEVEL_OFF);
        app(ChatService::class)->setNotifyLevel($c, $schedule, ChatRoomPreference::LEVEL_IMPORTANT);

        (new SendChatAckPushJob($message->id, false))->handle($acks, app(ChatService::class), app(FcmService::class));

        $this->assertCount(0, $this->pushesTo($a));
        $this->assertCount(0, $this->pushesTo($b));
        $this->assertCount(1, $this->pushesTo($c));
        $this->assertStringContainsString('ขอให้กดรับทราบ: ประกาศสำคัญ', $this->pushesTo($c)[0]['body']);
    }

    public function test_cancel_clears_acks_and_deleted_messages_drop_the_counter(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$a] = $this->party($schedule, ['เอ']);
        $message = app(ChatService::class)->postSystem($schedule, 'ประกาศ');
        $acks = app(ChatAckService::class);
        $acks->request($staff, $message, notify: false);
        $acks->ack($a, $message->fresh());

        $this->actingAs($staff, 'sanctum')
            ->deleteJson($this->chatUrl($schedule, "messages/{$message->id}/ack-request"))
            ->assertOk();
        $this->assertSame(0, ChatMessageAck::count());
        $this->assertNull($message->fresh()->ack_requested_at);

        $this->actingAs($a, 'sanctum')
            ->postJson($this->chatUrl($schedule, "messages/{$message->id}/ack"))
            ->assertStatus(422);

        $acks->request($staff, $message->fresh(), notify: false);
        app(ChatService::class)->deleteMessage($message->fresh());
        $this->assertNull($acks->present($message->fresh(), $a->id));
        $this->assertTrue($acks->pendingFor($a, $schedule)->isEmpty());
    }

    public function test_pending_for_lists_only_my_unacked_announcements(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$a] = $this->party($schedule, ['เอ']);
        $acks = app(ChatAckService::class);
        $one = app(ChatService::class)->postSystem($schedule, 'หนึ่ง');
        $two = app(ChatService::class)->postSystem($schedule, 'สอง');
        app(ChatService::class)->postSystem($schedule, 'ไม่ได้ขอ');
        $acks->request($staff, $one, notify: false);
        $acks->request($staff, $two, notify: false);
        $acks->ack($a, $one->fresh());

        $this->assertSame([$two->id], $acks->pendingFor($a, $schedule)->pluck('id')->all());
        $this->assertTrue($acks->pendingFor($staff, $schedule)->isEmpty());
    }
}
