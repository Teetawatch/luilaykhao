<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Receipt;
use App\Models\ScheduleAnnouncement;
use App\Models\ScheduleItineraryItem;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\TripBriefService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ของที่ต่อเพิ่มเข้าไปในใบเดินทาง: ไฟล์ปฏิทิน ประกาศจากทีมงาน รายการที่ยังขาด
 * ใบเสร็จ และปุ่ม "รับทราบแล้ว"
 *
 * @see TripBriefTest สำหรับตัวใบเดินทางเอง
 */
class TripBriefExtrasTest extends TestCase
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

    private function pickup(TripSchedule $schedule, string $time = '20:30:00'): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok', 'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี ขาออก',
            'price' => 4500, 'pickup_time' => $time,
        ]);
    }

    // ── ปฏิทิน ────────────────────────────────────────────────────────────────

    public function test_calendar_file_uses_the_time_the_customer_must_be_there(): void
    {
        $schedule = $this->schedule();
        $point = $this->pickup($schedule);
        $booking = $this->booking($schedule, ['pickup_point_id' => $point->id]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมชาย ใจดี', 'pickup_point_id' => $point->id,
        ]);

        $response = $this->get('/t/'.$booking->ensureBriefToken().'/calendar.ics');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/calendar; charset=utf-8');
        $ics = $response->getContent();

        // 20:30 เวลาไทยของวันที่ 12 = 13:30Z — เขียนเป็น UTC จะได้ไม่ต้องพึ่ง VTIMEZONE
        $this->assertStringContainsString('DTSTART:20260912T133000Z', $ics);
        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('TRIGGER:-PT1H', $ics);
        // ทริปสองวันได้นัดทั้งวันเพิ่มอีกใบ และ DTEND ของนัดทั้งวันไม่นับวันสุดท้าย
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260912', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260914', $ics);
        $this->assertStringContainsString('ปั๊ม', $ics);
    }

    public function test_calendar_follows_a_van_that_leaves_the_night_before(): void
    {
        $schedule = $this->schedule(null, ['departs_at' => '2026-09-11 22:00:00']);
        $booking = $this->booking($schedule);

        $ics = $this->get('/t/'.$booking->ensureBriefToken().'/calendar.ics')->getContent();

        // 22:00 ของวันที่ 11 ตามเวลาไทย = 15:00Z ของวันเดียวกัน ไม่ใช่เช้าวันทริป
        $this->assertStringContainsString('DTSTART:20260911T150000Z', $ics);
    }

    public function test_a_round_without_a_departure_time_gets_an_all_day_entry_only(): void
    {
        // รอบวันเดียว ไป-กลับวันเดียวกัน และไม่เคยตั้งเวลารถออก
        $schedule = $this->schedule(null, ['return_date' => '2026-09-12']);
        $booking = $this->booking($schedule);

        $ics = $this->get('/t/'.$booking->ensureBriefToken().'/calendar.ics')->getContent();

        // ไม่เคยมีใครตั้งเวลาไว้ ก็ต้องไม่มีนัดแบบมีเวลาโผล่มาจากเที่ยงคืนปลอม
        $this->assertStringNotContainsString('DTSTART:2026', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260912', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260913', $ics);
    }

    public function test_calendar_is_gone_once_the_link_expires(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        $token = $booking->ensureBriefToken();

        Carbon::setTestNow(Carbon::parse('2026-09-20 03:00:00', 'UTC'));

        $this->get('/t/'.$token.'/calendar.ics')->assertNotFound();
        $this->get('/t/unknownlink/calendar.ics')->assertNotFound();
    }

    // ── ประกาศจากทีมงาน ───────────────────────────────────────────────────────

    public function test_announcements_reach_the_customer_who_has_no_app(): void
    {
        $schedule = $this->schedule();
        $author = User::factory()->create(['name' => 'ปิยะ สายลม', 'nickname' => 'พี่ปิ']);
        $booking = $this->booking($schedule);

        ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id, 'author_id' => $author->id,
            'category' => 'packing', 'title' => 'อากาศเย็นกว่าปกติ',
            'body' => 'เอาเสื้อกันหนาวมาเพิ่มอีกตัวนะครับ', 'is_pinned' => false,
        ]);
        ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id, 'author_id' => $author->id,
            'category' => 'urgent', 'title' => 'เลื่อนเวลาออกเดินทาง',
            'body' => 'ขยับเป็น 21:00 น.', 'is_pinned' => true,
        ]);

        $response = $this->get('/t/'.$booking->ensureBriefToken());

        $response->assertOk();
        $response->assertSee('อากาศเย็นกว่าปกติ');
        $response->assertSee('เอาเสื้อกันหนาวมาเพิ่มอีกตัวนะครับ');
        $response->assertSee('เลื่อนเวลาออกเดินทาง');
        $response->assertSee('การเตรียมตัว');
        $response->assertSee('พี่ปิ');

        // ปักหมุดต้องมาก่อน ลำดับเดียวกับที่ลูกค้าเห็นในแอป
        $content = $response->getContent();
        $this->assertLessThan(
            strpos($content, 'อากาศเย็นกว่าปกติ'),
            strpos($content, 'เลื่อนเวลาออกเดินทาง'),
        );
    }

    public function test_a_new_announcement_counts_as_an_update_worth_resending(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        $briefs = app(TripBriefService::class);

        $before = $briefs->digest($booking);

        ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id,
            'category' => 'schedule_change', 'title' => 'เปลี่ยนจุดนัดพบ',
            'body' => 'ย้ายไปฝั่งตรงข้าม', 'is_pinned' => false,
        ]);

        $after = app(TripBriefService::class)->digest($booking->fresh());

        $this->assertNotSame($before, $after);
    }

    // ── ยังขาดอะไรอยู่ ────────────────────────────────────────────────────────

    public function test_missing_birthdate_becomes_a_task_with_a_link(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'สมชาย ใจดี']);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมหญิง ใจงาม', 'birth_date' => '1995-02-03',
        ]);

        $response = $this->get('/t/'.$booking->ensureBriefToken());

        $response->assertOk();
        $response->assertSee('วันเกิดของผู้เดินทาง');
        // ขาดแค่คนเดียว ต้องบอกว่าเป็นใคร ไม่ใช่เหมารวมทั้งใบ
        $response->assertSee('สมชาย ใจดี');
        $response->assertSee('/booking-birthdate/'.$booking->fresh()->birthdate_token, false);
    }

    public function test_nothing_missing_means_no_task_list_at_all(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมชาย ใจดี', 'birth_date' => '1990-01-01',
        ]);

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertDontSee('ยังขาดข้อมูลอยู่นิดหน่อย');
    }

    public function test_international_round_asks_for_the_passport(): void
    {
        $trip = $this->trip(['destination_type' => 'international', 'country_code' => 'NP']);
        $schedule = $this->schedule($trip);
        $booking = $this->booking($schedule);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมชาย ใจดี', 'birth_date' => '1990-01-01',
        ]);

        $response = $this->get('/t/'.$booking->ensureBriefToken());

        $response->assertOk();
        $response->assertSee('ข้อมูลพาสปอร์ต');
        $response->assertSee('/booking-passport/'.$booking->fresh()->passport_token, false);
    }

    // ── ใบเสร็จ ───────────────────────────────────────────────────────────────

    public function test_receipt_is_linked_when_one_has_been_issued(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);

        $receipt = Receipt::create([
            'booking_id' => $booking->id,
            'receipt_no' => Receipt::generateNumber(),
            'verify_token' => Receipt::generateToken(),
            'kind' => 'full', 'amount' => 4500, 'currency' => 'THB',
            'status' => 'issued', 'issued_at' => now(),
        ]);

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertSee('/receipt/'.$receipt->verify_token, false);
    }

    // ── วันเดินทาง: QR เช็คอิน + บอกสถานะที่จุดนัด ────────────────────────────

    public function test_checkin_qr_only_shows_up_when_it_is_time_to_use_it(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        $token = $booking->ensureBriefToken();

        // D-2 — ใบเดินทางเพิ่งถึงมือ รหัสขึ้นรถยังไม่ควรค้างอยู่บนหน้าจอ
        $this->get('/t/'.$token)->assertOk()->assertDontSee($booking->qr_code);

        // เย็นวันก่อนเดินทาง
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00', 'UTC'));

        $response = $this->get('/t/'.$token);
        $response->assertOk();
        $response->assertSee('ยื่นจอนี้ให้ทีมงานสแกน');
        $response->assertSee($booking->qr_code);
        $response->assertSee('data:image/svg+xml;base64,', false);
    }

    public function test_a_checked_in_booking_sees_the_confirmation_not_the_code(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 00:00:00', 'UTC'));

        $schedule = $this->schedule();
        $booking = $this->booking($schedule, [
            'checked_in' => true,
            'checked_in_at' => Carbon::parse('2026-09-11 20:35:00', 'UTC'),
        ]);

        $response = $this->get('/t/'.$booking->ensureBriefToken());

        $response->assertOk();
        $response->assertSee('เช็คอินขึ้นรถแล้ว');
        // 20:35 UTC = 03:35 ตามเวลาไทยของวันถัดไป
        $response->assertSee('03:35');
        $response->assertDontSee($booking->qr_code);
    }

    public function test_customer_without_the_app_can_report_being_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00', 'UTC'));

        $schedule = $this->schedule();
        $point = $this->pickup($schedule);
        $booking = $this->booking($schedule, ['pickup_point_id' => $point->id]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมชาย ใจดี', 'pickup_point_id' => $point->id,
        ]);
        $token = $booking->ensureBriefToken();

        $this->get('/t/'.$token)->assertOk()->assertSee('ถึงจุดนัดแล้ว');

        $this->post('/t/'.$token.'/pickup-status', [
            'status' => 'late',
            'eta_minutes' => 20,
        ])->assertRedirect('/t/'.$token);

        $booking->refresh();
        $this->assertSame('late', $booking->pickup_status);
        $this->assertSame(20, $booking->pickup_status_eta_minutes);

        $this->get('/t/'.$token)->assertOk()->assertSee('อาจสาย ~20 นาที');
    }

    public function test_reporting_outside_the_window_explains_itself_instead_of_crashing(): void
    {
        // ยังเป็น D-2 ตามเวลาของ setUp
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        $token = $booking->ensureBriefToken();

        $this->get('/t/'.$token)->assertOk()->assertDontSee('ถึงจุดนัดแล้ว');

        $this->post('/t/'.$token.'/pickup-status', ['status' => 'arrived'])
            ->assertRedirect('/t/'.$token)
            ->assertSessionHas('pickup_error');

        $this->assertNull($booking->fresh()->pickup_status);
    }

    public function test_pickup_status_rejects_an_unknown_value(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00', 'UTC'));

        $schedule = $this->schedule();
        $booking = $this->booking($schedule);

        $this->post('/t/'.$booking->ensureBriefToken().'/pickup-status', ['status' => 'boarded'])
            ->assertSessionHasErrors('status');

        $this->assertNull($booking->fresh()->pickup_status);
    }

    // ── ถึงไหนแล้ว ────────────────────────────────────────────────────────────

    public function test_progress_shows_up_once_the_team_ticks_the_first_stop(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 02:00:00', 'UTC'));

        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        $token = $booking->ensureBriefToken();

        $first = ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id, 'item_date' => '2026-09-12',
            'time' => '05:00:00', 'title' => 'ถึงที่ทำการอุทยาน', 'sort_order' => 1,
        ]);
        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id, 'item_date' => '2026-09-12',
            'time' => '09:00:00', 'title' => 'ถึงยอดดอย', 'sort_order' => 2,
        ]);

        // ยังไม่มีใครกดยืนยัน — แถบ 0% ไม่ได้บอกอะไรนอกจากทำให้คิดว่าทีมงานลืมกด
        $this->get('/t/'.$token)->assertOk()->assertDontSee('ตอนนี้ถึงไหนแล้ว');

        $first->update(['reached_at' => now()]);

        $response = $this->get('/t/'.$token);
        $response->assertOk();
        $response->assertSee('ตอนนี้ถึงไหนแล้ว');
        $response->assertSee('ถึงที่ทำการอุทยาน');
        $response->assertSee('ต่อไป');
        $response->assertSee('ถึงยอดดอย');
        $response->assertSee('ผ่านมาแล้ว 1 จาก 2 จุด');
    }

    // ── ส่งต่อให้ที่บ้าน ───────────────────────────────────────────────────────

    public function test_the_family_link_shared_is_the_tracking_one_not_the_brief(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule, ['total_amount' => 9000, 'paid_amount' => 4500]);
        $token = $booking->ensureBriefToken();

        $response = $this->get('/t/'.$token);

        $response->assertOk();
        $response->assertSee('ส่งลิงก์ให้ที่บ้าน');
        // ที่บ้านอยากรู้ว่ารถถึงไหน ไม่ต้องเห็นยอดค้างหรือปุ่มจ่ายเงินของลูกค้า
        $response->assertSee('/track/'.$booking->fresh()->share_token, false);
        $this->assertStringNotContainsString(
            'data-share="'.url('/t/'.$token).'"',
            $response->getContent(),
        );
    }

    // ── รับทราบแล้ว ───────────────────────────────────────────────────────────

    public function test_opening_the_link_records_the_first_read_only(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        $token = $booking->ensureBriefToken();

        $this->get('/t/'.$token)->assertOk();
        $firstRead = $booking->fresh()->brief_read_at;
        $this->assertNotNull($firstRead);

        Carbon::setTestNow(Carbon::now()->addHours(3));
        $this->get('/t/'.$token)->assertOk();

        // คำถามของทีมงานคือ "ถึงมือลูกค้าหรือยัง" ไม่ใช่ "เปิดไปกี่ครั้ง"
        $this->assertEquals($firstRead->timestamp, $booking->fresh()->brief_read_at->timestamp);
    }

    public function test_customer_can_acknowledge_and_the_page_says_thank_you(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule);
        $token = $booking->ensureBriefToken();

        $this->get('/t/'.$token)->assertSee('รับทราบแล้ว');

        $this->post('/t/'.$token.'/ack')->assertRedirect('/t/'.$token);

        $acked = $booking->fresh()->brief_ack_at;
        $this->assertNotNull($acked);

        $this->get('/t/'.$token)->assertOk()->assertSee('ขอบคุณครับ');

        // กดซ้ำไม่ขยับเวลาที่รับทราบครั้งแรก
        Carbon::setTestNow(Carbon::now()->addHour());
        $this->post('/t/'.$token.'/ack');
        $this->assertEquals($acked->timestamp, $booking->fresh()->brief_ack_at->timestamp);
    }

    public function test_acknowledging_an_expired_link_changes_nothing(): void
    {
        $schedule = $this->schedule();
        $booking = $this->booking($schedule, ['status' => 'cancelled']);
        $token = $booking->ensureBriefToken();

        $this->post('/t/'.$token.'/ack');

        $this->assertNull($booking->fresh()->brief_ack_at);
    }
}
