<?php

namespace Tests\Feature;

use App\Models\ChatPoll;
use App\Models\User;
use App\Services\ChatAckService;
use App\Services\ChatCollectionService;
use App\Services\ChatFoodOrderService;
use App\Services\ChatPollService;
use App\Services\ChatRestStopService;
use App\Services\ChatService;
use App\Services\ChatWellnessService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

class ChatTodoTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
    }

    private function todos($user, $schedule): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'todos'))
            ->assertOk()
            ->json('data.items');
    }

    public function test_lists_each_pending_card_for_the_viewer_and_clears_as_they_act(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$owner, , [$p1, $p2]] = $this->party($schedule, ['เอ', 'บี']);

        $vote = app(ChatPollService::class)->create($staff, $schedule, 'ไปน้ำตกไหม', [], kind: ChatPoll::KIND_VOTE, durationMinutes: 10);
        $round = app(ChatFoodOrderService::class)->open($staff, $schedule, 'ข้าวเที่ยง ร้านป้า');
        $collection = app(ChatCollectionService::class)->open($staff, $schedule, 'ค่าเข้าอุทยาน', null, 40, null, null, null);
        $stop = app(ChatRestStopService::class)->open($staff, $schedule, 20, 'ปั๊ม ปตท.');
        $check = app(ChatWellnessService::class)->open($staff, $schedule, 'หลังขึ้นเขา');
        $notice = app(ChatService::class)->postSystem($schedule, "นัดหมายพรุ่งนี้\nตีห้าครึ่ง");
        app(ChatAckService::class)->request($staff, $notice, notify: false);

        $items = collect($this->todos($owner, $schedule));
        $this->assertEqualsCanonicalizing(
            ['vote', 'food_order', 'collection', 'rest_stop', 'wellness', 'ack'],
            $items->pluck('kind')->all(),
        );
        // มีเวลาปิดมาก่อน (โหวต 10 นาที แล้วจุดพัก 20 นาที)
        $this->assertSame(['vote', 'rest_stop'], $items->take(2)->pluck('kind')->all());
        $this->assertSame('฿80 · 2 คน', $items->firstWhere('kind', 'collection')['detail']);
        $this->assertSame(2, $items->firstWhere('kind', 'rest_stop')['count']);
        $this->assertSame($notice->id, $items->firstWhere('kind', 'ack')['message_id']);
        $this->assertSame('นัดหมายพรุ่งนี้', $items->firstWhere('kind', 'ack')['detail']);

        // ทำทีละอย่าง แล้วรายการหายไป
        app(ChatPollService::class)->vote($owner, $vote, [$vote->options()->first()->id]);
        app(ChatFoodOrderService::class)->submit($owner, $round, [], skipped: true);
        Storage::fake(MediaDisk::slipDisk());
        app(ChatCollectionService::class)->claim($owner, $collection, true, UploadedFile::fake()->image('slip.jpg'));
        app(ChatRestStopService::class)->setBoarded($owner, $stop, [$p1->id, $p2->id], true, false);
        app(ChatWellnessService::class)->answer($owner, $check, [$p1->id, $p2->id], 'ok', null, false);
        app(ChatAckService::class)->ack($owner, $notice->fresh());

        $this->assertSame([], $this->todos($owner, $schedule));
    }

    public function test_billed_food_shows_what_i_still_owe(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$owner] = $this->party($schedule, ['เอ']);
        $food = app(ChatFoodOrderService::class);
        $round = $food->open($staff, $schedule, 'ตามสั่ง');
        $food->submit($owner, $round, [['name' => 'กะเพราไก่', 'qty' => 2]]);
        $food->close($round->fresh());
        $food->bill($staff, $round->fresh(), [['name' => 'กะเพราไก่', 'price' => 60]], '0812345678', 'พี่สตาฟ', false);

        $items = collect($this->todos($owner, $schedule));
        $this->assertSame(['food_pay'], $items->pluck('kind')->all());
        $this->assertStringStartsWith('฿120', $items[0]['detail']);
    }

    public function test_meetup_and_wakeup_only_show_once_they_can_be_tapped(): void
    {
        $schedule = $this->tripSchedule(['return_date' => now('Asia/Bangkok')->addDays(2)->toDateString()]);
        $staff = $this->staffOf($schedule);
        [$owner] = $this->party($schedule, ['เอ']);
        $stops = app(ChatRestStopService::class);
        $stops->openMeetup($staff, $schedule, now()->addHours(10), 'ล็อบบี้');

        $this->assertSame([], $this->todos($owner, $schedule));

        $this->travel(8)->hours();
        $this->assertSame(['meetup'], array_column($this->todos($owner, $schedule), 'kind'));
    }

    public function test_others_and_closed_or_deleted_cards_are_not_mine(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$owner] = $this->party($schedule, ['เอ']);
        [$other] = $this->party($schedule, ['บี']);

        $poll = app(ChatPollService::class)->create($staff, $schedule, 'กินอะไรดี', ['ส้มตำ', 'ไก่ย่าง']);
        app(ChatPollService::class)->vote($other, $poll, [$poll->options()->first()->id]);
        $closed = app(ChatPollService::class)->create($staff, $schedule, 'ปิดแล้ว', ['ก', 'ข']);
        app(ChatPollService::class)->close($closed);
        $gone = app(ChatPollService::class)->create($staff, $schedule, 'ลบแล้ว', ['ก', 'ข']);
        app(ChatService::class)->deleteMessage($gone->message);

        $this->assertSame([$poll->message_id], array_column($this->todos($owner, $schedule), 'message_id'));
        $this->assertSame([], $this->todos($other, $schedule));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson($this->chatUrl($schedule, 'todos'))
            ->assertForbidden();
    }

    public function test_since_id_fills_the_gap_back_to_an_old_message(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['เอ']);
        $chat = app(ChatService::class);
        $ids = collect(range(1, 40))->map(fn ($i) => $chat->postSystem($schedule, "ข้อความ $i")->id);

        $res = $this->actingAs($owner, 'sanctum')
            ->getJson($this->chatUrl($schedule, "messages?before_id={$ids[30]}&since_id={$ids[2]}"))
            ->assertOk()
            ->json('data');

        $this->assertSame($ids->slice(2, 28)->values()->all(), array_column($res['messages'], 'id'));
        $this->assertTrue($res['complete']);
        $this->assertTrue($res['has_more']);
    }
}
