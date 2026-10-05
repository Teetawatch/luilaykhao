<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ScheduleRoom;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * จัดห้องพักของรอบ — ลูกทริปเห็นห้องตัวเอง ทีมงานจัด/จัดอัตโนมัติ/ประกาศ
 */
class ScheduleRoomTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: int, 1: string, 2: string}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pushes = [];
        $this->mock(FcmService::class, function (MockInterface $m) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title, $body) {
                $this->pushes[] = [(int) $userId, $title, $body];
            });
        });
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปค้างคืน',
            'slug' => 'room-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'น่าน',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1900,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'return_date' => now()->addWeek()->addDay()->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $people  [คำนำหน้า, ชื่อ]
     * @return array{0: User, 1: array<int, BookingPassenger>}
     */
    private function party(TripSchedule $schedule, array $people): array
    {
        $owner = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1900 * count($people),
        ]);

        return [$owner, array_map(fn ($p) => BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => $p[0],
            'name' => $p[1],
        ]), $people)];
    }

    private function staff(TripSchedule $schedule): User
    {
        // สร้างบทบาทพร้อมกันตั้งแต่แรก — Spatie แคชรายชื่อบทบาทไว้หลังใช้ครั้งแรก
        Role::findOrCreate('admin');
        Role::findOrCreate('staff');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function url(TripSchedule $schedule, string $path = ''): string
    {
        return "/api/v1/schedules/{$schedule->id}/rooms{$path}";
    }

    /** ชื่อผู้พักของแต่ละห้อง เรียงตามห้อง */
    private function layout(array $data): array
    {
        return collect($data['rooms'])
            ->mapWithKeys(fn ($r) => [$r['name'] => array_column($r['guests'], 'name')])
            ->all();
    }

    public function test_auto_assign_keeps_bookings_together_and_never_mixes_stranger_genders(): void
    {
        $schedule = $this->makeSchedule();
        $this->party($schedule, [['นาย', 'สมชาย ใจดี'], ['นาง', 'สมหญิง ใจดี']]);
        $this->party($schedule, [['นางสาว', 'มิ้นท์ หวาน']]);
        $this->party($schedule, [['นาย', 'แบงค์ ขยัน']]);
        $this->party($schedule, [['นางสาว', 'พลอย สวย']]);
        $this->party($schedule, [['Mr', 'John Smith']]);
        $staff = $this->staff($schedule);

        $data = $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule, '/auto'), ['room_size' => 2])
            ->assertOk()
            ->assertJsonPath('message', 'จัดให้แล้ว 3 ห้อง')
            ->json('data');

        $this->assertSame([
            'ห้อง 1' => ['สมชาย', 'สมหญิง'],
            'ห้อง 2' => ['มิ้นท์', 'พลอย'],
            'ห้อง 3' => ['แบงค์', 'John'],
        ], $this->layout($data));
        $this->assertSame([], $data['unassigned'][0]['passengers']);

        // กดซ้ำ — ทุกคนมีห้องแล้ว ไม่สร้างห้องเพิ่ม
        $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule, '/auto'), ['room_size' => 2])
            ->assertJsonPath('message', 'ทุกคนมีห้องแล้ว');
        $this->assertSame(3, ScheduleRoom::count());
    }

    public function test_big_groups_split_into_neighbouring_rooms_and_numbering_continues(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->staff($schedule);
        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule), ['name' => 'ห้อง 1']);
        $this->party($schedule, [['นาย', 'ก ก'], ['นาย', 'ข ข'], ['นาย', 'ค ค']]);

        $data = $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule, '/auto'), ['room_size' => 2])
            ->json('data');

        $this->assertSame([
            'ห้อง 1' => [],
            'ห้อง 2' => ['ก', 'ข'],
            'ห้อง 3' => ['ค'],
        ], $this->layout($data));
    }

    public function test_moving_someone_takes_them_out_of_their_old_room_in_the_same_stay_only(): void
    {
        $schedule = $this->makeSchedule();
        [, [$a, $b]] = $this->party($schedule, [['นาย', 'เอ เอ'], ['นาย', 'บี บี']]);
        $staff = $this->staff($schedule);

        $night1 = $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule), ['name' => 'ห้อง 101', 'stay_label' => 'คืนแรก', 'passenger_ids' => [$a->id, $b->id]])
            ->assertCreated()->json('data.rooms.0.id');
        $night2 = $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule), ['name' => 'ห้อง 201', 'stay_label' => 'คืนสอง', 'passenger_ids' => [$a->id]])
            ->json('data.rooms.1.id');
        $other = $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule), ['name' => 'ห้อง 102', 'stay_label' => 'คืนแรก'])
            ->json('data.rooms.2.id');

        $data = $this->actingAs($staff, 'sanctum')
            ->putJson($this->url($schedule, "/{$other}/guests"), ['passenger_ids' => [$a->id]])
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'ห้อง 101' => ['บี'],
            'ห้อง 201' => ['เอ'],  // คนละที่พัก ไม่ถูกแตะ
            'ห้อง 102' => ['เอ'],
        ], $this->layout($data));
        $this->assertSame(['คืนแรก', 'คืนสอง'], $data['stays']);
    }

    public function test_travellers_see_every_room_and_which_one_is_theirs_but_cannot_edit(): void
    {
        $schedule = $this->makeSchedule();
        [$owner, [$a]] = $this->party($schedule, [['นาย', 'เอ เอ']]);
        [, [$b]] = $this->party($schedule, [['นาย', 'บี บี']]);
        $staff = $this->staff($schedule);
        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule), ['name' => 'ห้อง 1', 'passenger_ids' => [$a->id, $b->id]]);

        $data = $this->actingAs($owner, 'sanctum')->getJson($this->url($schedule))->assertOk()->json('data');
        $this->assertCount(1, $data['my_room_ids']);
        $this->assertTrue($data['rooms'][0]['guests'][0]['is_mine']);
        $this->assertFalse($data['rooms'][0]['guests'][1]['is_mine']);
        $this->assertArrayNotHasKey('unassigned', $data, 'ลูกทริปไม่ต้องเห็นรายชื่อคนที่ยังไม่มีห้อง');
        $this->assertArrayNotHasKey('full_name', $data['rooms'][0]['guests'][0], 'ชื่อจริงเต็มให้เฉพาะทีมงาน');

        // ทีมงาน/แอดมินได้ชื่อเต็มไว้ส่งรายชื่อเข้าพักให้ที่พัก
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin, 'sanctum')
            ->getJson($this->url($schedule))
            ->assertOk()
            ->assertJsonPath('data.rooms.0.guests.0.full_name', 'เอ เอ');

        $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule), ['name' => 'ห้องฉัน'])->assertForbidden();
        $this->actingAs($owner, 'sanctum')->postJson($this->url($schedule, '/auto'), ['room_size' => 2])->assertForbidden();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($this->url($schedule))->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.has_rooms', true);
    }

    public function test_announce_posts_the_list_and_tells_each_person_their_roommates(): void
    {
        $schedule = $this->makeSchedule();
        [$somchai, [$a]] = $this->party($schedule, [['นาย', 'สมชาย ใจดี']]);
        [$bank, [$b]] = $this->party($schedule, [['นาย', 'แบงค์ ขยัน']]);
        [$family, [$c, $d]] = $this->party($schedule, [['นาง', 'แม่ ใจดี'], ['นางสาว', 'ลูก ใจดี']]);
        $staff = $this->staff($schedule);
        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule), ['name' => 'ห้อง 204', 'note' => 'ชั้น 2', 'passenger_ids' => [$a->id, $b->id]]);
        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule), ['name' => 'บ้านริมน้ำ', 'passenger_ids' => [$c->id, $d->id]]);

        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule, '/announce'))->assertOk();

        $message = ChatMessage::where('schedule_id', $schedule->id)->where('sender_role', 'system')
            ->where('body', 'like', '🛏️ ห้องพัก%')->first();
        $this->assertSame(
            "🛏️ ห้องพัก\n• ห้อง 204: สมชาย, แบงค์ (ชั้น 2)\n• บ้านริมน้ำ: แม่, ลูก\nดูห้องของตัวเองได้ในแอป เมนู + › ห้องพัก",
            $message->body,
        );

        $byUser = collect($this->pushes)->groupBy(0);
        $this->assertSame('🛏️ ห้องพักของคุณ: ห้อง 204', $byUser[$somchai->id][0][1]);
        $this->assertStringStartsWith('พักกับ แบงค์ · ชั้น 2', $byUser[$somchai->id][0][2]);
        $this->assertStringStartsWith('พักกับ สมชาย', $byUser[$bank->id][0][2]);
        // ครอบครัวเดียวกันอยู่ห้องเดียวกัน ไม่มีคนอื่น — ไม่ต้องบอกว่า "พักกับ" ตัวเอง
        $this->assertCount(1, $byUser[$family->id]);
        $this->assertStringStartsWith('พักห้องนี้', $byUser[$family->id][0][2]);
    }

    public function test_announce_without_any_guests_is_refused(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->staff($schedule);
        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule), ['name' => 'ห้องว่าง']);

        $this->actingAs($staff, 'sanctum')->postJson($this->url($schedule, '/announce'))->assertStatus(422);
    }

    public function test_rename_and_delete_room(): void
    {
        $schedule = $this->makeSchedule();
        [, [$a]] = $this->party($schedule, [['นาย', 'เอ เอ']]);
        $staff = $this->staff($schedule);
        $roomId = $this->actingAs($staff, 'sanctum')
            ->postJson($this->url($schedule), ['name' => 'ห้อง 1', 'passenger_ids' => [$a->id]])
            ->json('data.rooms.0.id');

        $this->actingAs($staff, 'sanctum')
            ->putJson($this->url($schedule, "/{$roomId}"), ['name' => 'ห้อง 305', 'note' => 'วิวภูเขา'])
            ->assertJsonPath('data.rooms.0.name', 'ห้อง 305')
            ->assertJsonPath('data.rooms.0.note', 'วิวภูเขา');

        $this->actingAs($staff, 'sanctum')
            ->deleteJson($this->url($schedule, "/{$roomId}"))
            ->assertOk()
            ->assertJsonCount(0, 'data.rooms')
            // ลบห้องแล้วคนในห้องกลับไปอยู่ในรายชื่อที่ยังไม่มีห้อง
            ->assertJsonPath('data.unassigned.0.passengers.0.name', 'เอ');
    }
}
