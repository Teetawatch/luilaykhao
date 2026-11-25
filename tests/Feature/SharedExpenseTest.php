<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\TripSharedExpense;
use App\Models\TripSharedSettlement;
use App\Models\User;
use App\Services\PromptPayService;
use App\Services\SharedExpenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * หารค่าใช้จ่ายกับเพื่อนร่วมทริป — ยอดเป็นสตางค์ คิดระหว่างคู่ เห็นเฉพาะที่เกี่ยวข้อง
 */
class SharedExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ระหว่างทริป: รอบออก 5 ธ.ค. กลับ 6 ธ.ค.
        $this->travelTo(Carbon::parse('2026-12-05 09:00', 'Asia/Bangkok'));
    }

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปหารเงิน',
            'slug' => 'split-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'น่าน',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1900,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => '2026-12-05',
            'return_date' => '2026-12-06',
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    /** @return array{0: User, 1: array<int, BookingPassenger>, 2: Booking} */
    private function party(TripSchedule $schedule, array $names, string $status = 'confirmed'): array
    {
        $owner = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 1900,
        ]);

        return [$owner, array_map(fn ($n) => BookingPassenger::create(['booking_id' => $booking->id, 'name' => $n]), $names), $booking];
    }

    private function url(TripSchedule $schedule, string $path = ''): string
    {
        return "/api/v1/schedules/{$schedule->id}/shared-expenses{$path}";
    }

    private function add(User $user, TripSchedule $schedule, array $payload)
    {
        return $this->actingAs($user, 'sanctum')->postJson($this->url($schedule), array_merge([
            'title' => 'ค่าเรือ',
            'category' => 'transport',
            'split_mode' => 'equal',
        ], $payload));
    }

    private function balance(array $data, int $from, int $to): ?float
    {
        foreach ($data['balances'] as $b) {
            if ($b['from']['passenger_id'] === $from && $b['to']['passenger_id'] === $to) {
                return (float) $b['amount'];
            }
        }

        return null;
    }

    public function test_equal_split_gives_the_leftover_satang_to_the_payer_and_owes_pairwise(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ ใจดี']);
        [$bob, [$b]] = $this->party($schedule, ['บ๊อบ ขยัน']);
        [, [$c]] = $this->party($schedule, ['ชาลี มีสุข']);

        $data = $this->add($alice, $schedule, [
            'amount' => 100,
            'payer_passenger_id' => $a->id,
            'passenger_ids' => [$b->id, $a->id, $c->id],
        ])->assertCreated()->json('data');

        $expense = $data['expenses'][0];
        $shares = collect($expense['shares'])->pluck('amount', 'passenger_id');
        $this->assertSame(33.34, (float) $shares[$a->id]);
        $this->assertSame(33.33, (float) $shares[$b->id]);
        $this->assertSame(33.33, (float) $shares[$c->id]);
        $this->assertSame(100.0, round($shares->sum(), 2));

        $this->assertSame(33.33, $this->balance($data, $b->id, $a->id));
        $this->assertSame(33.33, $this->balance($data, $c->id, $a->id));
        $this->assertSame(66.66, (float) $data['totals']['owed_to_me']);
        $this->assertSame(0.0, (float) $data['totals']['i_owe']);
        $this->assertSame(33.34, (float) $data['totals']['my_spend']);

        // บ๊อบเห็นแค่ยอดของตัวเอง ไม่เห็นว่าชาลีติดอลิซเท่าไร
        $bobView = $this->actingAs($bob, 'sanctum')->getJson($this->url($schedule))->assertOk()->json('data');
        $this->assertSame(33.33, $this->balance($bobView, $b->id, $a->id));
        $this->assertNull($this->balance($bobView, $c->id, $a->id));
        $this->assertSame(33.33, (float) $bobView['totals']['i_owe']);
    }

    public function test_debts_are_not_chained_across_people_who_never_split_together(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [$bob, [$b]] = $this->party($schedule, ['บ๊อบ']);
        [$carol, [$c]] = $this->party($schedule, ['แคโรล']);

        // อลิซติดบ๊อบ 100 · บ๊อบติดแคโรล 100
        $this->add($bob, $schedule, ['amount' => 100, 'payer_passenger_id' => $b->id, 'passenger_ids' => [$a->id]])->assertCreated();
        $this->add($carol, $schedule, ['amount' => 100, 'payer_passenger_id' => $c->id, 'passenger_ids' => [$b->id]])->assertCreated();

        $aliceView = $this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertSame(100.0, $this->balance($aliceView, $a->id, $b->id));
        $this->assertNull($this->balance($aliceView, $a->id, $c->id), 'อลิซไม่ควรถูกจับคู่ให้โอนให้แคโรลที่ไม่เคยหารกัน');
        $this->assertCount(1, $aliceView['expenses']);
    }

    public function test_friend_without_the_app_is_managed_by_the_person_who_booked_for_them(): void
    {
        $schedule = $this->makeSchedule();
        [$owner, [$me, $friend]] = $this->party($schedule, ['เจ้าของ', 'เพื่อนไม่มีแอป']);
        [$dave, [$d]] = $this->party($schedule, ['เดฟ']);

        // เดฟออกค่าข้าว หารสามคน — เพื่อนที่ไม่มีแอปติดเดฟ คนจองเห็นแทน
        $this->add($dave, $schedule, [
            'title' => 'ค่าข้าวเย็น',
            'amount' => 300,
            'payer_passenger_id' => $d->id,
            'passenger_ids' => [$me->id, $friend->id, $d->id],
        ])->assertCreated();

        $view = $this->actingAs($owner, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertSame(100.0, $this->balance($view, $me->id, $d->id));
        $this->assertSame(100.0, $this->balance($view, $friend->id, $d->id));
        $this->assertSame(200.0, (float) $view['totals']['i_owe']);
        $this->assertEqualsCanonicalizing([$me->id, $friend->id], $view['me']['passenger_ids']);

        // คนจองบันทึกว่าเพื่อนจ่ายเงินสดให้เดฟแล้ว
        $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule, '/settlements'), [
            'from_passenger_id' => $friend->id,
            'to_passenger_id' => $d->id,
            'amount' => 100,
            'method' => 'cash',
        ])->assertCreated();

        $view = $this->actingAs($owner, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertNull($this->balance($view, $friend->id, $d->id));
        $this->assertSame(100.0, (float) $view['totals']['i_owe']);

        // คนที่ตัวเองดูแลทั้งสองฝั่ง = ยอดภายในกลุ่ม ไม่นับรวม "ต้องจ่าย"
        $this->add($owner, $schedule, ['amount' => 50, 'payer_passenger_id' => $me->id, 'passenger_ids' => [$friend->id]])->assertCreated();
        $view = $this->actingAs($owner, 'sanctum')->getJson($this->url($schedule))->json('data');
        $internal = collect($view['balances'])->firstWhere('internal', true);
        $this->assertSame($friend->id, $internal['from']['passenger_id']);
        $this->assertSame(100.0, (float) $view['totals']['i_owe']);
        $this->assertSame(0.0, (float) $view['totals']['owed_to_me']);
    }

    public function test_linked_member_manages_their_own_seat(): void
    {
        $schedule = $this->makeSchedule();
        [$owner, [$me, $friendSeat], $booking] = $this->party($schedule, ['เจ้าของ', 'เพื่อนมีแอป']);
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'passenger_id' => $friendSeat->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->add($owner, $schedule, ['amount' => 200, 'payer_passenger_id' => $me->id, 'passenger_ids' => [$me->id, $friendSeat->id]])->assertCreated();

        $friendView = $this->actingAs($friend, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertSame([$friendSeat->id], $friendView['me']['passenger_ids']);
        $this->assertSame(100.0, (float) $friendView['totals']['i_owe']);

        $ownerView = $this->actingAs($owner, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertSame(100.0, (float) $ownerView['totals']['owed_to_me']);
        $this->assertFalse(collect($ownerView['balances'])->first()['internal']);
    }

    public function test_promptpay_qr_goes_only_to_the_person_who_owes(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [$bob, [$b]] = $this->party($schedule, ['บ๊อบ']);

        $this->actingAs($alice, 'sanctum')->putJson('/api/v1/me/receive-promptpay', ['promptpay_id' => '081-234-5678'])
            ->assertOk()
            ->assertJsonPath('data.promptpay_id', '0812345678');
        $this->assertNotSame('0812345678', DB::table('users')->where('id', $alice->id)->value('receive_promptpay_id'), 'ต้องเข้ารหัสในฐานข้อมูล');
        $this->assertArrayNotHasKey('receive_promptpay_id', $alice->fresh()->toArray());

        $this->add($alice, $schedule, ['amount' => 500, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id, $b->id]])->assertCreated();

        $bobRow = collect($this->actingAs($bob, 'sanctum')->getJson($this->url($schedule))->json('data.balances'))->first();
        $this->assertSame(app(PromptPayService::class)->buildPayload('0812345678', 250), $bobRow['promptpay']['payload']);
        $this->assertSame('0812345678', $bobRow['promptpay']['id']);

        $aliceRow = collect($this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->json('data.balances'))->first();
        $this->assertNull($aliceRow['promptpay'], 'เจ้าหนี้ไม่ต้องเห็น QR ของตัวเอง');
        $this->assertSame('0812345678', $this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->json('data.me.promptpay_id'));

        $this->actingAs($alice, 'sanctum')->putJson('/api/v1/me/receive-promptpay', ['promptpay_id' => '12345'])->assertStatus(422);
        $this->actingAs($alice, 'sanctum')->putJson('/api/v1/me/receive-promptpay', ['promptpay_id' => null])->assertOk();
        $bobRow = collect($this->actingAs($bob, 'sanctum')->getJson($this->url($schedule))->json('data.balances'))->first();
        $this->assertNull($bobRow['promptpay']);
    }

    public function test_custom_split_must_add_up_to_the_total(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [, [$b]] = $this->party($schedule, ['บ๊อบ']);

        $this->add($alice, $schedule, [
            'amount' => 1000,
            'payer_passenger_id' => $a->id,
            'split_mode' => 'custom',
            'shares' => [['passenger_id' => $a->id, 'amount' => 400], ['passenger_id' => $b->id, 'amount' => 500]],
        ])->assertStatus(422)->assertJsonPath('message', 'ยอดของแต่ละคนรวมแล้วยังขาดอีก ฿100');

        $this->add($alice, $schedule, [
            'amount' => 1000,
            'payer_passenger_id' => $a->id,
            'split_mode' => 'custom',
            'shares' => [['passenger_id' => $a->id, 'amount' => 600], ['passenger_id' => $b->id, 'amount' => 500]],
        ])->assertStatus(422)->assertJsonPath('message', 'ยอดของแต่ละคนรวมแล้วเกินอีก ฿100');

        $data = $this->add($alice, $schedule, [
            'amount' => 1000,
            'payer_passenger_id' => $a->id,
            'split_mode' => 'custom',
            'shares' => [['passenger_id' => $a->id, 'amount' => 350.5], ['passenger_id' => $b->id, 'amount' => 649.5]],
        ])->assertCreated()->json('data');
        $this->assertSame(649.5, $this->balance($data, $b->id, $a->id));
        $this->assertSame('custom', $data['expenses'][0]['split_mode']);
    }

    public function test_expense_must_involve_the_recorder_and_someone_else(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [, [$b]] = $this->party($schedule, ['บ๊อบ']);
        [, [$c]] = $this->party($schedule, ['ชาลี']);

        $this->add($alice, $schedule, ['amount' => 100, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id]])
            ->assertStatus(422)->assertJsonPath('message', 'เลือกเพื่อนที่ร่วมหารอย่างน้อย 1 คน');

        $this->add($alice, $schedule, ['amount' => 100, 'payer_passenger_id' => $b->id, 'passenger_ids' => [$c->id]])
            ->assertStatus(422)->assertJsonPath('message', 'บันทึกได้เฉพาะรายการที่คุณออกเงินหรือร่วมหารด้วย');

        $other = $this->makeSchedule();
        [, [$outsider]] = $this->party($other, ['คนรอบอื่น']);
        $this->add($alice, $schedule, ['amount' => 100, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$outsider->id]])
            ->assertStatus(422)->assertJsonPath('message', 'มีคนที่ไม่ได้อยู่ในรายชื่อทริปนี้');

        // จดว่าเพื่อนออกให้ก่อน (ตัวเองร่วมหาร) ได้
        $this->add($alice, $schedule, ['amount' => 100, 'payer_passenger_id' => $b->id, 'passenger_ids' => [$a->id, $b->id]])->assertCreated();
    }

    public function test_only_travellers_of_the_round_can_open_it(): void
    {
        $schedule = $this->makeSchedule();
        $this->party($schedule, ['อลิซ']);
        [$cancelled] = $this->party($schedule, ['ยกเลิกแล้ว'], 'cancelled');
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')->getJson($this->url($schedule))->assertStatus(403);
        $this->actingAs($cancelled, 'sanctum')->getJson($this->url($schedule))->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($schedule))->assertStatus(401);
    }

    public function test_settlement_cannot_exceed_what_is_owed_and_can_be_undone(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [$bob, [$b]] = $this->party($schedule, ['บ๊อบ']);
        [$carol, [$c]] = $this->party($schedule, ['แคโรล']);

        $this->add($alice, $schedule, ['amount' => 300, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id, $b->id, $c->id]])->assertCreated();

        $settle = fn (User $u, int $from, int $to, $amount) => $this->actingAs($u, 'sanctum')->postJson($this->url($schedule, '/settlements'), [
            'from_passenger_id' => $from, 'to_passenger_id' => $to, 'amount' => $amount,
        ]);

        $settle($bob, $b->id, $a->id, 150)->assertStatus(422)->assertJsonPath('message', 'ยอดเกินที่ค้างอยู่ (ค้าง ฿100)');
        $settle($bob, $a->id, $b->id, 50)->assertStatus(422)->assertJsonPath('message', 'ไม่มียอดค้างระหว่างสองคนนี้แล้ว');
        $settle($carol, $b->id, $a->id, 10)->assertStatus(422)->assertJsonPath('message', 'บันทึกการจ่ายคืนได้เฉพาะยอดที่คุณเกี่ยวข้อง');

        $data = $settle($bob, $b->id, $a->id, 40)->assertCreated()->json('data');
        $this->assertSame(60.0, $this->balance($data, $b->id, $a->id));
        $data = $settle($bob, $b->id, $a->id, 60)->assertCreated()->json('data');
        $this->assertNull($this->balance($data, $b->id, $a->id));
        $settle($bob, $b->id, $a->id, 1)->assertStatus(422);

        // เจ้าหนี้กดยกเลิกรายการที่ไม่ได้รับจริง → ยอดค้างกลับมา
        $settlementId = TripSharedSettlement::where('amount', 60)->value('id');
        $data = $this->actingAs($alice, 'sanctum')->deleteJson($this->url($schedule, "/settlements/{$settlementId}"))->assertOk()->json('data');
        $this->assertSame(60.0, $this->balance($data, $b->id, $a->id));

        // คนที่ไม่เกี่ยวข้องยกเลิกไม่ได้
        $otherId = TripSharedSettlement::value('id');
        $this->actingAs($carol, 'sanctum')->deleteJson($this->url($schedule, "/settlements/{$otherId}"))->assertStatus(422);
        $this->assertSame(1, TripSharedSettlement::count());
    }

    public function test_editing_after_repayment_flips_the_debt_back(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [$bob, [$b]] = $this->party($schedule, ['บ๊อบ']);

        $id = $this->add($alice, $schedule, ['amount' => 200, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id, $b->id]])->json('data.expenses.0.id');
        $this->actingAs($bob, 'sanctum')->postJson($this->url($schedule, '/settlements'), [
            'from_passenger_id' => $b->id, 'to_passenger_id' => $a->id, 'amount' => 100,
        ])->assertCreated();

        // ยอดจริงคือ 100 ไม่ใช่ 200 — บ๊อบโอนเกินไป 50 อลิซต้องคืน
        $data = $this->actingAs($alice, 'sanctum')->putJson($this->url($schedule, "/{$id}"), [
            'title' => 'ค่าเรือ', 'amount' => 100, 'payer_passenger_id' => $a->id, 'split_mode' => 'equal', 'passenger_ids' => [$a->id, $b->id],
        ])->assertOk()->json('data');
        $this->assertSame(50.0, $this->balance($data, $a->id, $b->id));
        $this->assertSame(50.0, (float) $data['totals']['i_owe']);
    }

    public function test_only_the_recorder_or_payer_can_edit_or_delete(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [$bob, [$b]] = $this->party($schedule, ['บ๊อบ']);
        [$carol, [$c]] = $this->party($schedule, ['แคโรล']);

        // แคโรลจดว่าอลิซออกให้ก่อน
        $data = $this->add($carol, $schedule, ['amount' => 300, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id, $b->id, $c->id]])->json('data');
        $id = $data['expenses'][0]['id'];
        $this->assertTrue($data['expenses'][0]['can_edit']);

        $bobView = $this->actingAs($bob, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertFalse($bobView['expenses'][0]['can_edit']);
        $this->actingAs($bob, 'sanctum')->deleteJson($this->url($schedule, "/{$id}"))
            ->assertStatus(422)->assertJsonPath('message', 'ลบได้เฉพาะคนที่บันทึกหรือคนที่ออกเงิน');
        $this->actingAs($bob, 'sanctum')->putJson($this->url($schedule, "/{$id}"), [
            'title' => 'แก้', 'amount' => 1, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$b->id],
        ])->assertStatus(422);

        $aliceView = $this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertTrue($aliceView['expenses'][0]['can_edit']);

        $this->actingAs($alice, 'sanctum')->deleteJson($this->url($schedule, "/{$id}"))->assertOk();
        $this->assertSame(0, TripSharedExpense::count());
        $this->assertSame(0, DB::table('trip_shared_expense_shares')->count());
        $this->actingAs($alice, 'sanctum')->deleteJson($this->url($schedule, "/{$id}"))
            ->assertStatus(422)->assertJsonPath('message', 'ไม่พบรายการนี้ อาจถูกลบไปแล้ว');
    }

    public function test_window_opens_with_the_seat_handover_cutoff_and_closes_thirty_days_after_return(): void
    {
        // รถออก 4 ธ.ค. 22:00 (คืนก่อนวันทริป) → เปิดบันทึก 19:00
        $schedule = $this->makeSchedule(['departs_at' => '2026-12-04 22:00:00']);
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [, [$b]] = $this->party($schedule, ['บ๊อบ']);
        $payload = ['amount' => 100, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id, $b->id]];

        $this->travelTo(Carbon::parse('2026-12-04 18:59', 'Asia/Bangkok'));
        $view = $this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->assertOk()->json('data.window');
        $this->assertSame('upcoming', $view['status']);
        $this->assertSame('4 ธ.ค. 2569 19:00 น.', $view['opens_label']);
        $this->add($alice, $schedule, $payload)->assertStatus(422)->assertJsonPath('message', 'เริ่มบันทึกได้ตั้งแต่ 4 ธ.ค. 2569 19:00 น.');

        $this->travelTo(Carbon::parse('2026-12-04 19:00', 'Asia/Bangkok'));
        $this->add($alice, $schedule, $payload)->assertCreated();

        $this->travelTo(Carbon::parse('2027-01-05 23:59', 'Asia/Bangkok'));
        $this->assertSame('open', $this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->json('data.window.status'));

        $this->travelTo(Carbon::parse('2027-01-06 00:01', 'Asia/Bangkok'));
        $data = $this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->assertOk()->json('data');
        $this->assertSame('closed', $data['window']['status']);
        $this->assertFalse($data['expenses'][0]['can_edit']);
        $this->assertFalse($data['balances'][0]['can_settle']);
        $this->add($alice, $schedule, $payload)->assertStatus(422)->assertJsonPath('message', 'ทริปนี้ปิดบันทึกค่าใช้จ่ายแล้ว (หลังกลับ 30 วัน)');
    }

    public function test_completed_trip_still_counts_as_travelling(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ'], 'completed');
        [, [$b]] = $this->party($schedule, ['บ๊อบ'], 'completed');

        $this->add($alice, $schedule, ['amount' => 100, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id, $b->id]])->assertCreated();
    }

    public function test_not_going_traveller_is_hidden_from_the_picker_but_kept_in_old_records(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a, $sick]] = $this->party($schedule, ['อลิซ', 'ป่วยไม่ไป']);

        $this->add($alice, $schedule, ['amount' => 100, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id, $sick->id]])->assertCreated();
        $sick->forceFill(['not_going_at' => now()])->save();

        $data = $this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertNotContains($sick->id, collect($data['people'])->pluck('passenger_id')->all());
        $this->assertContains($sick->id, collect($data['expenses'][0]['shares'])->pluck('passenger_id')->all());
    }

    public function test_people_list_puts_my_group_first(): void
    {
        $schedule = $this->makeSchedule();
        [, [$x]] = $this->party($schedule, ['คนอื่น']);
        [$alice, [$a, $friend]] = $this->party($schedule, ['อลิซ', 'เพื่อนอลิซ']);

        $people = $this->actingAs($alice, 'sanctum')->getJson($this->url($schedule))->json('data.people');
        $this->assertSame([$a->id, $friend->id, $x->id], collect($people)->pluck('passenger_id')->all());
        $this->assertTrue($people[0]['is_mine']);
        $this->assertFalse($people[2]['same_booking']);
    }

    public function test_involved_app_users_are_notified_but_not_the_recorder(): void
    {
        $schedule = $this->makeSchedule();
        [$alice, [$a]] = $this->party($schedule, ['อลิซ']);
        [$bob, [$b]] = $this->party($schedule, ['บ๊อบ']);
        [$carol] = $this->party($schedule, ['แคโรล']);

        $this->add($alice, $schedule, ['title' => 'ค่าเรือหางยาว', 'amount' => 300, 'payer_passenger_id' => $a->id, 'passenger_ids' => [$a->id, $b->id]])->assertCreated();

        $note = SmartNotification::where('user_id', $bob->id)->where('type', 'trip_expense_added')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('ค่าเรือหางยาว', $note->title);
        $this->assertStringContainsString('ส่วนของคุณ ฿150 จากยอด ฿300', $note->body);
        $this->assertSame((string) $schedule->id, $note->data['schedule_id']);
        $this->assertSame(0, SmartNotification::where('user_id', $alice->id)->count());
        $this->assertSame(0, SmartNotification::where('user_id', $carol->id)->count());

        $this->actingAs($bob, 'sanctum')->postJson($this->url($schedule, '/settlements'), [
            'from_passenger_id' => $b->id, 'to_passenger_id' => $a->id, 'amount' => 150,
        ])->assertCreated();
        $settled = SmartNotification::where('user_id', $alice->id)->where('type', 'trip_expense_settled')->first();
        $this->assertNotNull($settled);
        $this->assertStringContainsString('บ๊อบ จ่ายคืน อลิซ ฿150 แล้ว', $settled->title);
    }

    public function test_equal_shares_split_in_satang_with_payer_first(): void
    {
        $service = app(SharedExpenseService::class);

        $this->assertSame([7 => 3334, 3 => 3333, 9 => 3333], $service->equalShares([3, 7, 9], 10000, 7));
        // คนออกเงินไม่ได้ร่วมหาร — เศษไล่ตามลำดับที่เลือก
        $this->assertSame([3 => 34, 9 => 33, 11 => 33], $service->equalShares([3, 9, 11, 3], 100, 99));
        $this->assertSame([], $service->equalShares([], 100, 1));
    }
}
