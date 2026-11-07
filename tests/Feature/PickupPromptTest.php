<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แถบ "คุณอยู่ไหนแล้ว" ในห้องแชท — โผล่เฉพาะช่วงใกล้เวลารับของจุดที่คนนั้นขึ้น
 * ไม่ค้างทั้งวัน และคนขึ้นคนละจุดเห็นไม่พร้อมกัน
 */
class PickupPromptTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private SchedulePickupPoint $mochit;

    private SchedulePickupPoint $rangsit;

    protected function setUp(): void
    {
        parent::setUp();

        $trip = Trip::create([
            'title' => 'Prompt Trip',
            'slug' => 'prompt-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-11-08',
            'return_date' => '2026-11-09',
            'total_seats' => 12,
            'booked_seats' => 8,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $this->mochit = $this->point('หมอชิต', 1, '04:30');
        $this->rangsit = $this->point('ฟิวเจอร์รังสิต', 2, '05:15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prompt_opens_ninety_minutes_before_the_pickup_time_of_that_point(): void
    {
        [, $me] = $this->booking($this->mochit);

        $this->at('2026-11-08 02:55');
        $this->prompt($me)
            ->assertJsonPath('data.visible', false)
            ->assertJsonPath('data.opens_at', '2026-11-08T03:00:00');

        $this->at('2026-11-08 03:05');
        $this->prompt($me)
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.point_label', 'หมอชิต')
            ->assertJsonPath('data.pickup_time', '04:30')
            ->assertJsonPath('data.status', null);

        // หลังเวลารับเกินชั่วโมง — เลิกถาม
        $this->at('2026-11-08 05:31');
        $this->prompt($me)->assertJsonPath('data.visible', false);
    }

    public function test_people_at_a_later_point_are_asked_later(): void
    {
        [, $rangsitUser] = $this->booking($this->rangsit);

        $this->at('2026-11-08 03:30');
        $this->prompt($rangsitUser)->assertJsonPath('data.visible', false);

        $this->at('2026-11-08 03:50');
        $this->prompt($rangsitUser)
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.point_label', 'ฟิวเจอร์รังสิต');
    }

    public function test_reports_status_and_how_many_mates_are_already_there(): void
    {
        [$mine, $me] = $this->booking($this->mochit);
        $this->booking($this->mochit, status: 'arrived');
        $this->booking($this->mochit);

        $this->at('2026-11-08 04:00');
        $mine->update(['pickup_status' => 'late', 'pickup_status_at' => now(), 'pickup_status_eta_minutes' => 15]);

        $this->prompt($me)
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.booking_ref', $mine->booking_ref)
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.status_label', 'อาจสาย ~15 นาที')
            ->assertJsonPath('data.mates_people', 2)
            ->assertJsonPath('data.mates_arrived', 1);
    }

    public function test_checked_in_traveller_is_no_longer_asked(): void
    {
        [$mine, $me] = $this->booking($this->mochit);
        $mine->passengers()->update(['checked_in_at' => now()]);
        $mine->update(['checked_in' => true]);

        $this->at('2026-11-08 04:00');
        $this->prompt($me)->assertJsonPath('data.visible', false);
    }

    public function test_linked_member_is_asked_about_their_own_seats_point(): void
    {
        [$mine] = $this->booking($this->mochit);
        $friendSeat = BookingPassenger::create([
            'booking_id' => $mine->id,
            'name' => 'เพื่อน ขึ้นรังสิต',
            'phone' => '0800000001',
            'pickup_point_id' => $this->rangsit->id,
        ]);
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $mine->id,
            'user_id' => $friend->id,
            'passenger_id' => $friendSeat->id,
            'role' => 'member',
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->at('2026-11-08 04:00');
        $this->prompt($friend)
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.point_label', 'ฟิวเจอร์รังสิต');
    }

    public function test_night_before_round_counts_after_midnight_pickups_on_the_next_day(): void
    {
        // รถออกสี่ทุ่มคืนก่อน (เวลาไทยในคอลัมน์ UTC) แล้วแวะจุดสุดท้ายตีครึ่ง
        $this->schedule->update(['departs_at' => '2026-11-07 22:00:00']);
        $this->mochit->update(['pickup_time' => '22:00']);
        $this->rangsit->update(['pickup_time' => '00:30']);
        [, $late] = $this->booking($this->rangsit);

        $this->at('2026-11-07 22:00');
        $this->prompt($late)
            ->assertJsonPath('data.visible', false)
            ->assertJsonPath('data.opens_at', '2026-11-07T23:00:00');

        // หลังเที่ยงคืน — ยังอยู่ในช่วงกดสถานะของรอบ
        $this->at('2026-11-08 00:10');
        $this->prompt($late)->assertJsonPath('data.visible', true);
    }

    public function test_point_without_time_falls_back_to_the_round_departure_time(): void
    {
        $this->schedule->update(['departs_at' => '2026-11-08 06:00:00']);
        $this->mochit->update(['pickup_time' => null]);
        [, $me] = $this->booking($this->mochit);

        $this->at('2026-11-08 04:20');
        $this->prompt($me)->assertJsonPath('data.visible', false);

        $this->at('2026-11-08 04:40');
        $this->prompt($me)
            ->assertJsonPath('data.visible', true)
            ->assertJsonPath('data.pickup_time', null);
    }

    public function test_people_outside_the_round_are_refused(): void
    {
        $this->at('2026-11-08 04:00');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/pickup-prompt")
            ->assertForbidden();
    }

    public function test_van_heading_over_asks_only_people_who_have_not_said_where_they_are(): void
    {
        $this->at('2026-11-08 04:20');
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        [, $silent] = $this->booking($this->rangsit);
        [, $told] = $this->booking($this->rangsit, status: 'on_the_way');

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$this->schedule->id}/pickup-points/{$this->mochit->id}/complete", [
                'completed' => true,
            ])
            ->assertOk();

        $silentPush = SmartNotification::where('user_id', $silent->id)->where('type', 'pickup_approaching')->first();
        $toldPush = SmartNotification::where('user_id', $told->id)->where('type', 'pickup_approaching')->first();

        $this->assertStringContainsString('กดบอกทีมงานในแชทได้ว่าคุณอยู่ไหน', $silentPush->body);
        $this->assertSame('1', $silentPush->data['ask_status']);
        $this->assertStringNotContainsString('กดบอกทีมงาน', $toldPush->body);
        $this->assertArrayNotHasKey('ask_status', $toldPush->data);
    }

    private function at(string $thaiWallClock): void
    {
        Carbon::setTestNow(Carbon::parse($thaiWallClock, 'Asia/Bangkok'));
    }

    private function prompt(User $user): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/pickup-prompt")
            ->assertOk();
    }

    private function point(string $location, int $order, ?string $time): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $this->schedule->id,
            'region' => 'bkk-'.$order,
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => $location,
            'pickup_time' => $time,
            'price' => 0,
            'sort_order' => $order,
        ]);
    }

    /** @return array{0: Booking, 1: User} */
    private function booking(SchedulePickupPoint $point, ?string $status = null): array
    {
        $user = User::factory()->create();

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $point->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
            'pickup_status' => $status,
            'pickup_status_at' => $status ? now() : null,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'ผู้โดยสาร '.$booking->id,
            'phone' => '0800000000',
            'pickup_point_id' => $point->id,
        ]);

        return [$booking->fresh(), $user];
    }
}
