<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * รอบที่รถออกไปแล้วต้องปิดรับจองตั้งแต่เวลารถออก — เดิมเปิดค้างไว้จนหมดวันเดินทาง
 * (ทริปออก 00:00 แต่ยังจองได้ถึงบ่าย) เพราะทุกจุดเทียบแค่ departure_date
 */
class DepartedScheduleBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ตั้งนาฬิกาเป็นเวลาไทย (แอปรันเป็น UTC) */
    private function atThai(string $wallClock): void
    {
        Carbon::setTestNow(Carbon::parse($wallClock, 'Asia/Bangkok')->utc());
    }

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริป '.uniqid(),
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 3000,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => '2026-11-22',
            'return_date' => '2026-11-23',
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function book(TripSchedule $schedule, bool $skipPayment = false)
    {
        return app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: [['name' => 'ผู้เดินทาง', 'phone' => '0812345678']],
            verifySeatLocks: false,
            skipPayment: $skipPayment,
        );
    }

    private function listedIds(TripSchedule $schedule): array
    {
        return collect($this->getJson("/api/v1/trips/{$schedule->trip->slug}/schedules")
            ->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_round_that_left_at_midnight_cannot_be_booked_later_that_day(): void
    {
        $schedule = $this->makeSchedule(['departs_at' => '2026-11-22 00:00:00']);

        $this->atThai('2026-11-21 23:50:00');
        $this->assertFalse($schedule->fresh()->hasDeparted());
        $this->assertSame([$schedule->id], $this->listedIds($schedule));

        // สายของวันเดินทาง — รายงานจริงจากลูกค้า
        $this->atThai('2026-11-22 10:30:00');
        $this->assertTrue($schedule->fresh()->hasDeparted());
        $this->assertSame([], $this->listedIds($schedule));
        $this->assertSame([], $this->getJson("/api/v1/trips/{$schedule->trip->slug}")->json('data.schedules'));

        $this->expectExceptionMessageMatches('/ออกเดินทางไปแล้ว/');
        $this->book($schedule);
    }

    public function test_a_round_leaving_tonight_stays_on_sale_until_it_leaves_in_thai_time(): void
    {
        $schedule = $this->makeSchedule(['departs_at' => '2026-11-22 23:30:00']);

        $this->atThai('2026-11-22 15:00:00');
        $this->assertFalse($schedule->fresh()->hasDeparted());
        $this->assertSame([$schedule->id], $this->listedIds($schedule));
        $this->assertFalse($this->getJson("/api/v1/trips/{$schedule->trip->slug}/schedules")->json('data.0.has_departed'));
        $this->assertNotNull($this->book($schedule));

        // 23:45 ไทย = 16:45 UTC — การเทียบกับ now() (UTC) ตรง ๆ จะยังบอกว่า "ยังไม่ออก"
        $this->atThai('2026-11-22 23:45:00');
        $this->assertTrue($schedule->fresh()->hasDeparted());
        $this->assertSame([], $this->listedIds($schedule));
    }

    public function test_a_round_without_a_departure_time_closes_at_the_start_of_its_day(): void
    {
        $schedule = $this->makeSchedule(['departs_at' => null]);

        $this->atThai('2026-11-21 23:00:00');
        $this->assertFalse($schedule->fresh()->hasDeparted());
        $this->assertSame([$schedule->id], $this->listedIds($schedule));

        $this->atThai('2026-11-22 08:00:00');
        $this->assertTrue($schedule->fresh()->hasDeparted());
        $this->assertSame([], $this->listedIds($schedule));
    }

    public function test_a_round_leaving_the_night_before_closes_when_the_van_leaves(): void
    {
        // ทริปวันอาทิตย์ รถออกคืนวันเสาร์ 21:00
        $schedule = $this->makeSchedule([
            'departure_date' => '2026-11-22',
            'departs_at' => '2026-11-21 21:00:00',
        ]);

        $this->atThai('2026-11-21 20:00:00');
        $this->assertFalse($schedule->fresh()->hasDeparted());

        $this->atThai('2026-11-21 22:00:00');
        $this->assertTrue($schedule->fresh()->hasDeparted());
        $this->assertSame([], $this->listedIds($schedule));
        $this->assertFalse($schedule->fresh()->acceptsNewCustomers());
    }

    public function test_seat_lock_is_refused_once_the_round_has_left(): void
    {
        $schedule = $this->makeSchedule(['departs_at' => '2026-11-22 00:00:00']);
        $this->atThai('2026-11-22 09:00:00');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/seats/lock", ['seat_ids' => ['A1']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'รอบนี้ออกเดินทางไปแล้ว ไม่สามารถจองได้ กรุณาเลือกรอบอื่น');
    }

    public function test_admin_can_still_record_a_skip_payment_booking_after_departure(): void
    {
        // ลงใบจองของคนที่ขึ้นรถหน้างานย้อนหลัง — ช่องทางของแอดมินเท่านั้น
        $schedule = $this->makeSchedule(['departs_at' => '2026-11-22 00:00:00']);
        $this->atThai('2026-11-22 09:00:00');

        $this->assertNotNull($this->book($schedule, skipPayment: true));
    }

    public function test_a_flash_sale_ends_when_the_van_leaves(): void
    {
        $schedule = $this->makeSchedule([
            'departs_at' => '2026-11-22 23:30:00',
            'flash_sale_enabled' => true,
            'flash_sale_price' => 2500,
        ]);

        $this->atThai('2026-11-22 23:00:00');
        $this->assertTrue($schedule->fresh()->flashSaleActive());

        $this->atThai('2026-11-22 23:45:00');
        $this->assertFalse($schedule->fresh()->flashSaleActive());
    }
}
