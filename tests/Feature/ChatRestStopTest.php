<?php

namespace Tests\Feature;

use App\Jobs\SendChatPushJob;
use App\Jobs\SettleChatPollsJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ChatRestStop;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * จุดพัก: นัดเวลากลับรถ + เช็คชื่อขึ้นรถ และคำขอแวะห้องน้ำแบบไม่บอกชื่อ
 */
class ChatRestStopTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{user: int, title: string, body: string}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendChatPushJob::class]);

        $this->pushes = [];
        $this->mock(FcmService::class, function (MockInterface $m) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title, $body) {
                $this->pushes[] = ['user' => (int) $userId, 'title' => $title, 'body' => $body];
            });
        });
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปพักรถ',
            'slug' => 'rest-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'น่าน',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1900,
            'status' => 'active',
        ]);

        // ระหว่างเดินทางจริง — วันนี้คือวันออกเดินทาง
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /**
     * ใบจองหนึ่งใบ เจ้าของจองให้ทั้งกลุ่ม
     *
     * @param  array<int, string>  $names
     * @return array{0: User, 1: Booking, 2: array<int, BookingPassenger>}
     */
    private function party(TripSchedule $schedule, array $names, array $bookingOverrides = []): array
    {
        $owner = User::factory()->create(['phone' => '0899999999']);
        $booking = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1900 * count($names),
        ], $bookingOverrides));

        $passengers = array_map(fn ($n) => BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => $n,
            'phone' => null,
        ]), $names);

        return [$owner, $booking, $passengers];
    }

    private function staff(TripSchedule $schedule): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create(['nickname' => 'พี่สตาฟ']);
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function openStop(User $staff, TripSchedule $schedule, array $payload = []): array
    {
        return $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops", array_merge([
                'minutes' => 20,
                'place' => 'ปั๊ม ปตท. วังน้อย',
            ], $payload))
            ->assertCreated()
            ->json('data');
    }

    private function board(User $user, TripSchedule $schedule, int $stopId, array $ids, bool $boarded = true)
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops/{$stopId}/board", [
                'passenger_ids' => $ids,
                'boarded' => $boarded,
            ]);
    }

    private function settle(): void
    {
        app()->call([new SettleChatPollsJob, 'handle']);
    }

    private function pushesTo(User $user): array
    {
        return array_values(array_filter($this->pushes, fn ($p) => $p['user'] === $user->id));
    }

    public function test_staff_announces_a_stop_with_the_whole_bus_on_the_roll(): void
    {
        $schedule = $this->makeSchedule();
        [, , $group] = $this->party($schedule, ['สมชาย ใจดี', 'สมหญิง รักดี']);
        [, , $solo] = $this->party($schedule, ['มานะ ขยัน']);
        // จอยทริปไม่ได้นั่งรถของรอบ — ไม่อยู่ในรายชื่อขึ้นรถ
        $this->party($schedule, ['จอย ทริป'], ['is_join_trip' => true]);
        $staff = $this->staff($schedule);

        $data = $this->openStop($staff, $schedule);

        $this->assertStringStartsWith('🅿️ พัก 20 นาที ที่ปั๊ม ปตท. วังน้อย — กลับขึ้นรถ ', $data['body']);
        $stop = $data['rest_stop'];
        $this->assertSame(3, $stop['total']);
        $this->assertSame(0, $stop['boarded_count']);
        $this->assertSame(['สมชาย', 'สมหญิง', 'มานะ'], array_column($stop['passengers'], 'name'));
        $this->assertArrayNotHasKey('phone', $stop['passengers'][0], 'การ์ดที่กระจายทั้งห้องต้องไม่มีเบอร์โทร');

        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->callToAction === true);
    }

    public function test_customers_cannot_announce_a_stop(): void
    {
        $schedule = $this->makeSchedule();
        [$owner] = $this->party($schedule, ['สมชาย ใจดี']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops", ['minutes' => 10])
            ->assertForbidden();
    }

    public function test_owner_boards_their_whole_group_but_not_other_people(): void
    {
        $schedule = $this->makeSchedule();
        [$owner, , $group] = $this->party($schedule, ['สมชาย ใจดี', 'สมหญิง รักดี']);
        [, , $solo] = $this->party($schedule, ['มานะ ขยัน']);
        $stopId = $this->openStop($this->staff($schedule), $schedule)['rest_stop']['id'];

        $this->board($owner, $schedule, $stopId, [$solo[0]->id])->assertStatus(422);

        $stop = $this->board($owner, $schedule, $stopId, [$group[0]->id, $group[1]->id])
            ->assertOk()
            ->json('data.rest_stop');
        $this->assertSame(2, $stop['boarded_count']);

        // ติ๊กพลาด → เอาออกได้
        $stop = $this->board($owner, $schedule, $stopId, [$group[1]->id], false)->json('data.rest_stop');
        $this->assertSame(1, $stop['boarded_count']);
    }

    public function test_a_linked_companion_boards_themselves_not_the_owner(): void
    {
        $schedule = $this->makeSchedule();
        [$owner, $booking, $group] = $this->party($schedule, ['สมชาย ใจดี', 'สมหญิง รักดี']);
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'passenger_id' => $group[1]->id,
            'role' => 'member',
            'status' => BookingMember::STATUS_ACTIVE,
        ]);
        $stopId = $this->openStop($this->staff($schedule), $schedule)['rest_stop']['id'];

        $this->board($friend, $schedule, $stopId, [$group[0]->id])->assertStatus(422);
        $this->board($friend, $schedule, $stopId, [$group[1]->id])->assertOk();
        // เจ้าของใบจองไม่ได้ดูแลที่นั่งที่เพื่อนผูกบัญชีไว้แล้ว
        $this->board($owner, $schedule, $stopId, [$group[1]->id])->assertStatus(422);
    }

    public function test_staff_sees_phones_and_can_board_anyone(): void
    {
        $schedule = $this->makeSchedule();
        [, , $group] = $this->party($schedule, ['สมชาย ใจดี']);
        $staff = $this->staff($schedule);
        $stopId = $this->openStop($staff, $schedule)['rest_stop']['id'];

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops/{$stopId}")
            ->assertOk()
            // ผู้โดยสารไม่มีเบอร์ → ใช้เบอร์คนที่จองให้
            ->assertJsonPath('data.rest_stop.passengers.0.phone', '0899999999');

        $this->board($staff, $schedule, $stopId, [$group[0]->id])
            ->assertOk()
            ->assertJsonPath('data.rest_stop.boarded_count', 1);
    }

    public function test_reminds_only_the_people_still_missing_then_tells_staff_who(): void
    {
        $schedule = $this->makeSchedule();
        [$onBus, , $a] = $this->party($schedule, ['สมชาย ใจดี']);
        [$late, , $b] = $this->party($schedule, ['มานะ ขยัน', 'มานี มีตา']);
        $staff = $this->staff($schedule);
        $stopId = $this->openStop($staff, $schedule, ['minutes' => 20])['rest_stop']['id'];
        $this->board($onBus, $schedule, $stopId, [$a[0]->id]);

        $this->settle();
        $this->assertSame([], $this->pushes, 'ยังไม่ถึงช่วงเตือน');

        $this->travel(16)->minutes();
        $this->settle();
        $this->settle();

        $this->assertCount(0, $this->pushesTo($onBus));
        $this->assertCount(1, $this->pushesTo($late));
        $this->assertSame('⏰ อีก 4 นาทีรถออก (มานะ, มานี)', $this->pushesTo($late)[0]['title']);

        $this->travel(5)->minutes();
        $this->settle();
        $this->settle();

        $this->assertCount(2, $this->pushesTo($late));
        $this->assertStringStartsWith('🚐 ถึงเวลากลับขึ้นรถแล้ว', $this->pushesTo($late)[1]['title']);
        $toStaff = $this->pushesTo($staff);
        $this->assertCount(1, $toStaff);
        $this->assertSame('🚐 ถึงเวลาออกรถ ยังขาด 2 คน', $toStaff[0]['title']);
        $this->assertSame('มานะ, มานี', $toStaff[0]['body']);
    }

    public function test_extending_moves_the_deadline_and_reminds_again(): void
    {
        $schedule = $this->makeSchedule();
        [$late] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staff($schedule);
        $stopId = $this->openStop($staff, $schedule, ['minutes' => 10])['rest_stop']['id'];

        $this->travel(11)->minutes();
        $this->settle();
        // job ไม่ได้วิ่งช่วงก่อน 5 นาที → ส่งแค่ "ถึงเวลาแล้ว" ไม่ส่งใบเตือนที่ล้าสมัยตามหลัง
        $this->assertCount(1, $this->pushesTo($late));
        $this->assertStringStartsWith('🚐 ถึงเวลากลับขึ้นรถแล้ว', $this->pushesTo($late)[0]['title']);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops/{$stopId}/extend", ['minutes' => 10])
            ->assertOk();

        $stop = ChatRestStop::find($stopId);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $stop->return_at->timestamp, 5);
        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)->where('body', 'like', '⏰ ขยายเวลาพักอีก 10 นาที%')->exists());

        $this->travel(6)->minutes();
        $this->settle();
        $this->assertCount(2, $this->pushesTo($late));
        $this->assertSame('⏰ อีก 4 นาทีรถออก', $this->pushesTo($late)[1]['title']);
    }

    public function test_short_stop_does_not_remind_right_after_the_announcement(): void
    {
        $schedule = $this->makeSchedule();
        [$late] = $this->party($schedule, ['มานะ ขยัน']);
        $this->openStop($this->staff($schedule), $schedule, ['minutes' => 5]);

        $this->travel(1)->minutes();
        $this->settle();

        $this->assertCount(0, $this->pushesTo($late));
    }

    public function test_departing_reports_the_head_count_and_locks_the_card(): void
    {
        $schedule = $this->makeSchedule();
        [$owner, , $group] = $this->party($schedule, ['สมชาย ใจดี', 'สมหญิง รักดี']);
        $staff = $this->staff($schedule);
        $stopId = $this->openStop($staff, $schedule)['rest_stop']['id'];
        $this->board($owner, $schedule, $stopId, [$group[0]->id, $group[1]->id]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops/{$stopId}/depart")
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops/{$stopId}/depart")
            ->assertOk()
            ->assertJsonPath('data.rest_stop.is_departed', true);

        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)
            ->where('body', '🚐 ออกรถแล้ว — ขึ้นครบ 2/2 คน ไปต่อกันเลย!')->exists());
        $this->board($owner, $schedule, $stopId, [$group[0]->id], false)->assertStatus(422);

        // ออกรถแล้วไม่เตือนใครอีก
        $this->travel(30)->minutes();
        $this->settle();
        $this->assertCount(0, $this->pushesTo($owner));
    }

    public function test_a_new_stop_closes_the_forgotten_one(): void
    {
        $schedule = $this->makeSchedule();
        $this->party($schedule, ['สมชาย ใจดี']);
        $staff = $this->staff($schedule);
        $first = $this->openStop($staff, $schedule)['rest_stop']['id'];
        $this->openStop($staff, $schedule);

        $this->assertTrue(ChatRestStop::find($first)->isDeparted());
    }

    public function test_toilet_request_reaches_staff_as_a_number_only(): void
    {
        $schedule = $this->makeSchedule();
        [$a] = $this->party($schedule, ['สมชาย ใจดี']);
        [$b] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staff($schedule);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests")
            ->assertOk()
            ->assertJsonPath('data.pending', 1);
        // กดซ้ำ = คำขอเดิม ไม่เด้งหาสตาฟอีก
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests");
        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests", ['urgent' => true])
            ->assertJsonPath('data.pending', 2)
            ->assertJsonPath('data.urgent', 1);

        $toStaff = $this->pushesTo($staff);
        $this->assertCount(2, $toStaff);
        $this->assertSame('🚻 มีคนขอแวะห้องน้ำ (ด่วน)', $toStaff[1]['title']);
        foreach ($toStaff as $push) {
            $this->assertStringNotContainsString($a->name, $push['title'].$push['body']);
            $this->assertStringNotContainsString($b->name, $push['title'].$push['body']);
        }

        // ห้องเห็นแค่ตัวเลข + ฉันขอค้างไว้ไหม
        $this->actingAs($a, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.stop_requests.pending', 2)
            ->assertJsonPath('data.my_stop_request', true);

        // ลูกค้ากดรับทราบแทนสตาฟไม่ได้
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests/ack")
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests/ack", ['minutes' => 10])
            ->assertOk()
            ->assertJsonPath('data.pending', 0);

        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)
            ->where('body', '🚻 ทีมงานรับทราบคำขอแวะห้องน้ำแล้ว — จะแวะในอีกประมาณ 10 นาทีครับ')->exists());
        $this->assertSame('🚻 ทีมงานรับทราบแล้ว', last($this->pushesTo($a))['title']);
        $this->assertSame('🚻 ทีมงานรับทราบแล้ว', last($this->pushesTo($b))['title']);
    }

    public function test_announcing_a_stop_answers_pending_toilet_requests(): void
    {
        $schedule = $this->makeSchedule();
        [$a] = $this->party($schedule, ['สมชาย ใจดี']);
        $staff = $this->staff($schedule);

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests");
        $this->openStop($staff, $schedule);

        $this->actingAs($a, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.stop_requests.pending', 0)
            ->assertJsonPath('data.my_stop_request', false);
    }

    public function test_requester_can_take_it_back(): void
    {
        $schedule = $this->makeSchedule();
        [$a] = $this->party($schedule, ['สมชาย ใจดี']);
        $this->staff($schedule);

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests");
        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests/mine")
            ->assertOk()
            ->assertJsonPath('data.pending', 0);
    }

    public function test_toilet_requests_only_open_around_the_trip(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->update([
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
        ]);
        [$a] = $this->party($schedule, ['สมชาย ใจดี']);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests")
            ->assertStatus(422);
    }
}
