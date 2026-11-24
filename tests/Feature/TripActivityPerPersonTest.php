<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\FcmToken;
use App\Models\LiveActivity;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ApnsLiveActivityService;
use App\Services\FcmService;
use App\Services\HomeWidgetService;
use App\Services\TripActivityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * การ์ดวันเดินทางเป็นของ "คน" ไม่ใช่ของใบจอง — และต้องไม่ตายกลางทางเพราะเพดาน
 * 8 ชั่วโมงของ iOS
 *
 * ใบจองในเทสต์นี้มีสองที่นั่ง: เจ้าของขึ้นจุดแรก (04:00) เพื่อนที่ผูกที่นั่งไว้ขึ้น
 * จุดที่สาม (05:30)
 */
class TripActivityPerPersonTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $friend;

    private TripSchedule $schedule;

    private Booking $booking;

    private SchedulePickupPoint $first;

    private SchedulePickupPoint $third;

    private BookingPassenger $ownerSeat;

    private BookingPassenger $friendSeat;

    protected function setUp(): void
    {
        parent::setUp();

        // 03:30 เช้าวันเดินทาง — ครึ่งชั่วโมงก่อนรถออกจากจุดแรก
        Carbon::setTestNow(Carbon::parse('2026-09-06 03:30:00', 'Asia/Bangkok'));

        $trip = Trip::create([
            'title' => 'ภูชี้ฟ้า', 'slug' => 'per-person-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงราย', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2500, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-09-06',
            'departs_at' => '2026-09-06 04:00:00',
            'return_date' => '2026-09-07',
            'total_seats' => 10, 'booked_seats' => 2, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $this->first = $this->point('ปั๊ม ปตท. รังสิต', '04:00', 1);
        $this->third = $this->point('เซ็นทรัล นครสวรรค์', '05:30', 3);

        $this->owner = User::factory()->create();
        $this->friend = User::factory()->create();

        $this->booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'pickup_point_id' => $this->first->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 5000,
            'paid_amount' => 5000,
        ]);

        $this->ownerSeat = $this->seat('เจ้าของ', null);
        $this->friendSeat = $this->seat('เพื่อน', $this->third->id);

        BookingMember::create([
            'booking_id' => $this->booking->id,
            'user_id' => $this->friend->id,
            'passenger_id' => $this->friendSeat->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_each_person_sees_their_own_pickup_point_and_time(): void
    {
        $owner = $this->stateFor($this->owner);
        $friend = $this->stateFor($this->friend);

        $this->assertSame('ปั๊ม ปตท. รังสิต', $owner['pickup_name']);
        $this->assertSame('รถมารับเวลา 04:00 น.', $owner['headline']);

        $this->assertSame('เซ็นทรัล นครสวรรค์', $friend['pickup_name']);
        $this->assertSame('รถมารับเวลา 05:30 น.', $friend['headline']);
    }

    public function test_the_owner_boarding_does_not_board_the_friend_still_waiting(): void
    {
        // เจ้าของขึ้นที่จุดแรก — ใบจองพลิกเป็น "มีคนขึ้นรถแล้ว"
        $this->ownerSeat->forceFill(['checked_in_at' => now()])->save();
        $this->booking->update(['checked_in' => true, 'checked_in_at' => now()]);

        $this->assertSame('onboard', $this->stateFor($this->owner)['stage']);

        // เพื่อนยังยืนรออยู่ที่จุดที่สาม การ์ดของเขาต้องยังรอรถอยู่
        $friend = $this->stateFor($this->friend);
        $this->assertNotSame('onboard', $friend['stage']);
        $this->assertSame('เซ็นทรัล นครสวรรค์', $friend['pickup_name']);
    }

    public function test_someone_who_is_not_going_gets_no_card(): void
    {
        $this->friendSeat->forceFill(['not_going_at' => now()])->save();

        $this->assertNull($this->stateFor($this->friend));
        $this->assertNotNull($this->stateFor($this->owner));
    }

    public function test_the_show_endpoint_answers_for_whoever_asks(): void
    {
        $this->actingAs($this->friend)
            ->getJson("/api/v1/bookings/{$this->booking->booking_ref}/live-activity")
            ->assertOk()
            ->assertJsonPath('data.state.pickup_name', 'เซ็นทรัล นครสวรรค์');
    }

    public function test_an_android_friend_gets_their_own_card_and_only_when_it_changes(): void
    {
        FcmToken::create(['user_id' => $this->friend->id, 'token' => 'android-friend', 'platform' => 'android']);

        $sent = [];
        $this->mock(FcmService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendDataToUser')->andReturnUsing(function ($userId, $data) use (&$sent) {
                $sent[] = [$userId, json_decode($data['state'], true)];
            });
        });

        $service = app(TripActivityService::class);
        $service->syncSchedule($this->schedule);

        // ไม่มีการ์ด iOS ในใบนี้เลย แต่เครื่อง Android ของเพื่อนก็ยังต้องได้อัปเดต
        $this->assertCount(1, $sent);
        $this->assertSame($this->friend->id, $sent[0][0]);
        $this->assertSame('เซ็นทรัล นครสวรรค์', $sent[0][1]['pickup_name']);

        // นาทีถัดไปไม่มีอะไรเปลี่ยน — ไม่ยิงซ้ำ
        $service->syncSchedule($this->schedule);
        $this->assertCount(1, $sent);
    }

    public function test_re_registering_the_same_card_keeps_its_age(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/live-activities', [
            'booking_ref' => $this->booking->booking_ref,
            'push_token' => 'same-card',
        ])->assertOk();

        $startedAt = LiveActivity::where('push_token', 'same-card')->value('started_at');

        Carbon::setTestNow(now()->addHours(3));

        // แอปฝาก token เดิมซ้ำทุกครั้งที่เปิดใหม่ — ห้ามรีเซ็ตนาฬิกา 8 ชั่วโมง
        $this->actingAs($this->owner)->postJson('/api/v1/live-activities', [
            'booking_ref' => $this->booking->booking_ref,
            'push_token' => 'same-card',
        ])->assertOk();

        $this->assertEquals(
            Carbon::parse($startedAt)->timestamp,
            LiveActivity::where('push_token', 'same-card')->first()->started_at->timestamp,
        );
    }

    public function test_a_card_near_the_8_hour_limit_is_replaced_with_a_fresh_one(): void
    {
        FcmToken::create([
            'user_id' => $this->owner->id, 'token' => 'ios-owner', 'platform' => 'ios',
            'live_activity_start_token' => 'start-token',
        ]);
        $old = $this->card($this->owner, startedHoursAgo: 7.6);

        $apns = $this->mock(ApnsLiveActivityService::class);
        $apns->shouldReceive('start')->once()->with('start-token', Mockery::any(), Mockery::any())->andReturnTrue();
        $apns->shouldReceive('end')->once()
            ->with(Mockery::on(fn ($a) => $a->id === $old->id), Mockery::any(), 0)
            ->andReturnTrue();
        $apns->shouldNotReceive('update');

        app(TripActivityService::class)->sync($this->booking->fresh(app(TripActivityService::class)->relations()));
    }

    public function test_a_card_past_the_limit_is_let_go_so_a_new_one_can_open(): void
    {
        $old = $this->card($this->owner, startedHoursAgo: 8.5);

        $apns = $this->mock(ApnsLiveActivityService::class);
        $apns->shouldNotReceive('update');

        app(TripActivityService::class)->sync($this->booking->fresh(app(TripActivityService::class)->relations()));

        $this->assertNotNull($old->fresh()->ended_at);
    }

    public function test_push_to_start_reaches_the_friend_too_but_not_after_they_swipe_it_away(): void
    {
        FcmToken::create([
            'user_id' => $this->friend->id, 'token' => 'ios-friend', 'platform' => 'ios',
            'live_activity_start_token' => 'friend-start',
        ]);

        $apns = $this->mock(ApnsLiveActivityService::class);
        $apns->shouldReceive('start')->once()->with('friend-start', Mockery::any(), Mockery::any())->andReturnTrue();

        $service = app(TripActivityService::class);
        $this->assertSame(1, $service->pushToStart($this->booking->fresh($service->relations())));

        // เพื่อนปัดการ์ดทิ้ง — ช่วงอื่นของวันเดียวกันก็ไม่เด้งกลับมาเอง
        $card = $this->card($this->friend, startedHoursAgo: 0.1, token: 'friend-card');
        $this->actingAs($this->friend)
            ->deleteJson('/api/v1/live-activities', ['push_token' => 'friend-card', 'reason' => 'dismissed'])
            ->assertOk();
        $this->assertNotNull($card->fresh()->ended_at);

        $this->friendSeat->forceFill(['checked_in_at' => now()->subHours(4)])->save();
        $this->assertSame(0, $service->pushToStart($this->booking->fresh($service->relations())));
    }

    public function test_the_home_widget_names_the_pickup_time_of_the_persons_own_point(): void
    {
        // สามวันก่อนเดินทาง — นอกช่วงการ์ดหน้าจอล็อก วิดเจ็ตพูดเอง
        Carbon::setTestNow(Carbon::parse('2026-09-03 12:00:00', 'Asia/Bangkok'));

        $trip = app(HomeWidgetService::class)->snapshotFor($this->owner->id)['trip'];

        $this->assertSame('04:00', $trip['depart_time']);
        $this->assertStringContainsString('04:00 น. · ปั๊ม ปตท. รังสิต', $trip['detail']);
    }

    public function test_the_home_widget_never_prints_a_made_up_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 12:00:00', 'Asia/Bangkok'));
        $this->schedule->update(['departs_at' => null]);
        $this->first->update(['pickup_time' => null]);

        $trip = app(HomeWidgetService::class)->snapshotFor($this->owner->id)['trip'];

        $this->assertNull($trip['depart_time']);
        $this->assertStringNotContainsString('00:00', $trip['detail']);
    }

    private function stateFor(User $user): ?array
    {
        $service = app(TripActivityService::class);

        return $service->stateFor($this->booking->fresh($service->relations()), $user->id);
    }

    private function point(string $name, string $time, int $order): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $this->schedule->id,
            'region' => 'central',
            'region_label' => 'ภาคกลาง',
            'price' => 2500,
            'pickup_location' => $name,
            'pickup_time' => $time,
            'sort_order' => $order,
        ]);
    }

    private function seat(string $name, ?int $pointId): BookingPassenger
    {
        return BookingPassenger::create([
            'booking_id' => $this->booking->id,
            'name' => $name,
            'phone' => '0800000000',
            'pickup_point_id' => $pointId,
        ]);
    }

    private function card(User $user, float $startedHoursAgo, string $token = 'card'): LiveActivity
    {
        return LiveActivity::create([
            'user_id' => $user->id,
            'booking_id' => $this->booking->id,
            'schedule_id' => $this->schedule->id,
            'platform' => 'ios',
            'push_token' => $token,
            'started_at' => now()->subMinutes((int) round($startedHoursAgo * 60)),
        ]);
    }
}
