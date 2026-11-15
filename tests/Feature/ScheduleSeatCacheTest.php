<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\TripController;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หน้ารายละเอียดทริปถามรอบซ้ำทุก 25 วินาที หน้าจองถามผังที่นั่งทุก 20 วินาที —
 * ทั้งสองจุดไม่ต้องนับที่นั่งใหม่จากตารางทุกครั้ง แต่ "เหลือกี่ที่" ต้องไม่ค้างหลังมีคนจอง
 */
class ScheduleSeatCacheTest extends TestCase
{
    use RefreshDatabase;

    private Trip $trip;

    private TripSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'seat-cache-'.uniqid(), 'type' => 'trekking', 'location' => 'เลย',
            'difficulty' => 'easy', 'duration_days' => 2, 'max_participants' => 20,
            'price_per_person' => 3000, 'status' => 'active',
        ]);
        $this->schedule = TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(30)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(31)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /** จำนวนครั้งที่นับผู้โดยสารจากตาราง (หัวใจของ syncBookedSeats) */
    private function recounts(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())
            ->filter(fn ($q) => stripos($q['query'], 'count(') !== false && str_contains($q['query'], 'booking_passengers'))
            ->count();
        DB::disableQueryLog();

        return $count;
    }

    private function book(int $people = 1): Booking
    {
        return app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $this->schedule->id,
            passengers: array_fill(0, $people, ['name' => 'ผู้เดินทาง', 'phone' => '0812345678']),
            verifySeatLocks: false,
        );
    }

    private function schedulesUrl(): string
    {
        return "/api/v1/trips/{$this->trip->slug}/schedules";
    }

    // ── รอบของทริป ──

    public function test_repeat_polls_of_a_trips_rounds_are_served_from_cache(): void
    {
        $this->getJson($this->schedulesUrl())->assertOk()->assertJsonPath('data.0.available_seats', 10);

        $this->assertSame(0, $this->recounts(fn () => $this->getJson($this->schedulesUrl())->assertOk()));
    }

    public function test_a_new_booking_shows_up_on_the_next_poll(): void
    {
        $this->getJson($this->schedulesUrl())->assertJsonPath('data.0.available_seats', 10);

        // BookingService นับที่นั่งด้วย increment() — ต้องล้างแคชด้วย ไม่ใช่รอหมดอายุ
        $this->book(people: 2);

        $this->getJson($this->schedulesUrl())->assertJsonPath('data.0.available_seats', 8);
    }

    public function test_the_cache_expires_on_its_own_after_a_few_seconds(): void
    {
        $this->getJson($this->schedulesUrl())->assertOk();
        $this->travel(TripController::SCHEDULES_CACHE_SECONDS + 1)->seconds();

        $this->assertGreaterThan(0, $this->recounts(fn () => $this->getJson($this->schedulesUrl())->assertOk()));
    }

    public function test_an_unknown_trip_is_still_a_404_and_is_not_cached(): void
    {
        $this->getJson('/api/v1/trips/no-such-trip/schedules')->assertNotFound();
        $this->getJson('/api/v1/trips/no-such-trip/schedules')->assertNotFound();
    }

    public function test_an_answer_built_for_staff_is_never_served_to_the_public(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // photos_shared ออกให้แอดมิน/โอเปอเรเตอร์เท่านั้น (TripScheduleResource)
        $this->actingAs($admin)->getJson($this->schedulesUrl())->assertJsonPath('data.0.photos_shared', false);

        $this->app['auth']->forgetGuards();
        $this->getJson($this->schedulesUrl())->assertOk()->assertJsonMissingPath('data.0.photos_shared');
    }

    // ── ผังที่นั่ง ──

    public function test_the_seat_map_does_not_recount_on_every_poll(): void
    {
        $user = User::factory()->create();
        $url = "/api/v1/schedules/{$this->schedule->id}/seats";

        $this->assertGreaterThan(0, $this->recounts(fn () => $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()));
        $this->assertSame(0, $this->recounts(fn () => $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()));
    }

    public function test_the_seat_map_recounts_right_after_a_booking_changes(): void
    {
        $user = User::factory()->create();
        $url = "/api/v1/schedules/{$this->schedule->id}/seats";
        $this->actingAs($user, 'sanctum')->getJson($url)->assertJsonPath('data.available_seats', 10);

        // เขียนใบจองตรง ๆ โดยไม่ผ่าน increment ของ BookingService — ตัวนับในรอบไม่ขยับ
        // ผังต้องนับใหม่เองทันที ไม่ใช่รอช่วงพักหมด
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(), 'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->schedule->id, 'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 3000,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'P', 'phone' => '0812345678']);

        $this->actingAs($user, 'sanctum')->getJson($url)->assertJsonPath('data.available_seats', 9);

        // ยกเลิกแล้วที่นั่งต้องกลับมาทันทีเช่นกัน
        $booking->update(['status' => 'cancelled']);
        $this->actingAs($user, 'sanctum')->getJson($url)->assertJsonPath('data.available_seats', 10);
    }

    public function test_moving_a_booking_to_another_round_recounts_both(): void
    {
        $user = User::factory()->create();
        $other = TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(40)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(41)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(), 'user_id' => User::factory()->create()->id,
            'schedule_id' => $this->schedule->id, 'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 3000,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'P', 'phone' => '0812345678']);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/schedules/{$this->schedule->id}/seats")->assertJsonPath('data.available_seats', 9);
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/schedules/{$other->id}/seats")->assertJsonPath('data.available_seats', 10);

        $booking->update(['schedule_id' => $other->id]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/schedules/{$this->schedule->id}/seats")->assertJsonPath('data.available_seats', 10);
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/schedules/{$other->id}/seats")->assertJsonPath('data.available_seats', 9);
    }
}
