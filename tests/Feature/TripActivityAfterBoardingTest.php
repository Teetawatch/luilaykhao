<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ScheduleAnnouncement;
use App\Models\ScheduleItineraryItem;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\TripActivityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * การ์ด "วันเดินทาง" หลังขึ้นรถ — ต้องมีอะไรให้ดูต่อเสมอ ไม่แช่ "ขึ้นรถเรียบร้อยแล้ว"
 *
 * เดิมการ์ดเดินต่อได้เฉพาะรอบที่มีกำหนดการ *และ* ทีมงานกดหมุดหน้างาน ซึ่งเป็น
 * ส่วนน้อย ที่เหลือค้างทั้งทริป ชุดนี้ล็อกทางสำรองทุกทาง: เวลาในแผน, วันของทริป,
 * ประกาศจากทีมงาน, และขากลับที่จับจากพิกัดรถเอง
 *
 * นาฬิกาถูกตรึงเป็นเวลาไทยทุกเทสต์ — ตรรกะชุดนี้ขึ้นกับ "ตอนนี้กี่โมง" ทั้งหมด
 */
class TripActivityAfterBoardingTest extends TestCase
{
    use RefreshDatabase;

    /** จุดรับ/จุดส่งของใบจอง — ปั๊มรังสิต */
    private const PICKUP_LAT = 13.95;

    private const PICKUP_LNG = 100.62;

    /** 1 กม. ตามแนวเหนือ-ใต้ ≈ องศาละติจูดนี้ */
    private const KM = 1 / 111.2;

    protected function setUp(): void
    {
        parent::setUp();

        // ไม่มีแคชตำแหน่งรถ — ให้อ่านพิกัดจริงจากฐานข้อมูล ซึ่งเทสต์คุมได้ทีละแถว
        Redis::shouldReceive('get')->andReturn(null);
        config(['services.google_maps.api_key' => '']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── A: วันของทริป (รอบที่ไม่มีกำหนดการ) ─────────────────────────────────

    public function test_a_round_without_an_itinerary_says_which_day_of_the_trip_it_is(): void
    {
        $this->at('2026-10-10 14:00');
        $booking = $this->booking('2026-10-10', '2026-10-12');

        $state = $this->state($booking);

        $this->assertSame('trip_day', $state['stage']);
        $this->assertSame('ทริปวันที่ 1 จาก 3', $state['headline']);
        $this->assertStringContainsString('เดินทางกลับ 12 ต.ค. 2569', $state['detail']);
        $this->assertNull($state['eta_minutes']);
    }

    public function test_the_day_before_going_home_says_tomorrow(): void
    {
        $this->at('2026-10-11 09:00');
        $booking = $this->booking('2026-10-10', '2026-10-12', checkedInHoursAgo: 24);

        $state = $this->state($booking);

        $this->assertSame('ทริปวันที่ 2 จาก 3', $state['headline']);
        $this->assertStringContainsString('พรุ่งนี้เดินทางกลับ', $state['detail']);
    }

    public function test_the_last_day_says_going_home_today(): void
    {
        $this->at('2026-10-12 08:00');
        $booking = $this->booking('2026-10-10', '2026-10-12', checkedInHoursAgo: 48);

        $state = $this->state($booking);

        $this->assertSame('trip_day', $state['stage']);
        $this->assertSame('วันนี้เดินทางกลับ', $state['headline']);
    }

    public function test_a_day_trip_names_the_drop_off_point(): void
    {
        $this->at('2026-10-10 10:00');
        $booking = $this->booking('2026-10-10', '2026-10-10');

        $state = $this->state($booking);

        $this->assertSame('อยู่ระหว่างทริป', $state['headline']);
        $this->assertStringContainsString('ขากลับส่งที่ ปั๊ม ปตท. รังสิต', $state['detail']);
    }

    public function test_a_van_that_leaves_the_night_before_says_the_trip_starts_tomorrow(): void
    {
        $this->at('2026-10-09 23:30');
        $booking = $this->booking('2026-10-10', '2026-10-11', departsAt: '2026-10-09 21:00:00', checkedInHoursAgo: 2);

        $state = $this->state($booking);

        $this->assertSame('trip_day', $state['stage']);
        $this->assertSame('อยู่ระหว่างเดินทาง', $state['headline']);
        $this->assertStringContainsString('ถึงวันแรกของทริปพรุ่งนี้', $state['detail']);
    }

    public function test_the_boarding_confirmation_still_comes_first(): void
    {
        $this->at('2026-10-10 06:05');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 0);

        $state = $this->state($booking);

        $this->assertSame('onboard', $state['stage']);
        $this->assertSame('ขึ้นรถเรียบร้อยแล้ว', $state['headline']);
    }

    // ── B: เดินตามเวลาในแผนเมื่อไม่มีใครกดหมุด ───────────────────────────────

    public function test_nobody_ticking_the_itinerary_follows_the_planned_times(): void
    {
        $this->at('2026-10-10 11:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->item($booking, '2026-10-10', '05:00', 'ออกจากกรุงเทพฯ');
        $this->item($booking, '2026-10-10', '07:30', 'แวะพักปั๊มสิงห์บุรี');
        $this->item($booking, '2026-10-10', '12:00', 'อาหารกลางวันที่ร้านป้าแดง');
        $this->item($booking, '2026-10-10', '16:00', 'เข้าที่พัก');

        $state = $this->state($booking);

        // เดิมการ์ดแช่ "ออกจากกรุงเทพฯ" ทั้งวัน เพราะไม่มีใครกดหมุดแรก
        $this->assertSame('itinerary', $state['stage']);
        $this->assertSame('12:00 น. · อาหารกลางวันที่ร้านป้าแดง', $state['headline']);
        $this->assertStringContainsString('ตามแผน', $state['detail']);
        $this->assertStringContainsString('จุดที่ 3 จาก 4', $state['detail']);
        $this->assertSame(0.5, $state['progress']);
    }

    public function test_ticks_that_stopped_hours_ago_give_way_to_the_plan(): void
    {
        $this->at('2026-10-10 15:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->item($booking, '2026-10-10', '05:00', 'ออกจากกรุงเทพฯ', reachedMinutesAgo: 9 * 60);
        $this->item($booking, '2026-10-10', '07:30', 'แวะพักปั๊มสิงห์บุรี');
        $this->item($booking, '2026-10-10', '12:00', 'อาหารกลางวัน');
        $this->item($booking, '2026-10-10', '16:00', 'เข้าที่พัก');

        $state = $this->state($booking);

        $this->assertSame('16:00 น. · เข้าที่พัก', $state['headline']);
        $this->assertStringContainsString('ทีมงานยืนยันแล้ว 1 จาก 4 จุด', $state['detail']);
    }

    public function test_fresh_ticks_still_beat_the_clock(): void
    {
        // แผนเลื่อนได้ หมุดที่เพิ่งกดคือสิ่งที่เกิดขึ้นจริง
        $this->at('2026-10-10 11:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->item($booking, '2026-10-10', '05:00', 'ออกจากกรุงเทพฯ', reachedMinutesAgo: 30);
        $this->item($booking, '2026-10-10', '07:30', 'แวะพักปั๊มสิงห์บุรี');
        $this->item($booking, '2026-10-10', '12:00', 'อาหารกลางวัน');

        $state = $this->state($booking);

        $this->assertSame('07:30 น. · แวะพักปั๊มสิงห์บุรี', $state['headline']);
        $this->assertStringContainsString('ผ่านมาแล้ว 1 จาก 3 จุด', $state['detail']);
    }

    public function test_the_next_stop_on_another_day_says_which_day(): void
    {
        $this->at('2026-10-10 21:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->item($booking, '2026-10-10', '16:00', 'เข้าที่พัก');
        $this->item($booking, '2026-10-11', '05:30', 'ดูทะเลหมอก');

        $state = $this->state($booking);

        // "05:30 น." เปล่า ๆ ตอนสามทุ่ม อ่านแล้วเหมือนตีห้าครึ่งที่ผ่านไปแล้ว
        $this->assertSame('พรุ่งนี้ 05:30 น. · ดูทะเลหมอก', $state['headline']);
    }

    public function test_database_time_columns_with_seconds_read_the_same(): void
    {
        $this->at('2026-10-10 09:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->item($booking, '2026-10-10', '10:00:00', 'ถึงจุดชมวิวผาตั้ง');

        $this->assertSame('10:00 น. · ถึงจุดชมวิวผาตั้ง', $this->state($booking)['headline']);
    }

    public function test_a_plan_that_has_run_out_hands_over_to_the_trip_day_card(): void
    {
        $this->at('2026-10-11 20:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 30);
        $this->item($booking, '2026-10-11', '09:00', 'ลงดอย');

        $state = $this->state($booking);

        $this->assertSame('trip_day', $state['stage']);
        $this->assertSame('วันนี้เดินทางกลับ', $state['headline']);
    }

    public function test_an_untimed_unticked_itinerary_does_not_freeze_on_its_first_stop(): void
    {
        $this->at('2026-10-10 11:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->item($booking, '2026-10-10', null, 'ออกจากกรุงเทพฯ');
        $this->item($booking, '2026-10-10', null, 'เข้าที่พัก');

        $this->assertSame('trip_day', $this->state($booking)['stage']);
    }

    // ── D: ประกาศจากทีมงาน ────────────────────────────────────────────────

    public function test_a_fresh_announcement_takes_over_the_card_for_a_while(): void
    {
        $this->at('2026-10-10 11:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->announce($booking, 'ฝนตกหนัก เลื่อนเดินขึ้นยอด', "ขอเลื่อนเป็น 14:00 น.\nรอที่ลานจอดก่อนนะครับ", minutesAgo: 5);

        $state = $this->state($booking);

        $this->assertSame('announcement', $state['stage']);
        $this->assertSame('📢 ฝนตกหนัก เลื่อนเดินขึ้นยอด', $state['headline']);
        $this->assertSame('ขอเลื่อนเป็น 14:00 น. รอที่ลานจอดก่อนนะครับ', $state['detail']);
    }

    public function test_an_old_announcement_leaves_the_card_alone(): void
    {
        $this->at('2026-10-10 11:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->announce($booking, 'ฝนตกหนัก', 'เลื่อนเวลา', minutesAgo: 45);

        $this->assertSame('trip_day', $this->state($booking)['stage']);
    }

    public function test_an_announcement_reaches_people_still_waiting_to_be_picked_up(): void
    {
        $this->at('2026-10-10 05:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: null);
        $this->announce($booking, 'รถออกช้า 20 นาที', 'รถติดอุบัติเหตุหน้าหมู่บ้าน', minutesAgo: 2);

        $state = $this->state($booking);

        $this->assertSame('announcement', $state['stage']);
        $this->assertSame('📢 รถออกช้า 20 นาที', $state['headline']);
    }

    public function test_an_announcement_never_covers_the_boarding_confirmation(): void
    {
        $this->at('2026-10-10 06:05');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 0);
        $this->announce($booking, 'แจ้งเตือน', 'ทดสอบ', minutesAgo: 1);

        $this->assertSame('onboard', $this->state($booking)['stage']);
    }

    // ── E: ขากลับ ─────────────────────────────────────────────────────────

    public function test_a_van_heading_home_on_the_last_day_shows_when_it_arrives(): void
    {
        $this->at('2026-10-11 17:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 30);
        $this->headingHome($booking, 100, 80, 60);

        $state = $this->state($booking);

        // 60 กม. เส้นตรง → ~75 กม. ถนน ที่ 70 กม./ชม. ≈ 65 นาที → 18:05
        $this->assertSame('returning', $state['stage']);
        $this->assertSame('ขากลับ · ถึงราว 18:05 น.', $state['headline']);
        $this->assertStringContainsString('จุดส่ง ปั๊ม ปตท. รังสิต', $state['detail']);
        // ขากลับยาว ๆ ไม่โชว์นาทีบน Dynamic Island — เวลาถึงบนหัวการ์ดพอแล้ว
        $this->assertNull($state['eta_minutes']);
        $this->assertEqualsWithDelta(60, $state['distance_km'], 0.5);
    }

    public function test_a_single_move_toward_home_is_not_the_return_leg(): void
    {
        // เช้าวันกลับรถขับไปจุดชมวิวที่อยู่ทางเดียวกับบ้าน — ช่วงเดียวยังไม่นับ
        $this->at('2026-10-11 09:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 26);
        $this->headingHome($booking, 100, 100, 95);

        $this->assertSame('trip_day', $this->state($booking)['stage']);
    }

    public function test_driving_toward_home_on_a_middle_day_is_not_the_return_leg(): void
    {
        $this->at('2026-10-11 17:00');
        $booking = $this->booking('2026-10-10', '2026-10-12', checkedInHoursAgo: 30);
        $this->headingHome($booking, 100, 80, 60);

        $this->assertNotSame('returning', $this->state($booking)['stage']);
    }

    public function test_the_return_leg_survives_a_rest_stop(): void
    {
        $this->at('2026-10-11 17:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 30);
        $this->headingHome($booking, 100, 80, 60);
        $this->assertSame('returning', $this->state($booking)['stage']);

        // จอดแวะปั๊ม 40 นาที — ไม่ได้เข้าใกล้บ้านเลยช่วงนี้ แต่ยังขากลับอยู่
        $this->at('2026-10-11 17:40');
        $this->pin($booking, 60, minutesAgo: 0);

        $this->assertSame('returning', $this->state($booking)['stage']);
    }

    public function test_the_last_stretch_counts_down_in_minutes_and_alerts(): void
    {
        $this->at('2026-10-11 17:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 30);
        $this->headingHome($booking, 20, 12, 5, speed: 40);

        $state = $this->state($booking);

        $this->assertSame('dropoff_soon', $state['stage']);
        $this->assertSame('อีก 9 นาทีถึงจุดส่ง', $state['headline']);
        $this->assertStringContainsString('เก็บของให้พร้อม', $state['detail']);
        $this->assertSame(9, $state['eta_minutes']);
    }

    public function test_dropping_off_says_so_then_closes_the_card(): void
    {
        $this->at('2026-10-11 17:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 30);
        $this->headingHome($booking, 10, 5, 0.2, speed: 5);

        $this->assertSame('dropoff', $this->state($booking)['stage']);

        $this->at('2026-10-11 17:20');
        $this->assertSame('dropoff', $this->state($booking)['stage']);

        // ส่งถึงแล้วครึ่งชั่วโมง — ทริปจบจริง การ์ดไม่ควรแช่อยู่จนเที่ยงคืน
        $this->at('2026-10-11 17:31');
        $this->assertNull($this->state($booking));
    }

    public function test_a_van_that_stops_near_the_pin_and_drives_on_counts_as_dropped_off(): void
    {
        $this->at('2026-10-11 17:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 30);
        $this->headingHome($booking, 10, 5, 0.8, speed: 5);
        $this->assertSame('dropoff_soon', $this->state($booking)['stage']);

        // จอดห่างหมุด 800 ม. แล้ววิ่งต่อไปส่งคนถัดไป
        $this->at('2026-10-11 17:10');
        $this->pin($booking, -2.5, minutesAgo: 0, speed: 40);

        $this->assertSame('dropoff', $this->state($booking)['stage']);
    }

    public function test_google_is_asked_for_long_return_legs_but_not_every_minute(): void
    {
        config([
            'services.google_maps.api_key' => 'test-key',
            'services.google_maps.distance_matrix_url' => 'https://maps.googleapis.com/maps/api/distancematrix/json',
        ]);
        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'origin_addresses' => ['a'],
            'destination_addresses' => ['b'],
            'rows' => [['elements' => [[
                'status' => 'OK',
                'distance' => ['text' => '80 กม.', 'value' => 80000],
                'duration' => ['text' => '1 ชม. 30 นาที', 'value' => 5400],
                'duration_in_traffic' => ['text' => '2 ชม.', 'value' => 7200],
            ]]]],
        ])]);

        $this->at('2026-10-11 17:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 30);
        $this->headingHome($booking, 100, 80, 60);

        // รถติดขาเข้ากรุงเทพฯ — Google รู้ เส้นตรงไม่รู้
        $this->assertSame('ขากลับ · ถึงราว 19:00 น.', $this->state($booking)['headline']);

        $this->at('2026-10-11 17:01');
        $this->pin($booking, 59, minutesAgo: 0, speed: 60);
        $this->assertSame('ขากลับ · ถึงราว 19:00 น.', $this->state($booking)['headline']);

        Http::assertSentCount(1);
    }

    public function test_the_sync_buzzes_once_when_the_drop_off_is_near(): void
    {
        $this->at('2026-10-11 17:00');
        $booking = $this->booking('2026-10-10', '2026-10-11', checkedInHoursAgo: 30);
        $this->headingHome($booking, 20, 12, 5, speed: 40);

        $alert = (function (?string $previous, array $state) {
            return $this->alertFor($previous, $state);
        })
            ->call(app(TripActivityService::class), 'returning', $this->state($booking));

        $this->assertNotNull($alert);
        $this->assertSame('อีก 9 นาทีถึงจุดส่ง', $alert['title']);
    }

    public function test_announcements_update_the_card_without_buzzing(): void
    {
        $this->at('2026-10-10 11:00');
        $booking = $this->booking('2026-10-10', '2026-10-11');
        $this->announce($booking, 'แจ้งเตือน', 'ทดสอบ', minutesAgo: 1);

        // ประกาศมี push ของมันเองแล้ว สั่นซ้ำจากการ์ดคือรบกวนสองรอบ
        $alert = (function (?string $previous, array $state) {
            return $this->alertFor($previous, $state);
        })
            ->call(app(TripActivityService::class), 'trip_day', $this->state($booking));

        $this->assertNull($alert);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function at(string $bangkok): void
    {
        Carbon::setTestNow(Carbon::parse($bangkok, 'Asia/Bangkok'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function state(Booking $booking): ?array
    {
        return app(TripActivityService::class)
            ->stateFor($booking->fresh(['schedule.trip', 'schedule.vehicle', 'pickupPoint']));
    }

    private function booking(
        string $departure,
        string $return,
        ?string $departsAt = null,
        ?float $checkedInHoursAgo = 3,
    ): Booking {
        $trip = Trip::create([
            'title' => 'ภูชี้ฟ้า', 'slug' => 'after-boarding-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงราย', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2500, 'status' => 'active',
        ]);

        $vehicle = Vehicle::create([
            'name' => 'ตู้ 1', 'license_plate' => 'กข-1234', 'type' => 'van',
            'capacity' => 12, 'status' => 'active',
        ]);

        // departs_at เก็บเป็นตัวเลขนาฬิกาไทยตรง ๆ ในคอลัมน์ชนิด UTC
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'departure_date' => $departure,
            'departs_at' => $departsAt ?? "{$departure} 06:00:00",
            'return_date' => $return,
            'total_seats' => 10, 'booked_seats' => 1, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'central',
            'region_label' => 'ภาคกลาง',
            'price' => 2500,
            'pickup_location' => 'ปั๊ม ปตท. รังสิต',
            'latitude' => self::PICKUP_LAT,
            'longitude' => self::PICKUP_LNG,
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'pickup_point_id' => $point->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);

        if ($checkedInHoursAgo !== null) {
            $booking->forceFill([
                'checked_in' => true,
                'checked_in_at' => now()->subMinutes((int) round($checkedInHoursAgo * 60)),
            ])->saveQuietly();
        }

        return $booking;
    }

    private function item(Booking $booking, string $date, ?string $time, string $title, ?int $reachedMinutesAgo = null): void
    {
        ScheduleItineraryItem::create([
            'schedule_id' => $booking->schedule_id,
            'item_date' => $date,
            'time' => $time,
            'title' => $title,
            'sort_order' => ScheduleItineraryItem::where('schedule_id', $booking->schedule_id)->count(),
            'reached_at' => $reachedMinutesAgo !== null ? now()->subMinutes($reachedMinutesAgo) : null,
        ]);
    }

    private function announce(Booking $booking, string $title, string $body, int $minutesAgo): void
    {
        $announcement = ScheduleAnnouncement::create([
            'schedule_id' => $booking->schedule_id,
            'category' => 'general',
            'title' => $title,
            'body' => $body,
        ]);

        $announcement->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();
    }

    /** รถอยู่ห่างจุดส่ง old → mid → now กม. เมื่อ 40 นาที / 20 นาทีก่อน / ตอนนี้ */
    private function headingHome(Booking $booking, float $oldKm, float $midKm, float $nowKm, float $speed = 80): void
    {
        $this->pin($booking, $oldKm, minutesAgo: 40, speed: $speed);
        $this->pin($booking, $midKm, minutesAgo: 20, speed: $speed);
        $this->pin($booking, $nowKm, minutesAgo: 0, speed: $speed);
    }

    /** พิกัดรถ ห่างจุดส่งไปทางเหนือ $km กม. (ติดลบ = เลยไปทางใต้) */
    private function pin(Booking $booking, float $km, int $minutesAgo, float $speed = 80): void
    {
        VehicleLocation::create([
            'vehicle_id' => $booking->schedule->vehicle_id,
            'latitude' => self::PICKUP_LAT + $km * self::KM,
            'longitude' => self::PICKUP_LNG,
            'speed' => $speed,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
