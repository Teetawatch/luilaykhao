<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Sticker;
use App\Models\StickerPack;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

class ChatBrowseTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
    }

    private function say(User $user, $schedule, string $body, array $extra = []): ChatMessage
    {
        return ChatMessage::create(array_merge([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'sender_role' => 'customer',
            'body' => $body,
        ], $extra));
    }

    public function test_search_finds_text_newest_first_with_context(): void
    {
        $schedule = $this->tripSchedule();
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);
        $old = $this->say($b, $schedule, 'เบอร์พี่ไกด์ 081-222-3333 นะ');
        app(ChatService::class)->postSystem($schedule, "สรุปการเดินทาง\nเบอร์คนขับ 089-111-2222");
        $this->say($a, $schedule, 'ไม่เกี่ยว');

        $items = $this->actingAs($a, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'search?q='.urlencode('เบอร์')))
            ->assertOk()
            ->json('data.items');

        $this->assertCount(2, $items);
        $this->assertSame('ระบบ', $items[0]['sender_name']);
        $this->assertSame($old->id, $items[1]['message_id']);
        $this->assertStringContainsString('081-222-3333', $items[1]['excerpt']);
    }

    public function test_search_escapes_wildcards_and_hides_blocked_deleted_and_stickers(): void
    {
        $schedule = $this->tripSchedule();
        [$a] = $this->party($schedule, ['เอ']);
        [$b] = $this->party($schedule, ['บี']);
        $this->say($b, $schedule, 'ลด 50% ทั้งร้าน');
        $this->say($b, $schedule, 'ลด 500 บาท');
        $this->say($a, $schedule, 'ลบไปแล้ว', ['is_deleted' => true]);
        $pack = StickerPack::create(['name' => 'p']);
        $sticker = Sticker::create(['pack_id' => $pack->id, 'label' => 'รับทราบ', 'image_path' => 'x.png']);
        $this->say($a, $schedule, '[สติกเกอร์] รับทราบ', ['sticker_id' => $sticker->id]);

        $hits = fn ($q) => array_column($this->actingAs($a, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'search?q='.urlencode($q)))
            ->json('data.items'), 'excerpt');

        $this->assertSame(['ลด 50% ทั้งร้าน'], $hits('0%'));
        $this->assertSame([], $hits('ลบไป'));
        $this->assertSame([], $hits('รับทราบ'));

        UserBlock::create(['blocker_id' => $a->id, 'blocked_id' => $b->id]);
        $this->assertSame([], $hits('ลด'));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson($this->chatUrl($schedule, 'search?q='.urlencode('ลด')))
            ->assertForbidden();
        $this->actingAs($a, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'search?q='.urlencode('ล')))
            ->assertStatus(422);
    }

    public function test_media_lists_images_and_links_with_locations_flagged(): void
    {
        $schedule = $this->tripSchedule();
        [$a] = $this->party($schedule, ['เอ']);
        $img = $this->say($a, $schedule, '', ['body' => null, 'image_path' => 'chat/a.jpg']);
        $loc = $this->say($a, $schedule, "📍 ตำแหน่งของฉัน\nhttps://www.google.com/maps/search/?api=1&query=13.7,100.5");
        $this->say($a, $schedule, 'ร้านนี้อร่อย https://example.com/menu.');

        $images = $this->actingAs($a, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'media?type=images'))
            ->assertOk()
            ->json('data');
        $this->assertSame([$img->id], array_column($images['items'], 'message_id'));
        $this->assertNull($images['next_before_id']);

        $links = $this->actingAs($a, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'media?type=links'))
            ->json('data.items');
        $this->assertSame(['link', 'location'], array_column($links, 'kind'));
        $this->assertSame('https://example.com/menu', $links[0]['url']);
        $this->assertSame('ร้านนี้อร่อย', $links[0]['title']);
        $this->assertSame('📍 ตำแหน่งของฉัน', $links[1]['title']);
        $this->assertSame($loc->id, $links[1]['message_id']);
    }

    public function test_up_to_three_pins_oldest_drops_and_unpin_keeps_the_rest(): void
    {
        $schedule = $this->tripSchedule();
        $staff = $this->staffOf($schedule);
        [$a] = $this->party($schedule, ['เอ']);
        $msgs = collect(range(1, 4))->map(fn ($i) => $this->say($a, $schedule, "ประกาศ $i"));

        foreach ($msgs as $i => $m) {
            $this->travel(1)->minutes();
            $res = $this->actingAs($staff, 'sanctum')
                ->postJson($this->chatUrl($schedule, "messages/{$m->id}/pin"))
                ->assertOk();
        }

        $this->assertSame(
            [$msgs[3]->id, $msgs[2]->id, $msgs[1]->id],
            array_column($res->json('data.pinned_messages'), 'id'),
        );
        $this->assertNull($msgs[0]->fresh()->pinned_at);

        $this->actingAs($staff, 'sanctum')
            ->deleteJson($this->chatUrl($schedule, "messages/{$msgs[3]->id}/pin"))
            ->assertOk()
            ->assertJsonPath('data.pinned_message.id', $msgs[2]->id);

        $this->actingAs($a, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'room'))
            ->assertJsonPath('data.pinned_message.id', $msgs[2]->id)
            ->assertJsonCount(2, 'data.pinned_messages');
    }
}
