<?php

namespace Tests\Feature;

use App\Jobs\SendChatPushJob;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ChatTrailCheck;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

/**
 * เช็คพอยต์บนเส้นทางเดิน — ใครถึงไหนแล้ว + "ตามไม่ทัน ขอพัก" แบบส่วนตัว
 */
class ChatTrailCheckTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
    }

    private function open(User $staff, TripSchedule $schedule, array $payload = [])
    {
        return $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, 'trail-checks'), array_merge([
            'title' => 'ขึ้นยอดภูกระดึง',
            'checkpoints' => ['ซำแฮก', 'หลังแป', 'ยอด'],
        ], $payload));
    }

    private function reach(User $user, TripSchedule $schedule, int $id, array $ids, ?int $index)
    {
        return $this->actingAs($user, 'sanctum')->postJson($this->chatUrl($schedule, "trail-checks/{$id}/reach"), [
            'passenger_ids' => $ids,
            'index' => $index,
        ]);
    }

    public function test_staff_opens_a_route_and_everyone_starts_before_the_first_point(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        $staff = $this->staffOf($schedule);

        $this->open($owner, $schedule)->assertForbidden();
        $this->open($staff, $schedule, ['checkpoints' => []])->assertStatus(422);
        $this->open($staff, $schedule, ['checkpoints' => ['ยอด', 'ยอด']])->assertStatus(422);

        $data = $this->open($staff, $schedule)->assertCreated()->json('data');

        $this->assertStringContainsString('ขึ้นยอดภูกระดึง: ซำแฮก → หลังแป → ยอด', $data['body']);
        $card = $data['trail_check'];
        $this->assertSame(['ซำแฮก', 'หลังแป', 'ยอด'], $card['checkpoints']);
        $this->assertSame(2, $card['total']);
        $this->assertSame([0, 0, 0], $card['counts']);
        $this->assertSame(-1, $card['tail_index']);
        $this->assertSame(['พ่อ', 'แม่'], $card['tail_names']);
        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->callToAction === true);

        // เปิดใบใหม่ = ใบเก่าปิดเงียบ ๆ
        $this->open($staff, $schedule)->assertCreated();
        $this->assertSame(1, ChatTrailCheck::whereNull('closed_at')->count());
    }

    public function test_people_mark_their_own_group_and_staff_mark_anyone(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.trail_check.id');

        $card = $this->reach($family, $schedule, $id, [$dad->id, $mom->id], 0)->assertOk()->json('data.trail_check');
        $this->assertSame([2, 0, 0], $card['counts']);
        $this->assertSame(['มานะ'], $card['tail_names']);

        // คนอื่นกดแทนกลุ่มอื่นไม่ได้
        $this->reach($solo, $schedule, $id, [$dad->id], 1)->assertStatus(422);
        // จุดที่ไม่มีในเส้นทาง
        $this->reach($staff, $schedule, $id, [$mana->id], 3)->assertStatus(422);

        $card = $this->reach($staff, $schedule, $id, [$mana->id], 1)->assertOk()->json('data.trail_check');
        $this->assertSame([3, 1, 0], $card['counts']);
        $this->assertSame(0, $card['tail_index']);
        $this->assertSame(['พ่อ', 'แม่'], $card['tail_names']);

        // กดผิด ถอยกลับได้ (null = ยังไม่ถึงจุดแรก)
        $card = $this->reach($family, $schedule, $id, [$mom->id], null)->assertOk()->json('data.trail_check');
        $this->assertSame([2, 1, 0], $card['counts']);
        $this->assertSame(['แม่'], $card['tail_names']);
    }

    public function test_everyone_reaching_the_last_point_is_announced_once(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.trail_check.id');

        $this->reach($family, $schedule, $id, [$dad->id], 2)->assertOk();
        $this->assertFalse(ChatMessage::where('body', 'like', '%ถึงยอดครบ%')->exists());

        $card = $this->reach($family, $schedule, $id, [$mom->id], 2)->assertOk()->json('data.trail_check');
        $this->assertSame([], $card['tail_names']);
        $this->assertSame(1, ChatMessage::where('body', '🏔️ ถึงยอดครบ 2/2 คนแล้ว เก่งมากทุกคน! 🎉')->count());
        $this->assertCount(1, array_filter($this->pushesTo($staff), fn ($p) => $p['title'] === '🏔️ ถึงยอดครบทุกคนแล้ว'));

        // ถอยแล้วกลับมาถึงอีกรอบ ไม่ประกาศซ้ำ
        $this->reach($family, $schedule, $id, [$mom->id], 1)->assertOk();
        $this->reach($family, $schedule, $id, [$mom->id], 2)->assertOk();
        $this->assertSame(1, ChatMessage::where('body', 'like', '%ถึงยอดครบ%')->count());
    }

    public function test_asking_for_a_break_reaches_staff_privately(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule, 'พี่หมู');
        $id = $this->open($staff, $schedule)->json('data.trail_check.id');
        $this->reach($family, $schedule, $id, [$dad->id, $mom->id], 0)->assertOk();

        $card = $this->actingAs($family, 'sanctum')
            ->postJson($this->chatUrl($schedule, "trail-checks/{$id}/help"), ['passenger_ids' => [$mom->id], 'note' => 'ปวดเข่า'])
            ->assertOk()
            ->json('data.trail_check');

        $this->assertSame(1, $card['help_count']);
        $this->assertTrue($card['my_help']);
        $this->assertArrayNotHasKey('needs_help', $card['passengers'][0], 'ห้องรวมไม่รู้ว่าใครขอพัก');

        $toStaff = $this->pushesTo($staff);
        $this->assertCount(1, $toStaff);
        $this->assertSame('🙋 แม่ ขอพัก ตามไม่ทัน', $toStaff[0]['title']);
        $this->assertSame('ล่าสุดถึง ซำแฮก · "ปวดเข่า" — เปิดแชทเพื่อกดรับทราบ', $toStaff[0]['body']);

        // กดซ้ำทันที = คำขอเดิม ไม่เด้งซ้ำ
        $this->actingAs($family, 'sanctum')->postJson($this->chatUrl($schedule, "trail-checks/{$id}/help"), ['passenger_ids' => [$mom->id]])->assertOk();
        $this->assertCount(1, $this->pushesTo($staff));

        // คนอื่นเห็นว่าตัวเองไม่ได้ขอ
        $this->actingAs($solo, 'sanctum')->getJson($this->chatUrl($schedule, "trail-checks/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.trail_check.my_help', false)
            ->assertJsonMissingPath('data.trail_check.passengers.0.phone');

        // ทีมงานเห็นชื่อ หมายเหตุ เบอร์
        $detail = $this->actingAs($staff, 'sanctum')->getJson($this->chatUrl($schedule, "trail-checks/{$id}"))->json('data.trail_check.passengers');
        $momRow = collect($detail)->firstWhere('id', $mom->id);
        $this->assertTrue($momRow['needs_help']);
        $this->assertSame('ปวดเข่า', $momRow['help_note']);
        $this->assertSame('0811111111', $momRow['phone']);

        // รับทราบ → คนขอได้แจ้งเตือน
        $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, "trail-checks/{$id}/help/ack"), ['passenger_ids' => [$mom->id]])
            ->assertOk()
            ->assertJsonPath('data.trail_check.help_count', 0);
        $this->assertSame('🙋 ทีมงานรับทราบแล้ว', $this->pushesTo($family)[0]['title']);
        $this->assertStringContainsString('พี่หมู', $this->pushesTo($family)[0]['body']);
    }

    public function test_walking_on_clears_a_break_request(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.trail_check.id');

        $this->actingAs($family, 'sanctum')->postJson($this->chatUrl($schedule, "trail-checks/{$id}/help"))->assertOk()
            ->assertJsonPath('data.trail_check.help_count', 1);
        $this->assertSame('ยังไม่ถึง ซำแฮก — เปิดแชทเพื่อกดรับทราบ', $this->pushesTo($staff)[0]['body']);

        $this->reach($family, $schedule, $id, [$dad->id], 0)->assertOk()
            ->assertJsonPath('data.trail_check.help_count', 0)
            ->assertJsonPath('data.trail_check.my_help', false);

        // ถอนเองก็ได้
        $this->actingAs($family, 'sanctum')->postJson($this->chatUrl($schedule, "trail-checks/{$id}/help"))->assertOk();
        $this->actingAs($family, 'sanctum')->deleteJson($this->chatUrl($schedule, "trail-checks/{$id}/help"))->assertOk()
            ->assertJsonPath('data.trail_check.help_count', 0);
    }

    public function test_closing_summarises_and_locks_the_card(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.trail_check.id');
        $this->reach($family, $schedule, $id, [$dad->id], 2)->assertOk();

        $this->actingAs($family, 'sanctum')->postJson($this->chatUrl($schedule, "trail-checks/{$id}/close"))->assertForbidden();
        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "trail-checks/{$id}/close"))->assertOk()
            ->assertJsonPath('data.trail_check.is_closed', true);

        $this->assertTrue(ChatMessage::where('body', '🏔️ ปิดเช็คพอยต์ — ถึงยอด 1/2 คน')->exists());
        $this->reach($family, $schedule, $id, [$mom->id], 2)->assertStatus(422);
    }

    public function test_forgotten_cards_close_themselves_and_no_shows_are_not_counted(): void
    {
        $schedule = $this->tripSchedule();
        [, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        $mom->forceFill(['not_going_at' => now()])->save();
        $staff = $this->staffOf($schedule);

        $this->open($staff, $schedule)->assertCreated()->assertJsonPath('data.trail_check.total', 1);

        $this->travel(ChatTrailCheck::AUTO_CLOSE_AFTER_HOURS + 1)->hours();
        $this->settleChat();

        $this->assertNotNull(ChatTrailCheck::first()->closed_at);
        $this->assertInstanceOf(BookingPassenger::class, $dad);
    }
}
