<?php

namespace Tests\Feature;

use App\Events\ChatPollUpdated;
use App\Models\Booking;
use App\Models\ChatPoll;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatPollTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'โพลทริป',
            'slug' => 'poll-trip-'.uniqid(),
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

    private function createPoll(User $user, TripSchedule $schedule, array $payload = []): array
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls", array_merge([
                'question' => 'แวะกินข้าวเย็นที่ไหนดี',
                'options' => ['ร้านลาบริมทาง', 'ปั๊มน้ำมัน', 'ตลาดในเมือง'],
            ], $payload))
            ->assertCreated()
            ->json('data');
    }

    public function test_member_can_create_a_poll_that_appears_as_a_chat_message(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $data = $this->createPoll($user, $schedule);

        $this->assertSame('📊 แวะกินข้าวเย็นที่ไหนดี', $data['body']);
        $this->assertSame('แวะกินข้าวเย็นที่ไหนดี', $data['poll']['question']);
        $this->assertCount(3, $data['poll']['options']);
        $this->assertFalse($data['poll']['allow_multiple']);
        $this->assertFalse($data['poll']['is_closed']);
        $this->assertSame(0, $data['poll']['voter_count']);

        // โผล่ในรายการข้อความปกติพร้อมข้อมูลโพล
        $messages = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertOk()
            ->json('data.messages');

        $pollMessage = collect($messages)->firstWhere('id', $data['id']);
        $this->assertSame(3, count($pollMessage['poll']['options']));
    }

    public function test_poll_requires_at_least_two_distinct_options(): void
    {
        $schedule = $this->makeSchedule();
        $user = $this->member($schedule);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls", [
                'question' => 'เอาไงดี',
                'options' => ['ข้อเดียว'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('options');

        // ส่งมา 2 ข้อแต่ซ้ำกัน — ผ่าน validation แต่เหลือตัวเลือกเดียว
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls", [
                'question' => 'เอาไงดี',
                'options' => ['เหมือนกัน', 'เหมือนกัน'],
            ])
            ->assertStatus(422);
    }

    public function test_single_choice_vote_replaces_the_previous_one(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $owner = $this->member($schedule);
        $voter = $this->member($schedule);

        $data = $this->createPoll($owner, $schedule);
        $poll = ChatPoll::firstOrFail();
        $first = $data['poll']['options'][0]['id'];
        $second = $data['poll']['options'][1]['id'];

        $this->actingAs($voter, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => [$first],
            ])
            ->assertOk()
            ->assertJsonPath('data.poll.options.0.vote_count', 1)
            ->assertJsonPath('data.poll.options.0.voted_by_me', true);

        $result = $this->actingAs($voter, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => [$second],
            ])
            ->assertOk()
            ->json('data.poll');

        $this->assertSame(0, $result['options'][0]['vote_count']);
        $this->assertSame(1, $result['options'][1]['vote_count']);
        $this->assertSame([$second], $result['my_option_ids']);
        $this->assertSame(1, $result['voter_count']);
    }

    public function test_multiple_choice_poll_keeps_every_selected_option(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $owner = $this->member($schedule);

        $data = $this->createPoll($owner, $schedule, ['allow_multiple' => true]);
        $poll = ChatPoll::firstOrFail();
        $ids = [$data['poll']['options'][0]['id'], $data['poll']['options'][2]['id']];

        $result = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => $ids,
            ])
            ->assertOk()
            ->json('data.poll');

        $this->assertSame($ids, $result['my_option_ids']);
        $this->assertSame(1, $result['voter_count']);   // คนเดียว 2 คะแนน = ผู้โหวต 1 คน
    }

    public function test_empty_vote_withdraws_the_users_votes(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $owner = $this->member($schedule);

        $data = $this->createPoll($owner, $schedule);
        $poll = ChatPoll::firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => [$data['poll']['options'][0]['id']],
            ])->assertOk();

        $result = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => [],
            ])
            ->assertOk()
            ->json('data.poll');

        $this->assertSame([], $result['my_option_ids']);
        $this->assertSame(0, $result['voter_count']);
    }

    public function test_voting_broadcasts_the_updated_poll(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $owner = $this->member($schedule);
        $data = $this->createPoll($owner, $schedule);
        $poll = ChatPoll::firstOrFail();

        Event::fake([ChatPollUpdated::class]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => [$data['poll']['options'][0]['id']],
            ])->assertOk();

        Event::assertDispatched(ChatPollUpdated::class, function (ChatPollUpdated $e) use ($schedule, $data) {
            return $e->scheduleId === $schedule->id
                && $e->messageId === $data['id']
                && $e->poll['options'][0]['vote_count'] === 1;
        });
    }

    public function test_closed_poll_rejects_new_votes(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $owner = $this->member($schedule);
        $other = $this->member($schedule);
        $data = $this->createPoll($owner, $schedule);
        $poll = ChatPoll::firstOrFail();

        // คนอื่นที่ไม่ใช่ผู้สร้าง/ทีมงาน ปิดโพลไม่ได้
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/close")
            ->assertStatus(403);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/close")
            ->assertOk()
            ->assertJsonPath('data.poll.is_closed', true);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => [$data['poll']['options'][0]['id']],
            ])
            ->assertStatus(422);
    }

    public function test_poll_closes_itself_once_the_duration_passes(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $owner = $this->member($schedule);
        $data = $this->createPoll($owner, $schedule, ['duration_hours' => 3]);
        $poll = ChatPoll::firstOrFail();

        $this->assertNotNull($data['poll']['closes_at']);
        $this->assertFalse($data['poll']['is_closed']);

        $this->travel(4)->hours();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => [$data['poll']['options'][0]['id']],
            ])
            ->assertStatus(422);
    }

    public function test_non_member_cannot_create_or_vote(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $owner = $this->member($schedule);
        $data = $this->createPoll($owner, $schedule);
        $poll = ChatPoll::firstOrFail();
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls", [
                'question' => 'แอบถาม',
                'options' => ['ก', 'ข'],
            ])
            ->assertStatus(403);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$poll->id}/vote", [
                'option_ids' => [$data['poll']['options'][0]['id']],
            ])
            ->assertStatus(403);
    }

    public function test_vote_ignores_options_from_another_poll(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $owner = $this->member($schedule);

        $first = $this->createPoll($owner, $schedule);
        $second = $this->createPoll($owner, $schedule, ['question' => 'ออกกี่โมงดี']);
        $firstPoll = ChatPoll::orderBy('id')->first();

        $result = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/polls/{$firstPoll->id}/vote", [
                'option_ids' => [$second['poll']['options'][0]['id']],
            ])
            ->assertOk()
            ->json('data.poll');

        $this->assertSame([], $result['my_option_ids']);
        $this->assertSame(0, $result['voter_count']);
        unset($first);
    }
}
