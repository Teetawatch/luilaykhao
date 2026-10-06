<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\ChatSupplyRequest;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ขอยา / ของจำเป็นจากสตาฟ ส่งถึงที่นั่ง
 */
class ChatSupplyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: int, 1: string, 2: string}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        // สร้างบทบาทก่อนคำขอแรก — Spatie แคชรายชื่อบทบาทไว้ตั้งแต่ครั้งแรกที่เช็กสิทธิ์
        Role::findOrCreate('staff');
        $this->pushes = [];
        $this->mock(FcmService::class, function (MockInterface $m) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title, $body) {
                $this->pushes[] = [(int) $userId, $title, $body];
            });
        });
    }

    private function makeSchedule(bool $onTrip = true): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปขอของ',
            'slug' => 'supply-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'น่าน',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1900,
            'status' => 'active',
        ]);
        $start = $onTrip ? now('Asia/Bangkok') : now('Asia/Bangkok')->addMonth();

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $start->toDateString(),
            'return_date' => $start->copy()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: ?string}>  $people  [ชื่อ, ที่นั่ง]
     * @return array{0: User, 1: Booking, 2: array<int, BookingPassenger>}
     */
    private function party(TripSchedule $schedule, array $people, array $booking = []): array
    {
        $owner = User::factory()->create();
        $b = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1900,
        ], $booking));

        $passengers = [];
        foreach ($people as [$name, $seat]) {
            $passengers[] = BookingPassenger::create(['booking_id' => $b->id, 'name' => $name, 'nickname' => explode(' ', $name)[0]]);
            if ($seat) {
                BookingSeat::create(['booking_id' => $b->id, 'schedule_id' => $schedule->id, 'seat_id' => $seat, 'passenger_name' => $name]);
            }
        }

        return [$owner, $b, $passengers];
    }

    private function staff(TripSchedule $schedule): User
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function url(TripSchedule $schedule, string $path = ''): string
    {
        return "/api/v1/schedules/{$schedule->id}/chat/supplies{$path}";
    }

    public function test_request_reaches_staff_with_seat_and_name_but_room_only_gets_a_count(): void
    {
        $schedule = $this->makeSchedule();
        [$owner, , [$p1, $p2]] = $this->party($schedule, [['มานะ ขยัน', 'C3'], ['มานี มีตา', 'C4']]);
        [$other] = $this->party($schedule, [['ปิติ ใจดี', 'A1']]);
        $staff = $this->staff($schedule);

        // ขอแทนเพื่อนในกลุ่มที่ตัวเองจองให้
        $data = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($schedule), ['item' => 'motion_sickness', 'passenger_id' => $p2->id])
            ->assertCreated()->json('data');

        $this->assertSame('C4', $data['seat_label']);
        $this->assertSame('มานี', $data['for_name']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame([[$staff->id, '💊 C4 มานี ขอยาแก้เมารถ', 'เปิดแชทเพื่อดูคิวคำขอ']], $this->pushes);

        // ขอแทนคนนอกกลุ่มไม่ได้
        $this->actingAs($other, 'sanctum')
            ->postJson($this->url($schedule), ['item' => 'tissue', 'passenger_id' => $p1->id])
            ->assertStatus(422);

        // คนอื่นในห้องเห็นแค่ของตัวเอง ไม่เห็นคิวของใคร
        $this->actingAs($other, 'sanctum')->getJson($this->url($schedule))
            ->assertOk()->assertJsonPath('data.can_manage', false)->assertJsonCount(0, 'data.requests');
        $this->actingAs($owner, 'sanctum')->getJson($this->url($schedule))
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.passengers.0.seat_label', 'C3');

        // สตาฟเห็นคิวเต็ม + ห้องแชทได้แค่ตัวเลข (เฉพาะทีมงาน)
        $this->actingAs($staff, 'sanctum')->getJson($this->url($schedule))
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.requests.0.label', 'ยาแก้เมารถ');
        $this->actingAs($staff, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.supply_requests_pending', 1);
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.supply_requests_pending', 0);
    }

    public function test_repeat_tap_is_the_same_request_and_open_requests_are_capped(): void
    {
        $schedule = $this->makeSchedule();
        [$owner] = $this->party($schedule, [['มานะ ขยัน', 'C3']]);
        $this->staff($schedule);

        $a = $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => 'water'])->json('data.id');
        $b = $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => 'water'])->json('data.id');
        $this->assertSame($a, $b);
        $this->assertCount(1, $this->pushes);

        foreach (['tissue', 'plaster', 'inhaler', 'painkiller'] as $item) {
            $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => $item])->assertCreated();
        }
        $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => 'sick_bag'])->assertStatus(422);
    }

    public function test_other_needs_a_note_and_unknown_items_are_rejected(): void
    {
        $schedule = $this->makeSchedule();
        [$owner] = $this->party($schedule, [['มานะ ขยัน', null]]);

        $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => 'other'])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => 'beer'])->assertStatus(422);
        $data = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($schedule), ['item' => 'other', 'note' => 'ขอผ้าห่ม'])
            ->assertCreated()->json('data');
        $this->assertNull($data['seat_label'], 'ไม่มีที่นั่ง (เช่น จอยทริป) ก็ขอได้');
        $this->assertSame('ขอผ้าห่ม', $data['note']);
    }

    public function test_staff_deliver_or_decline_and_requester_hears_about_a_decline(): void
    {
        $schedule = $this->makeSchedule();
        [$owner] = $this->party($schedule, [['มานะ ขยัน', 'C3']]);
        $staff = $this->staff($schedule);
        $a = $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => 'motion_sickness'])->json('data.id');
        $b = $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => 'painkiller'])->json('data.id');

        $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule, "/{$a}/deliver"))->assertForbidden();

        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule, "/{$a}/deliver"))
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule, "/{$b}/decline"), ['note' => 'หมดแล้ว แวะร้านยาจุดหน้า'])
            ->assertOk()->assertJsonPath('data.status', 'declined');

        $toOwner = array_values(array_filter($this->pushes, fn ($p) => $p[0] === $owner->id));
        $this->assertSame([[$owner->id, '😔 ตอนนี้ทีมงานไม่มียาแก้ปวด / ลดไข้', 'หมดแล้ว แวะร้านยาจุดหน้า']], $toOwner);

        // ที่รอก่อน ตามด้วยที่จัดการแล้ว
        $queue = $this->actingAs($staff, 'sanctum')->getJson($this->url($schedule))->json('data');
        $this->assertSame(0, $queue['pending']);
        $this->assertEqualsCanonicalizing(['delivered', 'declined'], array_column($queue['requests'], 'status'));

        // จัดการแล้วยกเลิกไม่ได้
        $this->actingAs($owner, 'sanctum')->deleteJson($this->url($schedule, "/{$a}"))->assertStatus(422);
    }

    public function test_requester_can_cancel_a_pending_request(): void
    {
        $schedule = $this->makeSchedule();
        [$owner] = $this->party($schedule, [['มานะ ขยัน', 'C3']]);
        [$other] = $this->party($schedule, [['ปิติ ใจดี', 'A1']]);
        $id = $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['item' => 'tissue'])->json('data.id');

        $this->actingAs($other, 'sanctum')->deleteJson($this->url($schedule, "/{$id}"))->assertForbidden();
        $this->actingAs($owner, 'sanctum')->deleteJson($this->url($schedule, "/{$id}"))->assertOk();
        $this->assertSame(0, ChatSupplyRequest::count());
    }

    public function test_linked_companion_asks_for_themself_and_seats_on_multi_van_rounds_name_the_van(): void
    {
        $schedule = $this->makeSchedule();
        [, $booking, [, $p2]] = $this->party($schedule, [['มานะ ขยัน', 'B1'], ['มานี มีตา', 'B2']], ['vehicle_option_label' => 'คันที่ 2']);
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'passenger_id' => $p2->id,
            'role' => 'member',
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($friend, 'sanctum')->postJson($this->url($schedule), ['item' => 'sick_bag'])
            ->assertCreated()
            ->assertJsonPath('data.seat_label', 'คันที่ 2 · B2')
            ->assertJsonPath('data.for_name', 'มานี');
    }

    public function test_only_travellers_during_the_trip(): void
    {
        $later = $this->makeSchedule(onTrip: false);
        [$owner] = $this->party($later, [['มานะ ขยัน', 'C3']]);
        $this->actingAs($owner, 'sanctum')->postJson($this->url($later), ['item' => 'water'])->assertStatus(422);

        $schedule = $this->makeSchedule();
        $staff = $this->staff($schedule);
        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule), ['item' => 'water'])->assertForbidden();
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson($this->url($schedule), ['item' => 'water'])->assertForbidden();
    }
}
