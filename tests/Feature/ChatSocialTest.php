<?php

namespace Tests\Feature;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Models\ChatMemberIntro;
use App\Models\ChatMessage;
use App\Models\ChatMessageAck;
use App\Models\TripContactExchange;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\ChatAckService;
use App\Services\ChatBirthdayService;
use App\Services\ChatIntroService;
use App\Services\ChatService;
use App\Services\TripChatTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

class ChatSocialTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
    }

    // ── แนะนำตัว ──────────────────────────────────────────────────────────

    public function test_intro_posts_once_then_edits_the_same_message(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['เอ']);
        $owner->update(['nickname' => 'เอ']);

        $res = $this->actingAs($owner, 'sanctum')
            ->putJson($this->chatUrl($schedule, 'intro'), ['hometown' => 'เชียงใหม่', 'experience' => 'first'])
            ->assertOk();
        $messageId = $res->json('data.message_id');

        $message = ChatMessage::findOrFail($messageId);
        $this->assertSame($owner->id, $message->user_id);
        $this->assertStringContainsString('มาจาก เชียงใหม่', $message->body);

        $this->actingAs($owner, 'sanctum')
            ->putJson($this->chatUrl($schedule, 'intro'), ['hometown' => 'ลำปาง', 'note' => 'ชอบถ่ายรูป'])
            ->assertOk()
            ->assertJsonPath('data.message_id', $messageId);

        $this->assertSame(1, ChatMemberIntro::count());
        $presented = collect($this->actingAs($owner, 'sanctum')->getJson($this->chatUrl($schedule, 'messages'))->json('data.messages'))
            ->firstWhere('id', $messageId);
        $this->assertSame('ลำปาง', $presented['intro']['hometown']);
        $this->assertNull($presented['intro']['experience']);
        $this->assertSame('ชอบถ่ายรูป', $presented['intro']['note']);
    }

    public function test_intro_needs_something_and_reposts_after_delete(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['เอ']);

        $this->actingAs($owner, 'sanctum')
            ->putJson($this->chatUrl($schedule, 'intro'), ['hometown' => '  '])
            ->assertStatus(422);

        $first = $this->actingAs($owner, 'sanctum')
            ->putJson($this->chatUrl($schedule, 'intro'), ['experience' => 'lots'])
            ->json('data.message_id');
        app(ChatService::class)->deleteMessage(ChatMessage::find($first));

        $second = $this->actingAs($owner, 'sanctum')
            ->putJson($this->chatUrl($schedule, 'intro'), ['experience' => 'some'])
            ->json('data.message_id');
        $this->assertNotSame($first, $second);
    }

    public function test_timeline_invites_intros_three_days_before_with_a_cta(): void
    {
        $departure = now('Asia/Bangkok')->addDays(3)->toDateString();
        $schedule = $this->tripSchedule(['departure_date' => $departure, 'return_date' => $departure]);
        [$a] = $this->party($schedule, ['เอ']);
        $this->party($schedule, ['บี']);

        $at = CarbonImmutable::parse(now('Asia/Bangkok')->toDateString().' 12:30', 'Asia/Bangkok');
        app(TripChatTimelineService::class)->syncFor($schedule, $at);

        $intro = ChatMessage::where('schedule_id', $schedule->id)->where('system_key', 'intro_3d')->first();
        $this->assertNotNull($intro);

        $presented = collect($this->actingAs($a, 'sanctum')->getJson($this->chatUrl($schedule, 'messages'))->json('data.messages'))
            ->firstWhere('id', $intro->id);
        $this->assertSame('intro', $presented['cta']);
    }

    // ── วันเกิด ───────────────────────────────────────────────────────────

    public function test_birthday_in_trip_tells_staff_once_without_age_and_staff_greets(): void
    {
        $day = now('Asia/Bangkok')->addDay();
        $schedule = $this->tripSchedule([
            'departure_date' => $day->toDateString(),
            'return_date' => $day->copy()->addDay()->toDateString(),
        ]);
        $staff = $this->staffOf($schedule);
        [$owner, , [$p1, $p2]] = $this->party($schedule, ['เอ', 'บี']);
        $p1->update(['birth_date' => $day->copy()->addDay()->subYears(30)->toDateString()]);
        $p2->update(['birth_date' => $day->copy()->addDays(5)->subYears(30)->toDateString()]);

        $this->assertSame(1, app(ChatBirthdayService::class)->notifyStaffDue());
        $this->assertSame(0, app(ChatBirthdayService::class)->notifyStaffDue());
        $this->assertCount(1, $this->pushesTo($staff));
        $this->assertStringContainsString('เอ', $this->pushesTo($staff)[0]['body']);
        // อายุ 30 ห้ามโผล่ — แต่วันเกิดเองอาจเป็นวันที่ 30 ("30 ต.ค.") จึงเช็กคำบอกอายุแทนเลขเปล่า
        $this->assertStringNotContainsString('30 ปี', $this->pushesTo($staff)[0]['body']);
        $this->assertStringNotContainsString('อายุ', $this->pushesTo($staff)[0]['body']);
        $this->assertCount(0, $this->pushesTo($owner));

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'birthdays'))
            ->assertForbidden();
        $this->actingAs($staff, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'room'))
            ->assertJsonPath('data.birthdays_pending', 1);

        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "birthdays/{$p1->id}/greet"))
            ->assertCreated()
            ->assertJsonPath('data.items.0.greeted', true);
        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "birthdays/{$p1->id}/greet"))
            ->assertStatus(422);
        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "birthdays/{$p2->id}/greet"))
            ->assertStatus(422);

        $body = ChatMessage::where('system_key', "birthday:{$p1->id}")->value('body');
        $this->assertStringContainsString('วันเกิดของ เอ', $body);
        $this->assertStringNotContainsString('30 ปี', $body);
        $this->assertStringNotContainsString('อายุ', $body);
    }

    public function test_leap_day_birthday_is_celebrated_on_feb_28(): void
    {
        $schedule = $this->tripSchedule(['departure_date' => '2027-02-28', 'return_date' => '2027-02-28']);
        [, , [$p]] = $this->party($schedule, ['เอ']);
        $p->update(['birth_date' => '2000-02-29']);

        $this->assertSame('2027-02-28', app(ChatBirthdayService::class)->forSchedule($schedule)->first()['date']);
    }

    // ── แลกคอนแทค ────────────────────────────────────────────────────────

    public function test_contacts_are_hidden_until_both_agree(): void
    {
        $schedule = $this->tripSchedule();
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);

        // ต้องมีคอนแทคของตัวเองก่อนขอ
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$b->id}/request")
            ->assertStatus(422);

        $this->actingAs($a, 'sanctum')
            ->putJson('/api/v1/me/social-handles', ['instagram' => 'https://instagram.com/@a.hiker/', 'line' => ' aline '])
            ->assertOk()
            ->assertJsonPath('data.handles.instagram', 'a.hiker')
            ->assertJsonPath('data.handles.line', 'aline');
        $b->update(['social_handles' => ['line' => 'bline']]);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$b->id}/request")
            ->assertOk()
            ->assertJsonPath('data.status', 'requested');
        $this->assertCount(1, $this->pushesTo($b));

        $board = $this->actingAs($b, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/contacts")->assertOk()->json('data');
        $row = collect($board['people'])->firstWhere('user_id', $a->id);
        $this->assertSame('incoming', $row['status']);
        $this->assertNull($row['handles']);

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$a->id}/respond", ['accept' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'exchanged');

        $row = collect($this->actingAs($a, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/contacts")->json('data.people'))
            ->firstWhere('user_id', $b->id);
        $this->assertSame(['line' => 'bline'], $row['handles']);

        // อยู่ต่อในโปรไฟล์แม้ห้องแชทถูกลบ
        $this->actingAs($a, 'sanctum')
            ->getJson('/api/v1/me/trip-contacts')
            ->assertJsonPath('data.items.0.handles.line', 'bline');

        // user toArray ไม่ปล่อยคอนแทคออกไปทางอื่น
        $this->assertArrayNotHasKey('social_handles', $a->fresh()->toArray());
    }

    public function test_mutual_request_auto_accepts_and_decline_stays_quiet(): void
    {
        $schedule = $this->tripSchedule();
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);
        [$c] = $this->party($schedule, ['ซี']);
        foreach ([$a, $b, $c] as $u) {
            $u->update(['social_handles' => ['line' => 'x'.$u->id]]);
        }

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$b->id}/request");
        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$a->id}/request")
            ->assertJsonPath('data.status', 'exchanged');

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$c->id}/request");
        $this->actingAs($c, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$a->id}/respond", ['accept' => false])
            ->assertJsonPath('data.status', 'none');

        $row = collect($this->actingAs($a, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/contacts")->json('data.people'))
            ->firstWhere('user_id', $c->id);
        $this->assertSame('requested', $row['status']);
        $this->assertSame(TripContactExchange::DECLINED, TripContactExchange::where('target_id', $c->id)->value('status'));
    }

    public function test_contacts_only_between_travellers_in_the_window_and_not_blocked(): void
    {
        $schedule = $this->tripSchedule();
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);
        $a->update(['social_handles' => ['line' => 'a']]);
        $stranger = User::factory()->create();

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$stranger->id}/request")
            ->assertStatus(422);
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/contacts")
            ->assertForbidden();

        UserBlock::create(['blocker_id' => $b->id, 'blocked_id' => $a->id]);
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/contacts/{$b->id}/request")
            ->assertStatus(422);
        $people = $this->actingAs($a, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/contacts")->json('data.people');
        $this->assertNotContains($b->id, array_column($people, 'user_id'));

        $future = $this->tripSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(11)->toDateString(),
        ]);
        [$x] = $this->party($future, ['เอ็กซ์']);
        [$y] = $this->party($future, ['วาย']);
        $x->update(['social_handles' => ['line' => 'x']]);
        $this->actingAs($x, 'sanctum')
            ->postJson("/api/v1/schedules/{$future->id}/contacts/{$y->id}/request")
            ->assertStatus(422);
    }

    public function test_purging_the_room_drops_intros_and_acks_but_keeps_exchanged_contacts(): void
    {
        $end = now('Asia/Bangkok')->subDays(PurgeEndedTripChatsJob::DELETE_AFTER_DAYS + 2)->toDateString();
        $schedule = $this->tripSchedule(['departure_date' => $end, 'return_date' => $end]);
        $staff = $this->staffOf($schedule);
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);

        app(ChatIntroService::class)->upsert($a, $schedule, ['hometown' => 'น่าน']);
        $notice = app(ChatService::class)->postSystem($schedule, 'ประกาศ');
        app(ChatAckService::class)->request($staff, $notice, notify: false);
        app(ChatAckService::class)->ack($a, $notice->fresh());
        TripContactExchange::create([
            'schedule_id' => $schedule->id, 'requester_id' => $a->id, 'target_id' => $b->id,
            'status' => TripContactExchange::ACCEPTED, 'responded_at' => now(),
        ]);

        app()->call([new PurgeEndedTripChatsJob, 'handle']);

        $this->assertSame(0, ChatMemberIntro::count());
        $this->assertSame(0, ChatMessageAck::count());
        $this->assertSame(0, ChatMessage::where('schedule_id', $schedule->id)->count());
        $this->assertSame(1, TripContactExchange::count());
    }
}
