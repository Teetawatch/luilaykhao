<?php

namespace Tests\Feature;

use App\Events\ChatWellnessUpdated;
use App\Models\ChatMessage;
use App\Models\ChatWellnessCheck;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

/**
 * เช็คอาการ "ยังไหวไหม" — คำตอบเห็นแค่ทีมงานกับคนตอบ
 */
class ChatWellnessTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
    }

    private function open(User $staff, TripSchedule $schedule)
    {
        return $this->actingAs($staff, 'sanctum')
            ->postJson($this->chatUrl($schedule, 'wellness'), ['label' => 'ก่อนขึ้นช่วงชัน']);
    }

    private function answer(User $user, TripSchedule $schedule, int $id, array $ids, string $status, ?string $note = null)
    {
        return $this->actingAs($user, 'sanctum')->postJson($this->chatUrl($schedule, "wellness/{$id}/answer"), [
            'passenger_ids' => $ids,
            'status' => $status,
            'note' => $note,
        ]);
    }

    public function test_answers_stay_between_the_person_and_staff(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule);

        $this->open($family, $schedule)->assertForbidden();
        $data = $this->open($staff, $schedule)->assertCreated()->json('data');
        $this->assertStringContainsString('เช็คอาการ (ก่อนขึ้นช่วงชัน)', $data['body']);
        $id = $data['wellness']['id'];

        Event::fake([ChatWellnessUpdated::class]);
        $mine = $this->answer($family, $schedule, $id, [$dad->id, $mom->id], 'tired', 'หายใจไม่ทัน')
            ->assertOk()
            ->json('data.wellness');
        $this->assertSame(['tired', 'tired'], array_column($mine['mine'], 'status'));
        $this->assertArrayNotHasKey('detail', $mine);

        // ที่กระจายทั้งห้องมีแค่จำนวน
        Event::assertDispatched(ChatWellnessUpdated::class, function ($e) {
            return $e->payload['answered_count'] === 2
                && ! array_key_exists('mine', $e->payload)
                && ! array_key_exists('detail', $e->payload)
                && ! str_contains(json_encode($e->payload, JSON_UNESCAPED_UNICODE), 'หายใจไม่ทัน');
        });

        // คนอื่นในห้องไม่เห็นคำตอบของครอบครัวนี้
        $other = $this->actingAs($solo, 'sanctum')->getJson($this->chatUrl($schedule, "wellness/{$id}"))->assertOk()->json('data.wellness');
        $this->assertSame([null], array_column($other['mine'], 'status'));
        $this->assertArrayNotHasKey('detail', $other);
        $this->assertStringNotContainsString('หายใจไม่ทัน', json_encode($other, JSON_UNESCAPED_UNICODE));

        // ลูกทริปตอบแทนกลุ่มอื่นไม่ได้
        $this->answer($solo, $schedule, $id, [$dad->id], 'ok')->assertStatus(422);

        // ทีมงานเห็นทั้งหมด เหนื่อยขึ้นก่อนคนที่ยังไม่ตอบ
        $detail = $this->actingAs($staff, 'sanctum')->getJson($this->chatUrl($schedule, "wellness/{$id}"))->json('data.wellness.detail');
        $this->assertSame(['ok' => 0, 'tired' => 2, 'unwell' => 0, 'none' => 1], $detail['counts']);
        $this->assertSame(['พ่อ', 'แม่', 'มานะ'], array_column($detail['passengers'], 'name'));
        $this->assertSame('หายใจไม่ทัน', $detail['passengers'][0]['note']);
    }

    public function test_feeling_unwell_reaches_staff_at_once_and_handling_tells_the_person(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี']);
        $staff = $this->staffOf($schedule, 'พี่หมู');
        $id = $this->open($staff, $schedule)->json('data.wellness.id');

        $this->answer($family, $schedule, $id, [$dad->id], 'tired')->assertOk();
        $this->assertSame([], $this->pushesTo($staff), 'เหนื่อยเฉย ๆ ไม่ต้องเด้ง');

        $this->answer($family, $schedule, $id, [$dad->id], 'unwell', 'เวียนหัว')->assertOk();
        $push = $this->pushesTo($staff);
        $this->assertCount(1, $push);
        $this->assertSame('🤕 พ่อ บอกว่าไม่ค่อยไหว', $push[0]['title']);
        $this->assertStringContainsString('"เวียนหัว"', $push[0]['body']);
        $this->assertSame('wellness_unwell', $push[0]['data']['type']);

        // ตอบซ้ำแบบเดิมไม่เด้งซ้ำ
        $this->answer($family, $schedule, $id, [$dad->id], 'unwell', 'เวียนหัวมาก')->assertOk();
        $this->assertCount(1, $this->pushesTo($staff));

        $this->actingAs($family, 'sanctum')->postJson($this->chatUrl($schedule, "wellness/{$id}/handled"), ['passenger_id' => $dad->id])->assertForbidden();
        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "wellness/{$id}/handled"), ['passenger_id' => $dad->id])
            ->assertOk()
            ->assertJsonPath('data.wellness.detail.passengers.0.handled', true);
        $this->assertSame('❤️ ทีมงานกำลังไปดูแลพ่อ', $this->pushesTo($family)[0]['title']);
        $this->assertStringContainsString('พี่หมู', $this->pushesTo($family)[0]['body']);

        // หายแล้วตอบ "สบาย" — หมายเหตุเก่าหายไป
        $mine = $this->answer($family, $schedule, $id, [$dad->id], 'ok', 'ยังเวียนหัว')->json('data.wellness.mine.0');
        $this->assertSame('ok', $mine['status']);
        $this->assertNull($mine['note']);
        $this->assertFalse($mine['handled']);
    }

    public function test_quiet_people_are_asked_again_once_and_staff_get_the_list(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี']);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.wellness.id');
        $this->answer($family, $schedule, $id, [$dad->id], 'ok')->assertOk();

        $this->settleChat();
        $this->assertSame([], $this->pushes, 'ยังไม่ครบ 10 นาที');

        $this->travel(ChatWellnessCheck::REMIND_AFTER_MINUTES + 1)->minutes();
        $this->settleChat();
        $this->settleChat();

        $this->assertCount(1, $this->pushesTo($solo));
        $this->assertSame('❤️ ยังไหวไหมครับ?', $this->pushesTo($solo)[0]['title']);
        $this->assertSame([], $this->pushesTo($family));
        $this->assertCount(1, $this->pushesTo($staff));
        $this->assertSame('ยังไม่ตอบ: มานะ', $this->pushesTo($staff)[0]['body']);

        $this->travel(ChatWellnessCheck::AUTO_CLOSE_AFTER_HOURS)->hours();
        $this->settleChat();
        $this->assertTrue(ChatWellnessCheck::find($id)->isClosed());
        $this->assertTrue(ChatMessage::where('body', 'like', '❤️ ปิดเช็คอาการแล้ว — ตอบ 1/2 คน%')->exists());
        $this->answer($solo, $schedule, $id, [$mana->id], 'ok')->assertStatus(422)
            ->assertJsonPath('message', 'ปิดเช็คอาการนี้ไปแล้ว ถ้าไม่สบายทักทีมงานได้เลยครับ');
    }

    public function test_message_payload_carries_my_answers_only(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี']);
        [$solo] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.wellness.id');
        $this->answer($family, $schedule, $id, [$dad->id], 'unwell', 'ปวดท้อง')->assertOk();

        $messages = $this->actingAs($solo, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")->assertOk()->json('data.messages');
        $card = collect($messages)->firstWhere('wellness.id', $id)['wellness'];
        $this->assertSame(1, $card['answered_count']);
        $this->assertSame('มานะ', $card['mine'][0]['name']);
        $this->assertStringNotContainsString('ปวดท้อง', json_encode($messages, JSON_UNESCAPED_UNICODE));
    }
}
