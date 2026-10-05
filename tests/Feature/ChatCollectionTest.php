<?php

namespace Tests\Feature;

use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatCollection;
use App\Models\ChatMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\PromptPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * เก็บเงินหน้างาน — ค่าใช้จ่ายนอกแพ็กเกจ ยอดต่อคน QR ต่อบัญชี สตาฟติ๊กจ่าย
 */
class ChatCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendChatPushJob::class]);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปเก็บเงิน',
            'slug' => 'collect-trip-'.uniqid(),
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
            'departure_date' => now()->toDateString(),
            'return_date' => now()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /** @return array{0: User, 1: array<int, BookingPassenger>} */
    private function party(TripSchedule $schedule, array $names): array
    {
        $owner = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1900,
        ]);

        return [$owner, array_map(fn ($n) => BookingPassenger::create(['booking_id' => $booking->id, 'name' => $n]), $names)];
    }

    private function staff(TripSchedule $schedule): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function url(TripSchedule $schedule, string $path = ''): string
    {
        return "/api/v1/schedules/{$schedule->id}/chat/collections{$path}";
    }

    private function open(User $staff, TripSchedule $schedule, array $payload = [])
    {
        return $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule), array_merge([
            'title' => 'ค่าลูกหาบ',
            'amount' => 200,
            'promptpay_id' => '081-234-5678',
            'payee_name' => 'พี่สตาฟ',
        ], $payload));
    }

    public function test_collecting_from_everyone_gives_each_account_its_group_total_and_qr(): void
    {
        $schedule = $this->makeSchedule();
        [$family] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staff($schedule);

        $data = $this->open($staff, $schedule)->assertCreated()->json('data');

        $this->assertSame('💰 เก็บเงิน: ค่าลูกหาบ — คนละ ฿200 (ทุกคน) ดูยอดของคุณและสแกนจ่ายในการ์ดนี้', $data['body']);
        $c = $data['collection'];
        $this->assertSame(3, $c['due_count']);
        $this->assertSame(600.0, (float) $c['total']);
        $pp = app(PromptPayService::class);
        $this->assertSame($pp->buildPayload('0812345678', 400), $c['payloads'][(string) $family->id]);
        $this->assertSame($pp->buildPayload('0812345678', 200), $c['payloads'][(string) $solo->id]);
        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->callToAction === true);
    }

    public function test_collecting_from_chosen_people_only(): void
    {
        $schedule = $this->makeSchedule();
        [, [$a]] = $this->party($schedule, ['John Smith']);
        [$thai] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staff($schedule);

        $c = $this->open($staff, $schedule, ['title' => 'ค่าเข้าอุทยาน (ต่างชาติ)', 'amount' => 300, 'passenger_ids' => [$a->id]])
            ->assertCreated()
            ->json('data.collection');

        $this->assertSame(1, $c['due_count']);
        $this->assertArrayNotHasKey((string) $thai->id, (array) $c['payloads']);
        $this->assertStringContainsString('(1 คน)', ChatMessage::latest('id')->first()->body);
    }

    public function test_claim_then_staff_confirm_then_close_announces_the_total(): void
    {
        $schedule = $this->makeSchedule();
        [$family, [$p1, $p2]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo, [$p3]] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staff($schedule);
        $id = $this->open($staff, $schedule)->json('data.collection.id');

        $c = $this->actingAs($family, 'sanctum')->postJson($this->url($schedule, "/{$id}/claim"))
            ->assertOk()->json('data.collection');
        $this->assertSame(['claimed', 'claimed', 'unpaid'], array_column($c['dues'], 'status'));

        // ลูกทริปยืนยันจ่ายเองไม่ได้
        $this->actingAs($family, 'sanctum')
            ->postJson($this->url($schedule, "/{$id}/paid"), ['passenger_ids' => [$p1->id], 'paid' => true])
            ->assertForbidden();

        $c = $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule, "/{$id}/paid"), ['passenger_ids' => [$p1->id, $p2->id], 'paid' => true])
            ->assertOk()->json('data.collection');
        $this->assertSame(400.0, (float) $c['collected']);
        $this->assertSame(200.0, (float) $c['outstanding']);
        $this->assertArrayNotHasKey((string) $family->id, (array) $c['payloads'], 'จ่ายครบแล้วไม่ต้องมี QR');

        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule, "/{$id}/close"))->assertOk()
            ->assertJsonPath('data.collection.is_closed', true);
        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)
            ->where('body', '💰 ปิดยอด “ค่าลูกหาบ” — เก็บได้ ฿400 จาก ฿600 (ยังค้าง 1 คน)')->exists());

        // ปิดยอดแล้วแจ้งโอนไม่ได้ แต่สตาฟยังรับเงินสดตามหลังได้
        $this->actingAs($solo, 'sanctum')->postJson($this->url($schedule, "/{$id}/claim"))->assertStatus(422);
        $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule, "/{$id}/paid"), ['passenger_ids' => [$p3->id], 'paid' => true])
            ->assertOk()
            ->assertJsonPath('data.collection.unpaid_count', 0);
    }

    public function test_changing_who_pays_keeps_paid_people_safe(): void
    {
        $schedule = $this->makeSchedule();
        [, [$a]] = $this->party($schedule, ['เอ เอ']);
        [, [$b]] = $this->party($schedule, ['บี บี']);
        [, [$c]] = $this->party($schedule, ['ซี ซี']);
        $staff = $this->staff($schedule);
        $id = $this->open($staff, $schedule, ['passenger_ids' => [$a->id, $b->id]])->json('data.collection.id');
        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule, "/{$id}/paid"), ['passenger_ids' => [$a->id], 'paid' => true]);

        $this->actingAs($staff, 'sanctum')
            ->putJson($this->url($schedule, "/{$id}/payers"), ['passenger_ids' => [$b->id, $c->id]])
            ->assertStatus(422);

        $data = $this->actingAs($staff, 'sanctum')
            ->putJson($this->url($schedule, "/{$id}/payers"), ['passenger_ids' => [$a->id, $c->id]])
            ->assertOk()->json('data.collection');
        $this->assertSame(['เอ', 'ซี'], array_column($data['dues'], 'name'));
        $this->assertSame(['paid', 'unpaid'], array_column($data['dues'], 'status'));
    }

    public function test_rules(): void
    {
        $schedule = $this->makeSchedule();
        [$owner] = $this->party($schedule, ['เอ เอ']);
        $staff = $this->staff($schedule);

        $this->open($owner, $schedule)->assertForbidden();
        $this->open($staff, $schedule, ['promptpay_id' => '12345'])->assertStatus(422);
        $this->open($staff, $schedule, ['amount' => 0])->assertStatus(422);
        $this->open($staff, $schedule, ['passenger_ids' => [999999]])->assertStatus(422);

        // ไม่ใส่พร้อมเพย์ = เก็บเงินสด ไม่มี QR
        $c = $this->open($staff, $schedule, ['promptpay_id' => null])->assertCreated()->json('data.collection');
        $this->assertSame([], (array) $c['payloads']);

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/roster")->assertForbidden();
        $this->actingAs($staff, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/roster")
            ->assertOk()->assertJsonPath('data.passengers.0.full_name', 'เอ เอ');
        $this->assertSame(1, ChatCollection::count());
    }
}
