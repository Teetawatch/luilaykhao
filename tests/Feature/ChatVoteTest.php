<?php

namespace Tests\Feature;

use App\Jobs\SendChatPushJob;
use App\Jobs\SettleChatPollsJob;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * โหวตตัดสิน (chat_polls.kind = vote) — เสียงข้างมาก มีเวลาจำกัด ปิดแล้วประกาศผลเข้าห้อง
 */
class ChatVoteTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'โหวตทริป',
            'slug' => 'vote-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'น่าน',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1900,
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

    private function member(TripSchedule $schedule): User
    {
        $user = User::factory()->create();
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1900,
        ]);

        return $user;
    }

    private function staff(TripSchedule $schedule): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function startVote(User $user, TripSchedule $schedule, array $payload = []): array
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls", array_merge([
                'kind' => 'vote',
                'question' => 'แวะคาเฟ่ก่อนกลับไหม',
            ], $payload))
            ->assertCreated()
            ->json('data');
    }

    private function vote(User $user, TripSchedule $schedule, array $poll, int $optionIndex): void
    {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll['id']}/vote", [
                'option_ids' => [$poll['options'][$optionIndex]['id']],
            ])
            ->assertOk();
    }

    private function settle(): void
    {
        app()->call([new SettleChatPollsJob, 'handle']);
    }

    private function resultMessages(TripSchedule $schedule)
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->where('system_key', 'like', 'vote_result:%')
            ->get();
    }

    public function test_vote_without_options_asks_agree_or_disagree_and_closes_in_ten_minutes(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $data = $this->startVote($user, $schedule, ['allow_multiple' => true]);

        $this->assertSame('🗳️ โหวต: แวะคาเฟ่ก่อนกลับไหม', $data['body']);
        $this->assertSame('vote', $data['poll']['kind']);
        $this->assertFalse($data['poll']['allow_multiple'], 'โหวตต้องหนึ่งคนหนึ่งเสียงเสมอ');
        $this->assertSame(ChatPoll::VOTE_DEFAULT_OPTIONS, array_column($data['poll']['options'], 'label'));
        $this->assertNull($data['poll']['result']);

        $closesAt = ChatPoll::find($data['poll']['id'])->closes_at;
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $closesAt->timestamp, 5);

        // เส้นตายสั้น → เด้งแบบมีเสียงถึงคนที่ตั้งไว้ "เฉพาะสำคัญ" ด้วย
        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->callToAction === true);
    }

    public function test_vote_accepts_custom_options_and_minutes(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $data = $this->startVote($user, $schedule, [
            'question' => 'มื้อเย็นกินอะไร',
            'options' => ['หมูกระทะ', 'ส้มตำ', 'ตามสั่ง'],
            'duration_minutes' => 5,
        ]);

        $this->assertCount(3, $data['poll']['options']);
        $this->assertEqualsWithDelta(
            now()->addMinutes(5)->timestamp,
            ChatPoll::find($data['poll']['id'])->closes_at->timestamp,
            5,
        );
    }

    public function test_plain_poll_still_needs_its_own_options(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls", [
                'question' => 'แวะไหนดี',
            ])
            ->assertStatus(422);
    }

    public function test_vote_closes_and_announces_once_every_traveller_has_voted(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $a = $this->member($schedule);
        $b = $this->member($schedule);
        $c = $this->member($schedule);
        $this->staff($schedule); // ไม่ต้องรอสตาฟโหวต

        $poll = $this->startVote($a, $schedule)['poll'];

        $this->vote($a, $schedule, $poll, 0);
        $this->vote($b, $schedule, $poll, 0);
        $this->assertFalse(ChatPoll::find($poll['id'])->isClosed());
        $this->assertCount(0, $this->resultMessages($schedule));

        $this->vote($c, $schedule, $poll, 1);

        $closed = ChatPoll::find($poll['id']);
        $this->assertTrue($closed->isClosed());
        $this->assertNotNull($closed->announced_at);

        $messages = $this->resultMessages($schedule);
        $this->assertCount(1, $messages);
        $this->assertSame('system', $messages->first()->sender_role);
        $this->assertSame(
            '🗳️ ผลโหวต “แวะคาเฟ่ก่อนกลับไหม” — “👍 เห็นด้วย” ชนะ 2 ต่อ 1 เสียง 🎉',
            $messages->first()->body,
        );

        // การ์ดโชว์ผลตรงกับข้อความประกาศ
        $card = $this->actingAs($a, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->json('data.messages');
        $voteCard = collect($card)->firstWhere('poll.id', $poll['id']);
        $this->assertSame('winner', $voteCard['poll']['result']['status']);
        $this->assertSame('👍 เห็นด้วย', $voteCard['poll']['result']['winner_label']);

        // ปิดแล้วโหวตต่อไม่ได้
        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll['id']}/vote", [
                'option_ids' => [$poll['options'][1]['id']],
            ])
            ->assertStatus(422);
    }

    public function test_manual_close_announces_a_tie_for_staff_to_decide(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $a = $this->member($schedule);
        $b = $this->member($schedule);
        $this->member($schedule); // ยังไม่โหวต → ไม่ปิดเอง

        $poll = $this->startVote($a, $schedule)['poll'];
        $this->vote($a, $schedule, $poll, 0);
        $this->vote($b, $schedule, $poll, 1);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll['id']}/close")
            ->assertOk()
            ->assertJsonPath('data.poll.result.status', 'tie');

        // ปิดซ้ำไม่ประกาศซ้ำ
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll['id']}/close")
            ->assertOk();

        $messages = $this->resultMessages($schedule);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('เสมอกัน', $messages->first()->body);
        $this->assertStringContainsString('ทีมงานช่วยตัดสิน', $messages->first()->body);
    }

    public function test_expired_vote_is_announced_by_the_settle_job_once(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $a = $this->member($schedule);
        $this->member($schedule);

        $poll = $this->startVote($a, $schedule, ['options' => ['ไป', 'ไม่ไป', 'แล้วแต่']])['poll'];
        $this->vote($a, $schedule, $poll, 0);

        // ยังไม่หมดเวลา → job ไม่แตะ
        $this->settle();
        $this->assertCount(0, $this->resultMessages($schedule));

        $this->travel(11)->minutes();

        $this->settle();
        $this->settle();

        $messages = $this->resultMessages($schedule);
        $this->assertCount(1, $messages);
        $this->assertSame('🗳️ ผลโหวต “แวะคาเฟ่ก่อนกลับไหม” — “ไป” ชนะ ด้วย 1 จาก 1 เสียง 🎉', $messages->first()->body);
    }

    public function test_expired_vote_with_no_votes_says_so(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $a = $this->member($schedule);

        $this->startVote($a, $schedule, ['duration_minutes' => 5]);
        $this->travel(6)->minutes();
        $this->settle();

        $this->assertStringContainsString('ยังไม่มีใครโหวต', $this->resultMessages($schedule)->first()->body);
    }

    public function test_plain_polls_are_never_announced(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $a = $this->member($schedule);

        $poll = $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls", [
                'question' => 'แวะไหนดี',
                'options' => ['ก', 'ข'],
                'duration_hours' => 1,
            ])
            ->assertCreated()
            ->json('data.poll');

        $this->vote($a, $schedule, $poll, 0);
        $this->assertFalse(ChatPoll::find($poll['id'])->isClosed(), 'โพลทั่วไปไม่ปิดเองเมื่อโหวตครบ');

        $this->travel(2)->hours();
        $this->settle();

        $this->assertCount(0, $this->resultMessages($schedule));
        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->callToAction === false);
    }
}
