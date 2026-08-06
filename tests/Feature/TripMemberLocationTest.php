<?php

namespace Tests\Feature;

use App\Events\TripMemberLocationUpdated;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Trip;
use App\Models\TripMemberLocation;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\TripMemberLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * "เพื่อนร่วมทริปอยู่ตรงไหน"
 *
 * นี่คือตำแหน่งของคนจริง เทสต์ชุดนี้จึงล็อกขอบเขตไว้แน่นกว่าฟีเจอร์อื่น: ใครดูได้
 * ดูได้เมื่อไหร่ ปิดแล้วหายจริงไหม และหมุดเก่าต้องไม่ถูกส่งออกไปหลอกใคร
 */
class TripMemberLocationTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private User $owner;

    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();

        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว', 'slug' => 'live-loc-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'hard', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3500, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 2, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $this->owner = User::factory()->create(['name' => 'ต้น']);
        $this->friend = User::factory()->create(['name' => 'หนึ่ง']);

        $this->confirmedBooking($this->owner);
        $this->confirmedBooking($this->friend);
    }

    public function test_member_can_share_and_others_in_the_round_see_it(): void
    {
        Event::fake([TripMemberLocationUpdated::class]);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/schedules/{$this->schedule->id}/live-location", [
                'latitude' => 19.3900,
                'longitude' => 98.9200,
                'battery_level' => 64,
            ])
            ->assertOk()
            ->assertJsonPath('data.me.name', 'ต้น');

        Event::assertDispatched(TripMemberLocationUpdated::class);

        $this->actingAs($this->friend)
            ->getJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->assertOk()
            ->assertJsonPath('data.members.0.name', 'ต้น')
            ->assertJsonPath('data.members.0.battery_level', 64)
            // ตัวเองยังไม่ได้แชร์ สวิตช์ในแอปต้องปิดอยู่
            ->assertJsonPath('data.sharing', false);
    }

    public function test_the_list_never_includes_yourself(): void
    {
        $this->share($this->owner);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->assertOk()
            ->assertJsonPath('data.members', [])
            ->assertJsonPath('data.sharing', true);
    }

    public function test_repeated_updates_overwrite_instead_of_piling_up(): void
    {
        $this->share($this->owner, lat: 19.39);
        $this->share($this->owner, lat: 19.40);

        // ตารางนี้เก็บ "ตอนนี้อยู่ไหน" ไม่ใช่ประวัติการเดิน — แถวต้องมีใบเดียว
        $this->assertDatabaseCount('trip_member_locations', 1);
        $this->assertEqualsWithDelta(
            19.40,
            TripMemberLocation::first()->latitude,
            0.0001,
        );
    }

    public function test_stopping_deletes_the_row_and_tells_the_others(): void
    {
        $this->share($this->owner);
        Event::fake([TripMemberLocationUpdated::class]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->assertOk();

        // "ปิดแล้วต้องหายจริง" ไม่ใช่แค่ซ่อน
        $this->assertDatabaseCount('trip_member_locations', 0);
        Event::assertDispatched(TripMemberLocationUpdated::class);
    }

    public function test_stale_pins_are_not_handed_out(): void
    {
        $this->share($this->owner);

        TripMemberLocation::query()->update([
            'recorded_at' => now()->subMinutes(TripMemberLocationService::STALE_MINUTES + 5),
        ]);

        // หมุดที่นิ่งมาครึ่งชั่วโมงบนดอยไม่ได้แปลว่าคนนั้นยืนอยู่ตรงนั้น การวาดมัน
        // ต่อไปคือการโกหกคนที่กำลังตามหา
        $this->actingAs($this->friend)
            ->getJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->assertOk()
            ->assertJsonPath('data.members', []);
    }

    public function test_companion_on_a_booking_counts_as_a_member(): void
    {
        $companion = User::factory()->create(['name' => 'สอง']);
        BookingMember::create([
            'booking_id' => Booking::where('user_id', $this->owner->id)->first()->id,
            'user_id' => $companion->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($companion)
            ->postJson("/api/v1/schedules/{$this->schedule->id}/live-location", [
                'latitude' => 19.39,
                'longitude' => 98.92,
            ])
            ->assertOk();
    }

    public function test_outsiders_are_refused(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->assertNotFound();

        $this->actingAs($stranger)
            ->postJson("/api/v1/schedules/{$this->schedule->id}/live-location", [
                'latitude' => 19.39,
                'longitude' => 98.92,
            ])
            ->assertNotFound();
    }

    public function test_sharing_is_refused_outside_the_trip_window(): void
    {
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
        ]);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/schedules/{$this->schedule->id}/live-location", [
                'latitude' => 19.39,
                'longitude' => 98.92,
            ])
            ->assertStatus(422);
    }

    public function test_stopping_still_works_after_the_trip_ends(): void
    {
        $this->share($this->owner);

        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->subMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->subMonth()->addDay()->toDateString(),
        ]);

        // "เลิกแชร์" ต้องทำได้เสมอ — ประตูที่เปิดค้างแล้วล็อกตัวเองไม่ได้คือบั๊ก
        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/schedules/{$this->schedule->id}/live-location")
            ->assertOk();

        $this->assertDatabaseCount('trip_member_locations', 0);
    }

    private function share(User $user, float $lat = 19.39): void
    {
        $this->actingAs($user)
            ->postJson("/api/v1/schedules/{$this->schedule->id}/live-location", [
                'latitude' => $lat,
                'longitude' => 98.92,
            ])
            ->assertOk();
    }

    private function confirmedBooking(User $user): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3500,
            'paid_amount' => 3500,
        ]);
    }
}
