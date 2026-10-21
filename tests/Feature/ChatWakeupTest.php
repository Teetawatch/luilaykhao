<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatRestStop;
use App\Models\ScheduleRoom;
use App\Models\ScheduleRoomGuest;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

/**
 * ปลุกรวม — การ์ดจุดพักชนิด wakeup: ปลุกตามเวลา ปลุกซ้ำ และบอกทีมงานว่าห้องไหนยังเงียบ
 */
class ChatWakeupTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTripChatPushes();
        // บ่ายวันแรกของทริป
        $this->day1 = Carbon::parse('today 15:00', 'Asia/Bangkok');
        $this->travelTo($this->day1);
    }

    private Carbon $day1;

    /** ตีสามครึ่งของเช้าวันที่สอง — คิดจากวันแรกที่ตรึงไว้ ไม่ใช่ "พรุ่งนี้" ของเวลาที่เดินทางไปแล้ว */
    private function wakeAt(): Carbon
    {
        return $this->day1->copy()->addDay()->setTime(3, 30);
    }

    private function day2(string $time): Carbon
    {
        [$h, $m] = explode(':', $time);

        return $this->day1->copy()->addDay()->setTime((int) $h, (int) $m);
    }

    private function open(User $staff, TripSchedule $schedule, ?Carbon $at = null)
    {
        return $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, 'rest-stops'), [
            'kind' => 'wakeup',
            'meet_at' => ($at ?? $this->wakeAt())->toIso8601String(),
            'place' => 'ออกเดิน 04:00 หน้าลานเต็นท์',
        ]);
    }

    private function awake(User $user, TripSchedule $schedule, int $id, array $ids)
    {
        return $this->actingAs($user, 'sanctum')->postJson($this->chatUrl($schedule, "rest-stops/{$id}/board"), [
            'passenger_ids' => $ids,
            'boarded' => true,
        ]);
    }

    public function test_staff_set_a_wake_up_call_that_people_can_only_tick_near_the_time(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        $this->party($schedule, ['จอย ขับเอง'], ['is_join_trip' => true]);
        $staff = $this->staffOf($schedule);

        $this->open($family, $schedule)->assertForbidden();
        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, 'rest-stops'), ['kind' => 'wakeup', 'minutes' => 10])
            ->assertStatus(422);
        $this->open($staff, $schedule, $this->day1->copy()->setTime(14, 0))->assertStatus(422);

        $data = $this->open($staff, $schedule)->assertCreated()->json('data');
        $this->assertSame('⏰ ปลุก พรุ่งนี้ 03:30 น. · ออกเดิน 04:00 หน้าลานเต็นท์ — ตื่นแล้วกด "ตื่นแล้ว" ในการ์ดนี้ (เปิดการ์ดนี้ไว้สักครั้ง เครื่องจะตั้งปลุกให้เองแม้ตรงนั้นไม่มีสัญญาณ)', $data['body']);
        $stop = $data['rest_stop'];
        $this->assertSame('wakeup', $stop['kind']);
        $this->assertSame(3, $stop['total'], 'คนจอยทริปค้างกับกลุ่มด้วย');

        $this->awake($family, $schedule, $stop['id'], [$dad->id])->assertStatus(422)
            ->assertJsonPath('message', 'ยังไม่ถึงเวลาปลุก กดได้ตั้งแต่ 02:30 น. ครับ');

        $this->travelTo($this->wakeAt()->subMinutes(30));
        $this->awake($family, $schedule, $stop['id'], [$dad->id])->assertOk()->assertJsonPath('data.rest_stop.boarded_count', 1);

        // ประกาศพักรถไม่ปิดการ์ดปลุก (พักรถปิดได้แค่จุดพักด้วยกัน)
        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, 'rest-stops'), ['minutes' => 10])->assertCreated();
        $this->assertNull(ChatRestStop::find($stop['id'])->departed_at);
    }

    public function test_eve_reminder_then_wake_push_then_staff_learn_which_rooms_are_quiet(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        [$solo, , [$mana]] = $this->party($schedule, ['มานะ ขยัน']);
        $staff = $this->staffOf($schedule);
        $room = ScheduleRoom::create(['schedule_id' => $schedule->id, 'name' => 'ห้อง 3', 'sort_order' => 1]);
        ScheduleRoomGuest::create(['room_id' => $room->id, 'passenger_id' => $mana->id]);

        $stop = $this->open($staff, $schedule)->json('data.rest_stop');
        $this->assertSame('ห้อง 3', collect($stop['passengers'])->firstWhere('id', $mana->id)['room']);

        // คืนก่อน 20:00
        $this->travelTo($this->day1->copy()->setTime(20, 1));
        $this->settleChat();
        $this->assertSame('🌙 พรุ่งนี้ปลุก 03:30 น.', $this->pushesTo($family)[0]['title']);
        $this->assertCount(1, $this->pushesTo($solo));

        // ถึงเวลาปลุก — พ่อตื่นแล้ว ครอบครัวยังโดนปลุกเพราะแม่ยังไม่กด
        $this->travelTo($this->wakeAt()->subMinutes(5));
        $this->awake($family, $schedule, $stop['id'], [$dad->id])->assertOk();
        $this->pushes = [];

        $this->travelTo($this->wakeAt());
        $this->settleChat();
        $this->settleChat();
        $this->assertSame('⏰ ได้เวลาตื่นแล้ว!', $this->pushesTo($family)[0]['title']);
        $this->assertSame('rest_stop', $this->pushesTo($family)[0]['data']['type']);
        $this->assertCount(1, $this->pushesTo($family));
        $this->assertCount(1, $this->pushesTo($solo));
        $this->assertSame([], $this->pushesTo($staff), 'ยังไม่ถึงเวลาปลุกซ้ำ');

        // ปลุกซ้ำ + บอกทีมงานเป็นรายห้อง
        $this->travelTo($this->wakeAt()->addMinutes(ChatRestStop::WAKEUP_FOLLOWUP_MINUTES));
        $this->settleChat();
        $this->assertSame('⏰ ยังไม่ตื่นเหรอครับ?', $this->pushesTo($solo)[1]['title']);
        $this->assertSame('⏰ ยังไม่ตื่น 2 คน', $this->pushesTo($staff)[0]['title']);
        $this->assertSame('ไม่ได้จัดห้อง: แม่ · ห้อง 3: มานะ', $this->pushesTo($staff)[0]['body']);

        $this->settleChat();
        $this->assertCount(1, $this->pushesTo($staff));
    }

    public function test_everyone_awake_closes_the_card(): void
    {
        $schedule = $this->tripSchedule();
        [$family, , [$dad, $mom]] = $this->party($schedule, ['พ่อ ใจดี', 'แม่ ใจดี']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.rest_stop.id');

        $this->travelTo($this->wakeAt());
        $this->awake($family, $schedule, $id, [$dad->id, $mom->id])->assertOk()
            ->assertJsonPath('data.rest_stop.is_departed', true);
        $this->assertTrue(ChatMessage::where('body', '⏰ ตื่นครบ 2/2 คนแล้ว ลุยกันเลย!')->exists());

        $this->pushes = [];
        $this->settleChat();
        $this->assertSame([], $this->pushes);
    }

    public function test_snoozing_moves_the_wake_up_and_rings_again_at_the_new_time(): void
    {
        $schedule = $this->tripSchedule();
        [$family] = $this->party($schedule, ['พ่อ ใจดี']);
        $staff = $this->staffOf($schedule);
        $id = $this->open($staff, $schedule)->json('data.rest_stop.id');

        $this->travelTo($this->wakeAt());
        $this->settleChat();
        $this->assertCount(1, $this->pushesTo($family));

        // ฝนตก เลื่อนออกไป 30 นาที
        $this->actingAs($staff, 'sanctum')->postJson($this->chatUrl($schedule, "rest-stops/{$id}/extend"), ['minutes' => 30])->assertOk();
        $this->assertTrue(ChatMessage::where('body', 'like', '⏰ เลื่อนเวลาปลุกไป 30 นาที — ปลุก % 04:00 น.')->exists());

        $this->settleChat();
        $this->assertCount(1, $this->pushesTo($family), 'ยังไม่ถึงเวลาใหม่');

        $this->travelTo($this->wakeAt()->addMinutes(30));
        $this->settleChat();
        $this->assertCount(2, $this->pushesTo($family));
        $this->assertSame('⏰ ได้เวลาตื่นแล้ว!', $this->pushesTo($family)[1]['title']);
    }

    public function test_a_stalled_scheduler_does_not_ring_twice_or_ring_hours_late(): void
    {
        $schedule = $this->tripSchedule();
        [$family] = $this->party($schedule, ['พ่อ ใจดี']);
        $staff = $this->staffOf($schedule);
        $this->open($staff, $schedule)->assertCreated();

        // งานตามเวลาหยุดไป ตื่นมาตอนเลยเวลาปลุกซ้ำแล้ว — ส่งใบเดียว
        $this->travelTo($this->wakeAt()->addMinutes(15));
        $this->settleChat();
        $this->settleChat();
        $this->assertCount(1, $this->pushesTo($family));
        $this->assertSame('⏰ ยังไม่ตื่นเหรอครับ?', $this->pushesTo($family)[0]['title']);

        // อีกใบที่เลยมาเป็นชั่วโมง — ไม่ปลุกแล้ว
        $this->open($staff, $schedule, $this->day2('05:00'))->assertCreated();
        $this->pushes = [];
        $this->travelTo($this->day2('06:30'));
        $this->settleChat();
        $this->assertSame([], $this->pushesTo($family));
    }
}
