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
use Illuminate\Support\Carbon;
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

    // ── นัดรวมพลล่วงหน้า ────────────────────────────────────────────────────

    private function openMeetup(User $staff, TripSchedule $schedule, Carbon $at, ?string $place = 'หน้าลานกางเต็นท์')
    {
        return $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops", [
                'meet_at' => $at->toIso8601String(),
                'place' => $place,
            ]);
    }

    public function test_meetup_tomorrow_includes_join_trip_people_and_reads_naturally(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 14:00', 'Asia/Bangkok'));
        $schedule = $this->makeSchedule();
        $this->party($schedule, ['สมชาย ใจดี']);
        $this->party($schedule, ['จอย ทริป'], ['is_join_trip' => true]);
        $staff = $this->staff($schedule);

        $data = $this->openMeetup($staff, $schedule, Carbon::parse('2026-10-11 04:30', 'Asia/Bangkok'))
            ->assertCreated()
            ->json('data');

        $this->assertSame('📍 นัดรวมพล พรุ่งนี้ 04:30 น. ที่หน้าลานกางเต็นท์ — ถึงจุดนัดแล้วกด "มาถึงแล้ว" ในการ์ดนี้', $data['body']);
        $this->assertSame('meetup', $data['rest_stop']['kind']);
        $this->assertSame(2, $data['rest_stop']['total'], 'นัดรวมพลนับคนจอยทริปด้วย');
        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->callToAction === true);
    }

    public function test_meetup_must_be_in_the_next_seven_days(): void
    {
        $schedule = $this->makeSchedule();
        $staff = $this->staff($schedule);

        $this->openMeetup($staff, $schedule, now()->subMinute())->assertStatus(422);
        $this->openMeetup($staff, $schedule, now()->addDays(8))->assertStatus(422);
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops", ['place' => 'ไหนก็ได้'])
            ->assertStatus(422);
    }

    public function test_a_rest_stop_today_does_not_cancel_tomorrows_meetup_and_vice_versa(): void
    {
        $schedule = $this->makeSchedule();
        $this->party($schedule, ['สมชาย ใจดี']);
        $staff = $this->staff($schedule);

        $restId = $this->openStop($staff, $schedule)['rest_stop']['id'];
        $meetupId = $this->openMeetup($staff, $schedule, now()->addDay())->json('data.rest_stop.id');
        $this->assertFalse(ChatRestStop::find($restId)->isDeparted());

        $this->openStop($staff, $schedule);
        $this->assertTrue(ChatRestStop::find($restId)->isDeparted(), 'จุดพักใบเก่าปิดตามปกติ');
        $this->assertFalse(ChatRestStop::find($meetupId)->isDeparted(), 'นัดพรุ่งนี้ต้องอยู่รอด');
    }

    public function test_meetup_reminds_the_eve_before_then_fifteen_minutes_before_then_tells_staff(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 14:00', 'Asia/Bangkok'));
        $schedule = $this->makeSchedule();
        [$early, , $a] = $this->party($schedule, ['สมชาย ใจดี']);
        [$late] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staff($schedule);
        $stopId = $this->openMeetup($staff, $schedule, Carbon::parse('2026-10-11 04:30', 'Asia/Bangkok'))
            ->json('data.rest_stop.id');

        $this->travelTo(Carbon::parse('2026-10-10 19:59', 'Asia/Bangkok'));
        $this->settle();
        $this->assertSame([], $this->pushes);

        // 20:00 คืนก่อน — ทุกคน ครั้งเดียว
        $this->travelTo(Carbon::parse('2026-10-10 20:00', 'Asia/Bangkok'));
        $this->settle();
        $this->settle();
        $this->assertCount(1, $this->pushesTo($early));
        $this->assertCount(1, $this->pushesTo($late));
        $this->assertSame('🌙 พรุ่งนี้ 04:30 น. นัดรวมพล', $this->pushesTo($late)[0]['title']);
        $this->assertCount(0, $this->pushesTo($staff));

        // ตีสอง กด "มาถึงแล้ว" ล่วงหน้าไม่ได้
        $this->travelTo(Carbon::parse('2026-10-11 01:00', 'Asia/Bangkok'));
        $this->board($early, $schedule, $stopId, [$a[0]->id])->assertStatus(422);

        $this->travelTo(Carbon::parse('2026-10-11 04:10', 'Asia/Bangkok'));
        $this->board($early, $schedule, $stopId, [$a[0]->id])->assertOk();

        // 04:15 — เตือนเฉพาะคนที่ยังไม่มา
        $this->travelTo(Carbon::parse('2026-10-11 04:15', 'Asia/Bangkok'));
        $this->settle();
        $this->assertCount(1, $this->pushesTo($early));
        $this->assertCount(2, $this->pushesTo($late));
        $this->assertSame('⏰ อีก 15 นาทีนัดรวมพล', $this->pushesTo($late)[1]['title']);

        // 04:30 — ถึงเวลา บอกสตาฟว่าใครยังไม่มา
        $this->travelTo(Carbon::parse('2026-10-11 04:30', 'Asia/Bangkok'));
        $this->settle();
        $this->assertSame('📍 ถึงเวลานัดรวมพลแล้ว', $this->pushesTo($late)[2]['title']);
        $this->assertSame('📍 ถึงเวลานัด ยังไม่มา 1 คน', $this->pushesTo($staff)[0]['title']);
        $this->assertSame('มานะ', $this->pushesTo($staff)[0]['body']);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops/{$stopId}/depart")
            ->assertOk();
        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)
            ->where('body', '📍 เริ่มแล้ว (มาถึงจุดนัด 1/2 คน)')->exists());
    }

    public function test_meetup_set_late_in_the_evening_skips_the_eve_reminder(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 21:30', 'Asia/Bangkok'));
        $schedule = $this->makeSchedule();
        [$late] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staff($schedule);
        $this->openMeetup($staff, $schedule, Carbon::parse('2026-10-11 05:00', 'Asia/Bangkok'));

        $this->travelTo(Carbon::parse('2026-10-10 22:00', 'Asia/Bangkok'));
        $this->settle();

        $this->assertCount(0, $this->pushesTo($late), 'เพิ่งประกาศไปเมื่อกี้ — ไม่ต้องเตือนคืนนี้ซ้ำ');
    }

    public function test_staff_can_postpone_a_meetup(): void
    {
        $schedule = $this->makeSchedule();
        $this->party($schedule, ['สมชาย ใจดี']);
        $staff = $this->staff($schedule);
        $at = now()->addHours(10)->startOfMinute();
        $stopId = $this->openMeetup($staff, $schedule, $at)->json('data.rest_stop.id');

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/rest-stops/{$stopId}/extend", ['minutes' => 30])
            ->assertOk();

        $this->assertSame($at->copy()->addMinutes(30)->timestamp, ChatRestStop::find($stopId)->return_at->timestamp);
        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)->where('body', 'like', '⏰ เลื่อนเวลานัดรวมพลไป 30 นาที%')->exists());
    }

    // ── คำขอแบบไม่บอกชื่ออื่น ๆ (แอร์ / ความเร็ว / เพลง) ─────────────────────

    private function ask(User $user, TripSchedule $schedule, string $kind, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests", ['kind' => $kind, ...$extra]);
    }

    public function test_comfort_requests_are_counted_per_kind_and_never_named(): void
    {
        $schedule = $this->makeSchedule();
        [$a] = $this->party($schedule, ['สมชาย ใจดี']);
        [$b] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staff($schedule);

        $this->ask($a, $schedule, 'too_cold')->assertOk()
            ->assertJsonPath('data.kinds.too_cold', 1)
            ->assertJsonPath('data.mine_kinds', ['too_cold'])
            ->assertJsonPath('data.pending', 0, 'pending ยังหมายถึงห้องน้ำ (แอปรุ่นก่อน)');
        $this->ask($a, $schedule, 'too_cold'); // กดซ้ำ = คำขอเดิม
        $this->ask($b, $schedule, 'too_hot');

        $toStaff = $this->pushesTo($staff);
        $this->assertCount(2, $toStaff);
        $this->assertSame('🥶 มีคนบอกว่าแอร์หนาวไป', $toStaff[0]['title']);
        // หนาวกับร้อนพร้อมกัน → บอกให้รู้ก่อนปรับ
        $this->assertStringContainsString('(แต่มีคนบอกว่าแอร์หนาวไป 1 คน)', $toStaff[1]['body']);
        foreach ($toStaff as $push) {
            $this->assertStringNotContainsString($a->name, $push['title'].$push['body']);
        }

        $this->actingAs($b, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.stop_requests.kinds.too_cold', 1)
            ->assertJsonPath('data.stop_requests.kinds.too_hot', 1)
            ->assertJsonPath('data.my_stop_requests', ['too_hot']);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests/ack", ['kind' => 'too_cold'])
            ->assertOk()
            ->assertJsonPath('data.kinds.too_cold', 0)
            ->assertJsonPath('data.kinds.too_hot', 1, 'รับทราบเรื่องหนึ่งไม่ปิดอีกเรื่อง');

        $this->assertTrue(ChatMessage::where('schedule_id', $schedule->id)
            ->where('body', '🥶 ทีมงานรับทราบแล้ว — ปรับแอร์ให้อุ่นขึ้นแล้วนะครับ')->exists());
        $this->assertSame('🥶 ทีมงานรับทราบแล้ว', last($this->pushesTo($a))['title']);
        $this->assertCount(0, array_filter($this->pushesTo($b), fn ($p) => str_contains($p['title'], 'รับทราบ')));
    }

    public function test_comfort_requests_expire_after_an_hour_and_count_again_when_pressed(): void
    {
        $schedule = $this->makeSchedule();
        [$a] = $this->party($schedule, ['สมชาย ใจดี']);
        $staff = $this->staff($schedule);

        $this->ask($a, $schedule, 'too_fast');
        $this->travel(61)->minutes();

        $this->actingAs($a, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.stop_requests.kinds.too_fast', 0)
            ->assertJsonPath('data.my_stop_requests', []);

        $this->ask($a, $schedule, 'too_fast')->assertJsonPath('data.kinds.too_fast', 1);
        $this->assertCount(2, $this->pushesTo($staff), 'กดใหม่หลังหมดอายุ = เด้งหาสตาฟอีกรอบ');

        // ขอแวะห้องน้ำไม่หมดอายุ
        $this->ask($a, $schedule, 'toilet');
        $this->travel(3)->hours();
        $this->actingAs($a, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.stop_requests.pending', 1);
    }

    public function test_announcing_a_rest_stop_only_answers_toilet_requests(): void
    {
        $schedule = $this->makeSchedule();
        [$a] = $this->party($schedule, ['สมชาย ใจดี']);
        $staff = $this->staff($schedule);

        $this->ask($a, $schedule, 'toilet');
        $this->ask($a, $schedule, 'music_down');
        $this->openStop($staff, $schedule);

        $this->actingAs($a, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/room")
            ->assertJsonPath('data.stop_requests.pending', 0)
            ->assertJsonPath('data.stop_requests.kinds.music_down', 1);
    }

    public function test_requester_withdraws_one_kind_and_staff_cannot_ask(): void
    {
        $schedule = $this->makeSchedule();
        [$a] = $this->party($schedule, ['สมชาย ใจดี']);
        $staff = $this->staff($schedule);

        $this->ask($a, $schedule, 'too_cold');
        $this->ask($a, $schedule, 'toilet');
        $this->actingAs($a, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/chat/stop-requests/mine", ['kind' => 'too_cold'])
            ->assertOk()
            ->assertJsonPath('data.mine_kinds', ['toilet'])
            ->assertJsonPath('data.mine', true);

        $this->ask($staff, $schedule, 'toilet')->assertForbidden();
        $this->ask($a, $schedule, 'karaoke')->assertStatus(422);
    }
}
