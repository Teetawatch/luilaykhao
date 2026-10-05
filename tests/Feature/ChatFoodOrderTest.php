<?php

namespace Tests\Feature;

use App\Events\ChatFoodRoundUpdated;
use App\Jobs\SendChatPushJob;
use App\Jobs\SettleChatPollsJob;
use App\Models\Booking;
use App\Models\ChatFoodRound;
use App\Models\ChatMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use App\Services\PromptPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รับออเดอร์อาหารในห้องแชท — สตาฟเปิดรอบ ลูกทริปพิมพ์เมนู สตาฟได้รายการรวมไปสั่ง
 */
class ChatFoodOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปกินข้าว',
            'slug' => 'food-trip-'.uniqid(),
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

    private function member(TripSchedule $schedule, string $nickname): User
    {
        $user = User::factory()->create(['nickname' => $nickname]);
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
        $staff = User::factory()->create(['nickname' => 'พี่สตาฟ']);
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function openRound(User $staff, TripSchedule $schedule, array $payload = []): array
    {
        return $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds", array_merge([
                'title' => 'มื้อเย็นขากลับ ร้านป้าแดง',
                'note' => 'ร้านตามสั่ง จ่ายเองที่ร้าน',
            ], $payload))
            ->assertCreated()
            ->json('data');
    }

    private function order(User $user, TripSchedule $schedule, int $roundId, array $payload)
    {
        return $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/my-order", $payload);
    }

    private function closeAnnouncements(TripSchedule $schedule)
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->where('sender_role', 'system')
            ->where('body', 'like', '🍜 ปิดรับออเดอร์%')
            ->get();
    }

    public function test_staff_opens_a_round_that_appears_as_a_chat_card_and_pings_everyone(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->member($schedule, 'มิ้นท์');
        $staff = $this->staff($schedule);

        $data = $this->openRound($staff, $schedule);

        $this->assertSame('🍜 รับออเดอร์อาหาร: มื้อเย็นขากลับ ร้านป้าแดง', $data['body']);
        $this->assertSame('staff', $data['sender_role']);
        $this->assertSame('มื้อเย็นขากลับ ร้านป้าแดง', $data['food_round']['title']);
        $this->assertSame('ร้านตามสั่ง จ่ายเองที่ร้าน', $data['food_round']['note']);
        $this->assertFalse($data['food_round']['is_closed']);
        $this->assertSame([], $data['food_round']['orders']);
        $this->assertNull($data['poll']);

        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->callToAction === true);
    }

    public function test_customers_cannot_open_a_round(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $customer = $this->member($schedule, 'มิ้นท์');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds", ['title' => 'ข้าว'])
            ->assertForbidden();
    }

    public function test_orders_are_merged_into_a_shop_summary(): void
    {
        Bus::fake();
        Event::fake([ChatFoodRoundUpdated::class]);
        $schedule = $this->makeSchedule();
        $mint = $this->member($schedule, 'มิ้นท์');
        $bank = $this->member($schedule, 'แบงค์');
        $ploy = $this->member($schedule, 'พลอย');
        $staff = $this->staff($schedule);
        $roundId = $this->openRound($staff, $schedule)['food_round']['id'];

        $this->order($mint, $schedule, $roundId, ['items' => [
            ['name' => 'กะเพราหมูสับ ไข่ดาว', 'qty' => 1],
            ['name' => 'ชาเย็น', 'qty' => 2],
        ]])->assertOk();
        // ช่องว่างเกิน/ตัวพิมพ์ต่างกันยังนับเป็นเมนูเดียวกัน
        $this->order($bank, $schedule, $roundId, ['items' => [
            ['name' => '  กะเพราหมูสับ   ไข่ดาว ', 'qty' => 2],
        ]])->assertOk();
        $this->order($ploy, $schedule, $roundId, ['skipped' => true])->assertOk();

        $round = $this->order($mint, $schedule, $roundId, ['items' => [
            ['name' => 'กะเพราหมูสับ ไข่ดาว', 'qty' => 1],
            ['name' => 'ชาเย็น', 'qty' => 1],
        ]])->assertOk()->json('data.food_round');

        $this->assertSame(2, $round['order_count']);
        $this->assertSame(1, $round['skipped_count']);
        $this->assertSame(4, $round['dish_count']);
        $this->assertCount(3, $round['orders'], 'แก้ออเดอร์ = แทนที่ ไม่ใช่เพิ่มแถว');

        $this->assertSame('กะเพราหมูสับ ไข่ดาว', $round['summary'][0]['name']);
        $this->assertSame(3, $round['summary'][0]['qty']);
        $this->assertSame(['มิ้นท์', 'แบงค์'], $round['summary'][0]['people']);
        $this->assertSame(['name' => 'ชาเย็น', 'qty' => 1, 'people' => ['มิ้นท์'], 'price' => null], $round['summary'][1]);

        Event::assertDispatched(ChatFoodRoundUpdated::class, fn ($e) => $e->round['dish_count'] === 4);
    }

    public function test_empty_order_without_skip_is_rejected(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $mint = $this->member($schedule, 'มิ้นท์');
        $roundId = $this->openRound($this->staff($schedule), $schedule)['food_round']['id'];

        $this->order($mint, $schedule, $roundId, ['items' => []])->assertStatus(422);
    }

    public function test_staff_can_take_an_order_for_someone_without_the_app_and_remove_any_order(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $mint = $this->member($schedule, 'มิ้นท์');
        $bank = $this->member($schedule, 'แบงค์');
        $staff = $this->staff($schedule);
        $roundId = $this->openRound($staff, $schedule)['food_round']['id'];

        $round = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/orders", [
                'name' => 'ลุงสมชาย',
                'items' => [['name' => 'ข้าวผัดกุ้ง', 'qty' => 1]],
            ])
            ->assertOk()
            ->json('data.food_round');
        $this->assertSame('ลุงสมชาย', $round['orders'][0]['name']);
        $this->assertTrue($round['orders'][0]['is_guest']);

        // ลูกค้าจดแทนคนอื่นไม่ได้
        $this->actingAs($mint, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/orders", [
                'name' => 'เพื่อน',
                'items' => [['name' => 'ข้าวผัด']],
            ])
            ->assertForbidden();

        $bankOrder = $this->order($bank, $schedule, $roundId, ['items' => [['name' => 'ผัดซีอิ๊ว']]])
            ->json('data.food_round.orders.1.id');

        // ลูกค้าลบของคนอื่นไม่ได้ สตาฟลบได้
        $this->actingAs($mint, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/orders/{$bankOrder}")
            ->assertForbidden();
        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/orders/{$bankOrder}")
            ->assertOk()
            ->assertJsonCount(1, 'data.food_round.orders');
    }

    public function test_closing_announces_the_total_and_blocks_new_orders_until_reopened(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $mint = $this->member($schedule, 'มิ้นท์');
        $bank = $this->member($schedule, 'แบงค์');
        $staff = $this->staff($schedule);
        $roundId = $this->openRound($staff, $schedule)['food_round']['id'];

        $this->order($mint, $schedule, $roundId, ['items' => [['name' => 'กะเพราไก่', 'qty' => 2]]]);

        // ลูกค้าปิดรอบไม่ได้
        $this->actingAs($mint, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/close")
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/close")
            ->assertOk()
            ->assertJsonPath('data.food_round.is_closed', true);

        $announced = $this->closeAnnouncements($schedule);
        $this->assertCount(1, $announced);
        $this->assertSame(
            '🍜 ปิดรับออเดอร์ “มื้อเย็นขากลับ ร้านป้าแดง” แล้ว — รวม 2 จาน จาก 1 คน น้องสตาฟกำลังไปสั่งให้นะครับ',
            $announced->first()->body,
        );

        $this->order($bank, $schedule, $roundId, ['items' => [['name' => 'ข้าวผัด']]])->assertStatus(422);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.food_round.is_closed', false);

        $this->order($bank, $schedule, $roundId, ['items' => [['name' => 'ข้าวผัด']]])->assertOk();
    }

    public function test_timed_round_closes_by_itself_and_is_announced_once(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $mint = $this->member($schedule, 'มิ้นท์');
        $roundId = $this->openRound($this->staff($schedule), $schedule, ['duration_minutes' => 15])['food_round']['id'];
        $this->order($mint, $schedule, $roundId, ['items' => [['name' => 'ข้าวไข่เจียว']]]);

        $this->travel(16)->minutes();
        app()->call([new SettleChatPollsJob, 'handle']);
        app()->call([new SettleChatPollsJob, 'handle']);

        $this->assertTrue(ChatFoodRound::find($roundId)->isClosed());
        $this->assertCount(1, $this->closeAnnouncements($schedule));
    }

    public function test_strangers_cannot_order(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $roundId = $this->openRound($this->staff($schedule), $schedule)['food_round']['id'];

        $this->order(User::factory()->create(), $schedule, $roundId, ['items' => [['name' => 'ข้าว']]])
            ->assertForbidden();
    }

    // ── หารบิล ──────────────────────────────────────────────────────────────

    /**
     * รอบที่สั่งครบแล้ว: มิ้นท์ กะเพรา×1 + ชาเย็น×2, แบงค์ กะเพรา×2, พลอยไม่สั่ง
     *
     * @return array{0: User, 1: User, 2: User, 3: User, 4: int, 5: TripSchedule}
     */
    private function orderedRound(): array
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $mint = $this->member($schedule, 'มิ้นท์');
        $bank = $this->member($schedule, 'แบงค์');
        $ploy = $this->member($schedule, 'พลอย');
        $staff = $this->staff($schedule);
        $roundId = $this->openRound($staff, $schedule)['food_round']['id'];

        $this->order($mint, $schedule, $roundId, ['items' => [
            ['name' => 'กะเพราหมูสับ ไข่ดาว', 'qty' => 1],
            ['name' => 'ชาเย็น', 'qty' => 2],
        ]]);
        $this->order($bank, $schedule, $roundId, ['items' => [['name' => 'กะเพราหมูสับ  ไข่ดาว', 'qty' => 2]]]);
        $this->order($ploy, $schedule, $roundId, ['skipped' => true]);

        return [$mint, $bank, $ploy, $staff, $roundId, $schedule];
    }

    private function bill(User $staff, TripSchedule $schedule, int $roundId, array $payload)
    {
        return $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/bill", $payload);
    }

    private function orderOf(array $round, string $name): array
    {
        return collect($round['orders'])->firstWhere('name', $name);
    }

    public function test_staff_prices_dishes_and_each_person_gets_their_own_total_and_qr(): void
    {
        [$mint, $bank, , $staff, $roundId, $schedule] = $this->orderedRound();

        $round = $this->bill($staff, $schedule, $roundId, [
            'prices' => [
                ['name' => 'กะเพราหมูสับ ไข่ดาว', 'price' => 60],
                ['name' => 'ชาเย็น', 'price' => 25],
            ],
            'promptpay_id' => '081-234-5678',
            'payee_name' => 'พี่สตาฟ',
        ])->assertOk()->json('data.food_round');

        $this->assertSame(110.0, (float) $this->orderOf($round, 'มิ้นท์')['amount']);
        $this->assertSame(120.0, (float) $this->orderOf($round, 'แบงค์')['amount']);
        $this->assertSame('unpaid', $this->orderOf($round, 'มิ้นท์')['pay_status']);
        $this->assertSame('none', $this->orderOf($round, 'พลอย')['pay_status']);
        $this->assertSame(
            app(PromptPayService::class)->buildPayload('0812345678', 110),
            $this->orderOf($round, 'มิ้นท์')['promptpay_payload'],
        );
        $this->assertNull($this->orderOf($round, 'พลอย')['promptpay_payload']);

        $this->assertSame(230.0, (float) $round['billing']['total']);
        $this->assertSame(230.0, (float) $round['billing']['outstanding']);
        $this->assertSame('0812345678', $round['billing']['promptpay_id']);
        $this->assertSame([], $round['billing']['unpriced']);
        $this->assertSame(60.0, (float) $round['summary'][0]['price']);
    }

    public function test_dishes_without_a_price_hold_back_that_persons_total(): void
    {
        [, , , $staff, $roundId, $schedule] = $this->orderedRound();

        $round = $this->bill($staff, $schedule, $roundId, [
            'prices' => [['name' => 'กะเพราหมูสับ ไข่ดาว', 'price' => 60]],
            'promptpay_id' => '0812345678',
        ])->json('data.food_round');

        $this->assertSame('unpriced', $this->orderOf($round, 'มิ้นท์')['pay_status']);
        $this->assertNull($this->orderOf($round, 'มิ้นท์')['promptpay_payload']);
        $this->assertSame('unpaid', $this->orderOf($round, 'แบงค์')['pay_status']);
        $this->assertSame(['ชาเย็น'], $round['billing']['unpriced']);

        // ใส่ราคาที่ขาดทีหลัง ราคาเดิมยังอยู่
        $round = $this->bill($staff, $schedule, $roundId, [
            'prices' => [['name' => 'ชาเย็น', 'price' => 25]],
            'promptpay_id' => '0812345678',
        ])->json('data.food_round');
        $this->assertSame(110.0, (float) $this->orderOf($round, 'มิ้นท์')['amount']);
    }

    public function test_customer_claims_staff_confirms_and_a_later_price_rise_shows_the_shortfall(): void
    {
        [$mint, , , $staff, $roundId, $schedule] = $this->orderedRound();
        $prices = [
            ['name' => 'กะเพราหมูสับ ไข่ดาว', 'price' => 60],
            ['name' => 'ชาเย็น', 'price' => 25],
        ];
        $this->bill($staff, $schedule, $roundId, ['prices' => $prices, 'promptpay_id' => '0812345678']);

        $round = $this->actingAs($mint, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/my-order/paid")
            ->assertOk()
            ->json('data.food_round');
        $mintOrder = $this->orderOf($round, 'มิ้นท์');
        $this->assertSame('claimed', $mintOrder['pay_status']);

        // ลูกค้ายืนยันการจ่ายของตัวเองไม่ได้ — ต้องเป็นสตาฟ
        $this->actingAs($mint, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/orders/{$mintOrder['id']}/paid", ['paid' => true])
            ->assertForbidden();

        $round = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/orders/{$mintOrder['id']}/paid", ['paid' => true])
            ->assertOk()
            ->json('data.food_round');
        $this->assertSame('paid', $this->orderOf($round, 'มิ้นท์')['pay_status']);
        $this->assertSame(110.0, (float) $round['billing']['collected']);
        $this->assertSame(120.0, (float) $round['billing']['outstanding']);
        $this->assertSame(1, $round['billing']['paid_count']);

        // ชาเย็นขึ้นราคา → มิ้นท์ขาดอีก 10 บาท พร้อม QR ของส่วนต่าง
        $prices[1]['price'] = 30;
        $round = $this->bill($staff, $schedule, $roundId, ['prices' => $prices, 'promptpay_id' => '0812345678'])
            ->json('data.food_round');
        $mintOrder = $this->orderOf($round, 'มิ้นท์');
        $this->assertSame('short', $mintOrder['pay_status']);
        $this->assertSame(10.0, (float) $mintOrder['balance']);
        $this->assertSame(app(PromptPayService::class)->buildPayload('0812345678', 10), $mintOrder['promptpay_payload']);
    }

    public function test_sending_the_bill_announces_and_pings_only_people_who_owe(): void
    {
        $pushes = [];
        $this->mock(FcmService::class, function (MockInterface $m) use (&$pushes) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title) use (&$pushes) {
                $pushes[] = [(int) $userId, $title];
            });
        });
        [$mint, $bank, $ploy, $staff, $roundId, $schedule] = $this->orderedRound();

        $this->bill($staff, $schedule, $roundId, [
            'prices' => [
                ['name' => 'กะเพราหมูสับ ไข่ดาว', 'price' => 60],
                ['name' => 'ชาเย็น', 'price' => 25],
            ],
            'promptpay_id' => '0812345678',
            'payee_name' => 'พี่สตาฟ',
            'notify' => true,
        ])->assertOk();

        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)
            ->where('body', '💸 ยอดค่าอาหาร “มื้อเย็นขากลับ ร้านป้าแดง” รวม ฿230 — ดูยอดของตัวเองในการ์ด แล้วโอนให้พี่สตาฟผ่านพร้อมเพย์ได้เลยครับ')
            ->exists());
        $this->assertEqualsCanonicalizing([
            [$mint->id, '💸 ค่าอาหารของคุณ ฿110'],
            [$bank->id, '💸 ค่าอาหารของคุณ ฿120'],
        ], $pushes);
    }

    public function test_billing_rules(): void
    {
        [$mint, , , $staff, $roundId, $schedule] = $this->orderedRound();

        // ลูกค้าตั้งราคาไม่ได้
        $this->bill($mint, $schedule, $roundId, ['prices' => []])->assertForbidden();
        // พร้อมเพย์ผิดรูป
        $this->bill($staff, $schedule, $roundId, ['prices' => [], 'promptpay_id' => '12345'])->assertStatus(422);
        // ยังไม่ส่งยอด → แจ้งโอนไม่ได้
        $this->actingAs($mint, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/food-rounds/{$roundId}/my-order/paid")
            ->assertStatus(422);
        // ยังไม่ส่งยอด → การ์ดไม่มีก้อน billing
        $this->assertNull($this->order($mint, $schedule, $roundId, ['items' => [['name' => 'ชาเย็น']]])->json('data.food_round.billing'));
    }
}
