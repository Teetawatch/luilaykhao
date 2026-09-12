<?php

namespace Tests\Feature;

use App\Mail\TripBriefMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ScheduleItineraryItem;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripBriefService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "ใบเดินทาง" — หน้า /t/{token} ที่เปิดได้โดยไม่ต้องล็อกอินและไม่ต้องมีแอป
 */
class TripBriefTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-09-10 10:00 Bangkok — ทริปในเทสต์ออกเดินทาง 2026-09-12
        Carbon::setTestNow(Carbon::parse('2026-09-10 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'hard',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 4500,
            'status' => 'active',
            'checkin_bring' => 'บัตรประชาชน',
        ], $overrides));
    }

    private function schedule(?Trip $trip = null, array $overrides = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => ($trip ?: $this->trip())->id,
            'departure_date' => '2026-09-12',
            'return_date' => '2026-09-13',
            'total_seats' => 10,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function booking(TripSchedule $schedule, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['name' => 'สมชาย ใจดี'])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 4500,
            'paid_amount' => 4500,
        ], $overrides));
    }

    public function test_page_shows_crew_driver_pickup_and_itinerary(): void
    {
        $schedule = $this->schedule();

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 10,
            'license_plate' => 'ฮข 1234 กทม', 'color' => 'ขาว',
            'driver_name' => 'ลุงชัย', 'driver_phone' => '0891234567',
        ]);
        $schedule->update(['vehicle_id' => $vehicle->id]);

        $staff = User::factory()->create(['name' => 'ปิยะ สายลม', 'nickname' => 'พี่ปิ', 'phone' => '0812223333']);
        $schedule->staff()->attach($staff->id);

        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok', 'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี ขาออก',
            'price' => 4500, 'pickup_time' => '20:30:00',
            'map_url' => 'https://maps.app.goo.gl/abc',
        ]);

        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id,
            'item_date' => '2026-09-12', 'time' => '05:00:00',
            'title' => 'ถึงที่ทำการอุทยาน', 'sort_order' => 1,
        ]);

        $booking = $this->booking($schedule, ['pickup_point_id' => $point->id]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมชาย ใจดี',
            'phone' => '0899999999', 'pickup_point_id' => $point->id,
        ]);

        $response = $this->get('/t/'.$booking->ensureBriefToken());

        $response->assertOk();
        $response->assertSee('ดอยหลวงเชียงดาว');
        $response->assertSee('ปั๊ม ปตท. วิภาวดี ขาออก');
        $response->assertSee('20:30');
        $response->assertSee('พี่ปิ');
        $response->assertSee('0812223333');
        $response->assertSee('ลุงชัย');
        $response->assertSee('0891234567');
        $response->assertSee('ฮข 1234 กทม');
        $response->assertSee('ถึงที่ทำการอุทยาน');
        // ไม่ต้องล็อกอิน ไม่ต้องมีแอป
        $this->assertGuest();
    }

    public function test_itinerary_is_never_truncated(): void
    {
        $schedule = $this->schedule();

        foreach (range(1, 14) as $i) {
            ScheduleItineraryItem::create([
                'schedule_id' => $schedule->id,
                'item_date' => '2026-09-12',
                'time' => sprintf('%02d:00:00', $i),
                'title' => "รายการที่ {$i}",
                'sort_order' => $i,
            ]);
        }

        $booking = $this->booking($schedule);
        $response = $this->get('/t/'.$booking->ensureBriefToken());

        $response->assertOk();
        foreach (range(1, 14) as $i) {
            $response->assertSee("รายการที่ {$i}");
        }
    }

    public function test_warns_when_the_van_leaves_the_night_before(): void
    {
        // รถออก 22:00 ของวันที่ 11 แต่วันทริปคือ 12
        $schedule = $this->schedule(null, ['departs_at' => '2026-09-11 22:00:00']);
        $booking = $this->booking($schedule);

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertSee('รถออกก่อนวันทริป');
    }

    public function test_day_only_round_never_prints_a_midnight_that_was_never_set(): void
    {
        $schedule = $this->schedule(null, ['departs_at' => null]);
        $booking = $this->booking($schedule);

        $brief = app(TripBriefService::class)->payload($booking->fresh());

        $this->assertNull($brief['when']['time_label']);
        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertDontSee('00:00');
    }

    public function test_flight_round_shows_the_meetup_not_a_pickup_point(): void
    {
        $trip = $this->trip(['destination_type' => 'international', 'country_code' => 'JP']);
        $schedule = $this->schedule($trip, [
            'transport_type' => 'flight',
            'departs_at' => '2026-09-12 06:00:00',
            'meeting_point' => 'สนามบินสุวรรณภูมิ เคาน์เตอร์ Row K',
            'meeting_time' => '03:00',
            'baggage_allowance' => 'โหลด 20 กก. ถือขึ้นเครื่อง 7 กก.',
        ]);
        $booking = $this->booking($schedule);

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertSee('สนามบินสุวรรณภูมิ เคาน์เตอร์ Row K')
            ->assertSee('โหลด 20 กก. ถือขึ้นเครื่อง 7 กก.')
            ->assertSee('จุดนัดพบ')
            ->assertDontSee('จุดขึ้นรถ');
    }

    public function test_shows_outstanding_balance(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule, [
            'total_amount' => 9000,
            'paid_amount' => 3000,
            'payment_type' => 'deposit',
            'balance_amount' => 6000,
            'balance_due_at' => '2026-09-11',
        ]);

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertSee('ยอดที่ยังค้างอยู่')
            ->assertSee('฿6,000');
    }

    public function test_cancelled_booking_and_unknown_token_return_404(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule, ['status' => 'cancelled']);

        $this->get('/t/'.$booking->ensureBriefToken())->assertNotFound();
        $this->get('/t/doesnotexist')->assertNotFound();
    }

    public function test_link_expires_a_few_days_after_the_trip_ends(): void
    {
        $schedule = $this->schedule(null, [
            'departure_date' => '2026-09-01',
            'return_date' => '2026-09-02',
        ]);
        $booking = $this->booking($schedule);
        $token = $booking->ensureBriefToken();

        // จบทริปไปแล้วเกินหน้าต่างที่กำหนด — เบอร์ทีมงานต้องไม่ค้างอยู่ในลิงก์เก่า
        $this->get('/t/'.$token)->assertNotFound();
    }

    public function test_released_staff_are_not_listed(): void
    {
        $schedule = $this->schedule();
        $current = User::factory()->create(['nickname' => 'พี่ใหม่', 'phone' => '0811111111']);
        $released = User::factory()->create(['nickname' => 'พี่เก่า', 'phone' => '0822222222']);

        $schedule->staff()->attach($current->id);
        $schedule->staff()->attach($released->id, ['released_at' => now()]);

        $booking = $this->booking($schedule);

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertSee('พี่ใหม่')
            ->assertDontSee('พี่เก่า');
    }

    public function test_email_renders_the_same_facts_as_the_page(): void
    {
        $schedule = $this->schedule(null, ['departs_at' => '2026-09-11 22:00:00']);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 10,
            'license_plate' => 'ฮข 1234 กทม', 'driver_name' => 'ลุงชัย', 'driver_phone' => '0891234567',
        ]);
        $schedule->update(['vehicle_id' => $vehicle->id]);
        $schedule->staff()->attach(User::factory()->create(['nickname' => 'พี่ปิ', 'phone' => '0812223333'])->id);

        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี', 'price' => 4500, 'pickup_time' => '20:30:00',
        ]);

        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id, 'item_date' => '2026-09-12',
            'time' => '05:00:00', 'title' => 'ถึงที่ทำการอุทยาน', 'sort_order' => 1,
        ]);

        $booking = $this->booking($schedule, ['pickup_point_id' => $point->id]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมชาย ใจดี', 'pickup_point_id' => $point->id,
        ]);

        $html = (new TripBriefMail($booking->fresh()))->render();

        $this->assertStringContainsString('ปั๊ม ปตท. วิภาวดี', $html);
        $this->assertStringContainsString('20:30', $html);
        $this->assertStringContainsString('พี่ปิ', $html);
        $this->assertStringContainsString('0812223333', $html);
        $this->assertStringContainsString('ลุงชัย', $html);
        $this->assertStringContainsString('ฮข 1234 กทม', $html);
        $this->assertStringContainsString('ถึงที่ทำการอุทยาน', $html);
        $this->assertStringContainsString('รถออกก่อนวันทริป', $html);
        // ทุกฉบับต้องจบด้วยลิงก์หน้าที่อัปเดตตัวเองได้
        $this->assertStringContainsString('/t/'.$booking->fresh()->brief_token, $html);
    }

    public function test_update_edition_says_so_in_the_subject(): void
    {
        $booking = $this->booking($this->schedule());

        $first = new TripBriefMail($booking);
        $update = new TripBriefMail($booking, isUpdate: true);

        $this->assertStringContainsString('ใบเดินทาง', $first->envelope()->subject);
        $this->assertStringContainsString('อัปเดตใบเดินทาง', $update->envelope()->subject);
    }

    public function test_brief_token_is_distinct_from_the_tracking_token(): void
    {
        $booking = $this->booking($this->schedule());

        $this->assertNotSame($booking->ensureShareToken(), $booking->ensureBriefToken());
        $this->assertSame($booking->ensureBriefToken(), $booking->fresh()->ensureBriefToken());
    }
}
