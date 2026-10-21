<?php

namespace Tests\Feature;

use App\Models\ChatCollection;
use App\Models\ChatCollectionDue;
use App\Models\ChatMessage;
use App\Models\ChatPorterBag;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\PromptPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

/**
 * ฝากของลูกหาบ — เลขป้ายต่อใบ ชั่งแล้วคิดตามกิโล ส่งยอดเข้าเก็บเงิน และรับคืน
 */
class ChatPorterTest extends TestCase
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
        return $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, 'porters'), array_merge([
            'title' => 'ลูกหาบขึ้นภูกระดึง',
            'rate_per_kg' => 30,
            'min_charge' => 50,
            'round_up' => true,
            'promptpay_id' => '081-234-5678',
            'payee_name' => 'พี่สตาฟ',
        ], $payload));
    }

    private function add(User $user, TripSchedule $schedule, int $id, int $passengerId, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson($this->chatUrl($schedule, "porters/{$id}/bags"), ['passenger_id' => $passengerId, ...$extra]);
    }

    private function weigh(User $staff, TripSchedule $schedule, int $id, int $bagId, ?float $kg)
    {
        return $this->actingAs($staff, 'sanctum')->putJson($this->chatUrl($schedule, "porters/{$id}/bags/{$bagId}"), ['weight_kg' => $kg]);
    }

    public function test_people_register_bags_and_get_tag_numbers(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule);

        $this->open($family, $schedule)->assertForbidden();
        $this->open($staff, $schedule, ['promptpay_id' => '123'])->assertStatus(422);
        $data = $this->open($staff, $schedule)->assertCreated()->json('data');
        $this->assertStringContainsString('กก.ละ ฿30 (ขั้นต่ำใบละ ฿50)', $data['body']);
        $id = $data['porter']['id'];

        $this->add($family, $schedule, $id, $dad->id, ['count' => 2, 'note' => 'เป้สีแดง'])->assertOk();
        $card = $this->add($solo, $schedule, $id, $mana->id)->assertOk()->json('data.porter');
        $this->assertSame([1, 2, 3], array_column($card['bags'], 'tag_no'));
        $this->assertSame(['พ่อ', 'พ่อ', 'มานะ'], array_column($card['bags'], 'name'));
        $this->assertSame('เป้สีแดง', $card['bags'][0]['note']);
        $this->assertSame([['id' => $mana->id, 'name' => 'มานะ']], $card['mine'], 'คนที่ฉันฝากแทนได้');

        // กติกา
        $this->add($solo, $schedule, $id, $mom->id)->assertStatus(422);
        $this->add($family, $schedule, $id, $mom->id, ['weight_kg' => 5])->assertStatus(422);
        $this->add($family, $schedule, $id, $mom->id, ['count' => 5])->assertOk();
        $this->add($family, $schedule, $id, $mom->id)->assertStatus(422);
    }

    public function test_weighing_prices_each_bag_by_rounded_kilo_with_a_minimum(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.porter.id');
        $this->add($family, $schedule, $id, $dad->id, ['count' => 2])->assertOk();
        [$big, $small] = ChatPorterBag::orderBy('tag_no')->get()->all();

        $this->weigh($family, $schedule, $id, $big->id, 6.2)->assertForbidden();
        $this->weigh($staff, $schedule, $id, $big->id, 0)->assertStatus(422);
        $this->weigh($staff, $schedule, $id, $big->id, 6.2)->assertOk();
        $card = $this->weigh($staff, $schedule, $id, $small->id, 1.1)->assertOk()->json('data.porter');

        // 6.2 กก. ปัดเป็น 7 × 30 = 210, 1.1 กก. ปัดเป็น 2 × 30 = 60 → ไม่ถึงขั้นต่ำ? 60 > 50 จึงเป็น 60
        $this->assertSame([210.0, 60.0], array_map('floatval', array_column($card['bags'], 'fee')));
        $this->assertSame(270.0, (float) $card['total_fee']);
        $this->assertSame(7.3, (float) $card['total_kg']);

        $card = $this->weigh($staff, $schedule, $id, $small->id, 0.4)->json('data.porter');
        $this->assertSame(50.0, (float) $card['bags'][1]['fee'], 'ใบเล็กคิดขั้นต่ำ');
    }

    public function test_billing_opens_a_collection_with_each_persons_own_amount(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.porter.id');
        $bill = fn () => $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "porters/{$id}/bill"));

        $bill()->assertStatus(422);
        $this->add($family, $schedule, $id, $dad->id)->assertOk();
        $this->add($family, $schedule, $id, $mom->id)->assertOk();
        $this->add($solo, $schedule, $id, $mana->id)->assertOk();
        $bags = ChatPorterBag::orderBy('tag_no')->get();
        $this->weigh($staff, $schedule, $id, $bags[0]->id, 8)->assertOk();
        $this->weigh($staff, $schedule, $id, $bags[1]->id, 5)->assertOk();
        $bill()->assertStatus(422)->assertJsonPath('message', 'ยังไม่ได้ชั่งอีก 1 ใบ — ชั่งให้ครบก่อนส่งยอด');
        $this->weigh($staff, $schedule, $id, $bags[2]->id, 10.5)->assertOk();

        $card = $bill()->assertOk()->json('data.porter');
        $this->assertTrue($card['is_billed']);
        $bill()->assertStatus(422);

        $collection = ChatCollection::firstOrFail();
        $this->assertSame(ChatCollection::SOURCE_PORTER, $collection->source);
        $this->assertSame($collection->message_id, $card['collection_message_id']);
        $this->assertSame(
            [$dad->id => 240.0, $mom->id => 150.0, $mana->id => 330.0],
            ChatCollectionDue::orderBy('passenger_id')->get()->mapWithKeys(fn ($d) => [$d->passenger_id => (float) $d->amount])->all(),
        );
        $this->assertTrue(ChatMessage::where('body', 'like', '💰 เก็บเงิน: ค่าลูกหาบ · ลูกหาบขึ้นภูกระดึง — รวม ฿720 (3 คน ยอดไม่เท่ากัน)%')->exists());

        $c = $this->actingAs($family, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/chat/collections/{$collection->id}/claim", ['claimed' => false]);
        $c->assertOk();
        $this->assertTrue($c->json('data.collection.amounts_vary'));
        $this->assertSame(app(PromptPayService::class)->buildPayload('0812345678', 390), $c->json('data.collection.payloads.'.$family->id));

        // แก้รายชื่อจากการ์ดเก็บเงินไม่ได้ — ต้องแก้ที่กระเป๋า
        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/collections/{$collection->id}/payers", ['passenger_ids' => [$dad->id]])
            ->assertStatus(422);
    }

    public function test_reweighing_after_billing_follows_unpaid_people_and_locks_paid_ones(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี']);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน']);
        [$late, , [$late1]] = $this->party($schedule, ['มาสาย จริง']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.porter.id');
        $this->add($family, $schedule, $id, $dad->id)->assertOk();
        $this->add($solo, $schedule, $id, $mana->id)->assertOk();
        [$dadBag, $manaBag] = ChatPorterBag::orderBy('tag_no')->get()->all();
        $this->weigh($staff, $schedule, $id, $dadBag->id, 5)->assertOk();
        $this->weigh($staff, $schedule, $id, $manaBag->id, 5)->assertOk();
        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "porters/{$id}/bill"))->assertOk();
        $collection = ChatCollection::firstOrFail();

        // มานะจ่ายเงินสดแล้ว
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/collections/{$collection->id}/paid", ['passenger_ids' => [$mana->id], 'paid' => true, 'method' => 'cash'])
            ->assertOk();

        $this->weigh($staff, $schedule, $id, $dadBag->id, 9)->assertOk();
        $this->assertSame(270.0, (float) ChatCollectionDue::where('passenger_id', $dad->id)->value('amount'));

        $this->weigh($staff, $schedule, $id, $manaBag->id, 9)->assertStatus(422);
        $this->assertSame(150.0, (float) ChatCollectionDue::where('passenger_id', $mana->id)->value('amount'));

        // คนที่ฝากทีหลังได้ยอดเพิ่มเข้าไป
        $this->add($staff, $schedule, $id, $late1->id, ['weight_kg' => 3])->assertOk();
        $this->assertSame(90.0, (float) ChatCollectionDue::where('passenger_id', $late1->id)->value('amount'));

        // เอากระเป๋าออก = เอาออกจากรายการเก็บเงิน
        $lateBag = ChatPorterBag::where('passenger_id', $late1->id)->firstOrFail();
        $this->actingAs($late, 'sanctum')->deleteJson($this->chatUrl($schedule, "porters/{$id}/bags/{$lateBag->id}"))->assertStatus(422);
        $this->actingAs($staff, 'sanctum')->deleteJson($this->chatUrl($schedule, "porters/{$id}/bags/{$lateBag->id}"))->assertOk();
        $this->assertFalse(ChatCollectionDue::where('passenger_id', $late1->id)->exists());
    }

    public function test_bags_are_ticked_back_and_announced_once_all_are_returned(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี']);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.porter.id');
        $this->add($family, $schedule, $id, $dad->id)->assertOk();
        $this->add($solo, $schedule, $id, $mana->id)->assertOk();
        [$dadBag, $manaBag] = ChatPorterBag::orderBy('tag_no')->get()->all();
        $returned = fn (User $u, array $ids, bool $flag = true) => $this->actingAs($u, 'sanctum')
            ->postJson($this->chatUrl($schedule, "porters/{$id}/returned"), ['bag_ids' => $ids, 'returned' => $flag]);

        $returned($solo, [$dadBag->id])->assertStatus(422);
        $returned($family, [$dadBag->id])->assertOk()->assertJsonPath('data.porter.returned_count', 1);
        $returned($staff, [$manaBag->id])->assertOk()->assertJsonPath('data.porter.returned_count', 2);
        $this->assertSame(1, ChatMessage::where('body', 'like', '🎒 คืนกระเป๋าครบ 2 ใบแล้ว%')->count());

        $returned($family, [$dadBag->id], false)->assertOk();
        $returned($family, [$dadBag->id])->assertOk();
        $this->assertSame(1, ChatMessage::where('body', 'like', '🎒 คืนกระเป๋าครบ%')->count());
    }

    public function test_closing_stops_self_registration_but_not_staff(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.porter.id');

        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "porters/{$id}/close"))->assertOk()
            ->assertJsonPath('data.porter.is_closed', true);
        $this->add($family, $schedule, $id, $dad->id)->assertStatus(422);
        $this->add($staff, $schedule, $id, $dad->id, ['weight_kg' => 4])->assertOk();

        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "porters/{$id}/close"), ['closed' => false])->assertOk()
            ->assertJsonPath('data.porter.is_closed', false);
        $this->add($family, $schedule, $id, $dad->id)->assertOk();
    }
}
