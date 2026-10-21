<?php

namespace Tests\Feature;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Models\ChatCollection;
use App\Models\OnsiteMoneyReport;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

/**
 * สรุปยอดหน้างาน — เงินอยู่กับใคร (เงินสด = คนกดรับ, โอน = เจ้าของพร้อมเพย์ของการ์ด)
 * สตาฟกดส่งเงิน การเงินกดรับ และยอดอยู่ต่อหลังห้องแชทถูกล้าง
 */
class OnsiteMoneyTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
        Storage::fake('local');
    }

    private function collect(User $staff, TripSchedule $schedule, string $title, int $amount, string $promptpay = '0812345678'): int
    {
        return $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, 'collections'), [
            'title' => $title,
            'amount' => $amount,
            'promptpay_id' => $promptpay,
        ])->assertCreated()->json('data.collection.id');
    }

    private function payTransfer(User $payer, User $staff, TripSchedule $schedule, int $id, array $ids): void
    {
        $this->actingAs($payer, 'sanctum')->post($this->chatUrl($schedule, "collections/{$id}/claim"), [
            'claimed' => '1',
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "collections/{$id}/paid"), [
            'passenger_ids' => $ids, 'paid' => true, 'method' => 'transfer',
        ])->assertOk();
    }

    private function payCash(User $staff, TripSchedule $schedule, int $id, array $ids): void
    {
        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "collections/{$id}/paid"), [
            'passenger_ids' => $ids, 'paid' => true, 'method' => 'cash',
        ])->assertOk();
    }

    private function finance(): User
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('finance');
        $admin = User::factory()->create(['nickname' => 'พี่บัญชี']);
        $admin->assignRole('admin');

        return $admin;
    }

    /** @return array{0: TripSchedule, 1: User, 2: User, 3: User, 4: User, 5: array<int, int>} */
    private function round(): array
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน']);
        $lead = $this->staffOf($schedule, 'พี่หมู');
        $helper = $this->staffOf($schedule, 'พี่ไก่');

        return [$schedule, $family, $solo, $lead, $helper, [$dad->id, $mom->id, $mana->id]];
    }

    public function test_money_is_split_by_who_holds_it(): void
    {
        [$schedule, $family, , $lead, $helper, [$dad, $mom, $mana]] = $this->round();
        $park = $this->collect($lead, $schedule, 'ค่าอุทยาน', 300);
        $this->payTransfer($family, $lead, $schedule, $park, [$dad, $mom]);
        // ไก่รับเงินสดของมานะแทน (การ์ดของหมู)
        $this->payCash($helper, $schedule, $park, [$mana]);
        $tip = $this->collect($helper, $schedule, 'ทิปไกด์', 100, '0899999999');
        $this->payCash($helper, $schedule, $tip, [$dad]);

        $s = $this->actingAs($lead, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/onsite-money")->assertOk()->json('data');

        $this->assertSame(['collected' => 1000.0, 'cash' => 400.0, 'transfer' => 600.0, 'outstanding' => 200.0], array_intersect_key(
            array_map('floatval', $s['totals']), array_flip(['collected', 'cash', 'transfer', 'outstanding'])
        ));
        $holders = collect($s['holders'])->keyBy('name');
        $this->assertSame([600.0, 0.0, 600.0], [(float) $holders['พี่หมู']['transfer'], (float) $holders['พี่หมู']['cash'], (float) $holders['พี่หมู']['total']]);
        $this->assertSame('081-xxx-5678', $holders['พี่หมู']['promptpay']);
        $this->assertSame([0.0, 400.0], [(float) $holders['พี่ไก่']['transfer'], (float) $holders['พี่ไก่']['cash']]);
        $this->assertSame('pending', $holders['พี่ไก่']['status']);
        $this->assertSame(600.0, (float) $s['mine']['total']);

        $tipRow = collect($s['collections'])->firstWhere('title', 'ทิปไกด์');
        $this->assertSame(['แม่', 'มานะ'], array_column($tipRow['unpaid'], 'name'));
    }

    public function test_handover_two_sided_and_changes_after_sending(): void
    {
        [$schedule, $family, , $lead, $helper, [$dad, $mom, $mana]] = $this->round();
        $finance = $this->finance();
        $park = $this->collect($lead, $schedule, 'ค่าอุทยาน', 300);
        $this->payCash($helper, $schedule, $park, [$dad]);
        $url = "/api/v1/schedules/{$schedule->id}/onsite-money";
        $receive = fn () => $this->actingAs($finance, 'sanctum')->postJson("/api/v1/admin/finance/onsite-money/{$schedule->id}/handovers/{$helper->id}/receive", ['note' => 'รับเงินสดที่ออฟฟิศ']);

        // หมูไม่ได้ถือเงิน
        $this->actingAs($lead, 'sanctum')->postJson("{$url}/handover")->assertStatus(422);
        $receive()->assertStatus(422)->assertJsonPath('message', 'สตาฟคนนี้ยังไม่ได้กดส่งเงิน');

        $this->actingAs($helper, 'sanctum')->postJson("{$url}/handover", ['note' => 'วางไว้ที่โต๊ะบัญชี'])
            ->assertOk()->assertJsonPath('data.mine.status', 'submitted');

        // เก็บเงินสดเพิ่มหลังส่ง — ยอดเปลี่ยน การเงินรับไม่ได้จนกว่าจะส่งใหม่
        $this->payCash($helper, $schedule, $park, [$mom]);
        $this->actingAs($helper, 'sanctum')->getJson($url)->assertJsonPath('data.mine.status', 'changed');
        $receive()->assertStatus(422);

        $this->actingAs($helper, 'sanctum')->postJson("{$url}/handover")->assertOk();
        $s = $receive()->assertOk()->json('data');
        $h = collect($s['holders'])->firstWhere('user_id', $helper->id);
        $this->assertSame('received', $h['status']);
        $this->assertSame(600.0, (float) $h['handover']['total']);
        $this->assertSame('พี่บัญชี', $h['handover']['received_by']);
        $this->assertSame(0.0, (float) $s['pending_amount']);

        // รับแล้วส่งซ้ำไม่ได้
        $this->actingAs($helper, 'sanctum')->postJson("{$url}/handover")->assertStatus(422);
        // กดรับผิด ยกเลิกได้
        $this->actingAs($finance, 'sanctum')->deleteJson("/api/v1/admin/finance/onsite-money/{$schedule->id}/handovers/{$helper->id}/receive")
            ->assertOk();
        $this->assertSame('submitted', collect($this->actingAs($helper, 'sanctum')->getJson($url)->json('data.holders'))->firstWhere('user_id', $helper->id)['status']);
        $this->assertNotNull($mana);
    }

    public function test_only_the_rounds_team_and_finance_can_see_it(): void
    {
        // hasAnyRole() ของสิทธิ์ห้องแชทต้องมีบทบาทเหล่านี้อยู่ในระบบ (ใน prod มีเสมอ)
        foreach (['admin', 'operator', 'finance'] as $role) {
            Role::findOrCreate($role);
        }
        [$schedule, $family, , $lead] = $this->round();
        $this->collect($lead, $schedule, 'ค่าอุทยาน', 300);
        $other = $this->tripSchedule();
        $otherStaff = $this->staffOf($other, 'คนอื่น');
        Role::findOrCreate('operator');
        Role::findOrCreate('finance');
        $operator = User::factory()->create();
        $operator->assignRole('operator');

        $this->actingAs($family, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/onsite-money")->assertForbidden();
        $this->actingAs($otherStaff, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/onsite-money")->assertForbidden();
        $this->actingAs($operator, 'sanctum')->getJson('/api/v1/admin/finance/onsite-money')->assertForbidden();
        $this->actingAs($this->finance(), 'sanctum')->getJson('/api/v1/admin/finance/onsite-money')->assertOk();
    }

    public function test_food_bills_are_shown_but_not_counted_as_company_money(): void
    {
        [$schedule, $family, , $lead] = $this->round();
        $round = $this->actingAs($lead, 'sanctum')->postJson($this->chatUrl($schedule, 'food-rounds'), ['title' => 'มื้อเย็น'])
            ->assertCreated()->json('data.food_round.id');
        $this->actingAs($family, 'sanctum')->putJson($this->chatUrl($schedule, "food-rounds/{$round}/my-order"), [
            'items' => [['name' => 'ข้าวผัด', 'qty' => 2]],
        ])->assertOk();
        $this->actingAs($lead, 'sanctum')->putJson($this->chatUrl($schedule, "food-rounds/{$round}/bill"), [
            'prices' => [['name' => 'ข้าวผัด', 'price' => 60]],
            'promptpay_id' => '0812345678',
        ])->assertOk();

        $s = $this->actingAs($lead, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/onsite-money")->json('data');

        $this->assertSame(120.0, (float) $s['food'][0]['total']);
        $this->assertSame(120.0, (float) $s['totals']['food_outstanding']);
        $this->assertSame([], $s['holders']);
        $this->assertSame(0.0, (float) $s['totals']['collected']);
    }

    public function test_summary_outlives_the_chat_purge_and_finance_sees_open_rounds(): void
    {
        [$schedule, $family, , $lead, , [$dad, $mom]] = $this->round();
        $finance = $this->finance();
        $park = $this->collect($lead, $schedule, 'ค่าอุทยาน', 300);
        $this->payTransfer($family, $lead, $schedule, $park, [$dad, $mom]);

        $list = $this->actingAs($finance, 'sanctum')->getJson('/api/v1/admin/finance/onsite-money')->assertOk()->json('data');
        $this->assertSame(1, $list['pending_count']);
        $this->assertSame(600.0, (float) $list['pending_amount']);
        $this->assertFalse($list['rounds'][0]['is_settled']);

        // ห้องแชทถูกล้าง 3 วันหลังจบทริป
        $this->travel(PurgeEndedTripChatsJob::DELETE_AFTER_DAYS + 2)->days();
        (new PurgeEndedTripChatsJob)->handle();
        $this->assertSame(0, ChatCollection::count());
        $this->assertSame(1, OnsiteMoneyReport::count());

        $s = $this->actingAs($lead, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/onsite-money")->assertOk()->json('data');
        $this->assertTrue($s['is_frozen']);
        $this->assertSame(600.0, (float) $s['mine']['total']);

        $this->actingAs($lead, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/onsite-money/handover")->assertOk();
        $this->actingAs($finance, 'sanctum')->postJson("/api/v1/admin/finance/onsite-money/{$schedule->id}/handovers/{$lead->id}/receive")->assertOk();
        $this->assertTrue($this->actingAs($finance, 'sanctum')->getJson('/api/v1/admin/finance/onsite-money')->json('data.rounds.0.is_settled'));
    }

    public function test_staff_holding_money_are_reminded_each_morning_until_they_send(): void
    {
        [$schedule, , , $lead, $helper, [$dad]] = $this->round();
        $park = $this->collect($lead, $schedule, 'ค่าอุทยาน', 300);
        $this->payCash($helper, $schedule, $park, [$dad]);

        // ระหว่างทริปยังไม่เตือน
        $this->artisan('onsite-money:remind')->assertSuccessful();
        $this->assertSame([], $this->pushesTo($helper));

        $this->travel(2)->days();
        $this->artisan('onsite-money:remind')->assertSuccessful();
        $this->assertSame('💰 ยังไม่ได้ส่งเงินหน้างาน ฿300', $this->pushesTo($helper)[0]['title']);
        $this->assertSame('onsite_money', $this->pushesTo($helper)[0]['data']['type']);
        $this->assertSame([], $this->pushesTo($lead));

        $this->actingAs($helper, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/onsite-money/handover")->assertOk();
        $this->artisan('onsite-money:remind')->assertSuccessful();
        $this->assertCount(1, $this->pushesTo($helper));
    }
}
