<?php

namespace Tests\Feature;

use App\Jobs\SyncTripActivityJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\LiveActivity;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * การ์ด "วันเดินทาง" บนหน้าจอล็อก
 *
 * สิ่งที่เทสต์ชุดนี้ล็อกไว้คือ "ข้อความบนการ์ดต้องมาจากที่เดียว" — ถ้าตรรกะการ
 * เลือกขั้น (stage) หลุดไปอยู่ฝั่งแอป วันหนึ่ง iOS กับ Android จะบอกเวลารถถึงไม่
 * ตรงกัน ซึ่งแย่กว่าไม่บอกเลย
 */
class TripLiveActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TripSchedule $schedule;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $trip = Trip::create([
            'title' => 'ภูชี้ฟ้า', 'slug' => 'live-act-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงราย', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2500, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'departs_at' => now('Asia/Bangkok')->addHour(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $this->owner = User::factory()->create();

        $this->booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    public function test_state_counts_down_before_departure(): void
    {
        $state = app(TripActivityService::class)->stateFor($this->booking);

        $this->assertNotNull($state);
        // รถออกในอีก 1 ชม. — ยังไม่มีตำแหน่งรถ จึงเป็นช่วง "เตรียมตัว"
        $this->assertSame('preparing', $state['stage']);
        $this->assertStringContainsString('รถออกเวลา', $state['headline']);
        $this->assertNull($state['eta_minutes']);
    }

    public function test_state_reports_eta_once_the_van_is_moving(): void
    {
        $point = SchedulePickupPoint::create([
            'schedule_id' => $this->schedule->id,
            'region' => 'central',
            'region_label' => 'ภาคกลาง',
            'price' => 2500,
            'pickup_location' => 'ปั๊ม ปตท. รังสิต',
            'latitude' => 13.9500,
            'longitude' => 100.6200,
        ]);
        $this->booking->update(['pickup_point_id' => $point->id]);
        $this->schedule->update(['vehicle_id' => $this->vehicleId()]);

        // ~3 กม. จากจุดรับ ที่ 40 กม./ชม. → ประมาณ 4-5 นาที
        $this->fakeVehicleLocation(13.9230, 100.6200, speed: 40);

        $state = app(TripActivityService::class)
            ->stateFor($this->booking->fresh(['schedule', 'pickupPoint']));

        $this->assertSame('arriving', $state['stage']);
        $this->assertLessThanOrEqual(5, $state['eta_minutes']);
        $this->assertStringContainsString('ปั๊ม ปตท. รังสิต', $state['detail']);
        $this->assertGreaterThan(0.5, $state['progress']);
    }

    public function test_checked_in_flips_the_card_to_onboard(): void
    {
        $this->booking->update(['checked_in' => true, 'checked_in_at' => now()]);

        $state = app(TripActivityService::class)->stateFor($this->booking->fresh('schedule'));

        $this->assertSame('onboard', $state['stage']);
        $this->assertSame(1.0, $state['progress']);
    }

    public function test_state_is_null_outside_the_trip_window(): void
    {
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'departs_at' => now('Asia/Bangkok')->addMonth(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
        ]);

        $this->assertNull(
            app(TripActivityService::class)->stateFor($this->booking->fresh('schedule'))
        );
    }

    public function test_cancelled_booking_has_no_card(): void
    {
        $this->booking->update(['status' => 'cancelled']);

        $this->assertNull(
            app(TripActivityService::class)->stateFor($this->booking->fresh('schedule'))
        );
    }

    public function test_owner_can_register_a_push_token_and_gets_the_state_back(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/v1/live-activities', [
            'booking_ref' => $this->booking->booking_ref,
            'push_token' => 'abc123',
            'activity_id' => 'act-1',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.state.stage', 'preparing');

        $this->assertDatabaseHas('live_activities', [
            'push_token' => 'abc123',
            'booking_id' => $this->booking->id,
            'user_id' => $this->owner->id,
            'ended_at' => null,
        ]);
    }

    public function test_registering_a_new_token_retires_the_previous_one(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/live-activities', [
            'booking_ref' => $this->booking->booking_ref,
            'push_token' => 'first',
        ])->assertOk();

        $this->actingAs($this->owner)->postJson('/api/v1/live-activities', [
            'booking_ref' => $this->booking->booking_ref,
            'push_token' => 'second',
        ])->assertOk();

        // ยิงไปหา Activity ที่ตายแล้วทุกนาทีคือค่าใช้จ่ายเปล่า ๆ และเสี่ยงโดน APNs
        // ปิดประตูใส่
        $this->assertNotNull(LiveActivity::where('push_token', 'first')->first()->ended_at);
        $this->assertNull(LiveActivity::where('push_token', 'second')->first()->ended_at);
    }

    public function test_companion_on_the_booking_gets_their_own_card(): void
    {
        $companion = User::factory()->create();
        BookingMember::create([
            'booking_id' => $this->booking->id,
            'user_id' => $companion->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($companion)->postJson('/api/v1/live-activities', [
            'booking_ref' => $this->booking->booking_ref,
            'push_token' => 'companion-token',
        ])->assertOk();

        $this->assertDatabaseHas('live_activities', [
            'push_token' => 'companion-token',
            'user_id' => $companion->id,
        ]);
    }

    public function test_a_stranger_cannot_register_against_someone_elses_booking(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson('/api/v1/live-activities', [
            'booking_ref' => $this->booking->booking_ref,
            'push_token' => 'stranger-token',
        ])->assertNotFound();

        $this->assertDatabaseCount('live_activities', 0);
    }

    public function test_user_can_close_their_own_card(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/live-activities', [
            'booking_ref' => $this->booking->booking_ref,
            'push_token' => 'to-close',
        ])->assertOk();

        $this->actingAs($this->owner)
            ->deleteJson('/api/v1/live-activities', ['push_token' => 'to-close'])
            ->assertOk();

        $this->assertNotNull(LiveActivity::where('push_token', 'to-close')->first()->ended_at);
    }

    public function test_show_returns_the_state_for_android_to_draw(): void
    {
        $this->actingAs($this->owner)
            ->getJson("/api/v1/bookings/{$this->booking->booking_ref}/live-activity")
            ->assertOk()
            ->assertJsonPath('data.state.booking_ref', $this->booking->booking_ref)
            ->assertJsonPath('data.attributes.tripTitle', 'ภูชี้ฟ้า');
    }

    public function test_check_in_pushes_the_card_immediately_rather_than_waiting_for_the_next_sweep(): void
    {
        Bus::fake([SyncTripActivityJob::class]);

        $this->booking->update(['checked_in' => true, 'checked_in_at' => now()]);

        // สตาฟสแกน QR แล้วการ์ดต้องพลิกเดี๋ยวนั้น — ลูกค้ายืนดูอยู่ตรงนั้น
        Bus::assertDispatched(
            SyncTripActivityJob::class,
            fn (SyncTripActivityJob $job) => $job->bookingId === $this->booking->id,
        );
    }

    public function test_cancelling_pushes_the_card_off_the_lock_screen(): void
    {
        Bus::fake([SyncTripActivityJob::class]);

        $this->booking->update(['status' => 'cancelled']);

        Bus::assertDispatched(SyncTripActivityJob::class);
    }

    public function test_the_sweep_closes_cards_of_rounds_that_are_over(): void
    {
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);
        config([
            'services.apns.key_id' => 'K',
            'services.apns.team_id' => 'T',
            'services.apns.key_content' => $this->ecKey(),
        ]);

        $activity = LiveActivity::create([
            'user_id' => $this->owner->id,
            'booking_id' => $this->booking->id,
            'schedule_id' => $this->schedule->id,
            'push_token' => 'stale-token',
            'started_at' => now()->subDays(2),
        ]);

        // รอบจบไปแล้ว — ไม่มี loop ไหนแตะมันอีก ถ้าไม่กวาดตรงนี้การ์ดจะค้างข้ามวัน
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->subDays(3)->toDateString(),
            'departs_at' => now('Asia/Bangkok')->subDays(3),
            'return_date' => now('Asia/Bangkok')->subDays(2)->toDateString(),
        ]);

        $this->artisan('trip-activity:sync')->assertSuccessful();

        $this->assertNotNull($activity->fresh()->ended_at);
    }

    private function ecKey(): string
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($key, $exported);

        return $exported;
    }

    private function vehicleId(): int
    {
        return Vehicle::create([
            'name' => 'ตู้ 1',
            'license_plate' => 'กข-1234',
            'type' => 'van',
            'capacity' => 12,
            'status' => 'active',
        ])->id;
    }

    private function fakeVehicleLocation(float $lat, float $lng, float $speed): void
    {
        Redis::shouldReceive('get')
            ->andReturn(json_encode([
                'latitude' => $lat,
                'longitude' => $lng,
                'speed' => $speed,
                'recorded_at' => now()->toIso8601String(),
            ]));
    }
}
