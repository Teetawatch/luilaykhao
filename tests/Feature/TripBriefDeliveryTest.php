<?php

namespace Tests\Feature;

use App\Jobs\SendTripBriefsJob;
use App\Mail\TripBriefMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SchedulePickupPoint;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MailService;
use App\Services\SmsService;
use App\Services\TripBriefService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ส่งใบเดินทางทางช่องที่ลูกค้ามีจริง — อีเมลเมื่อส่งถึงคนจริงได้ ไม่งั้น SMS
 *
 * หัวใจของฟีเจอร์นี้คือลูกค้าที่ทีมงานเปิดใบจองแทนให้แล้วไม่ได้โหลดแอป และ
 * ส่วนใหญ่ไม่มีอีเมลจริงในระบบ (ได้ที่อยู่ปลอม manual_...@luilaykhao.com)
 */
class TripBriefDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-09-10 18:05 Bangkok — ทริปออกเดินทาง 2026-09-12 คือ D-2
        Carbon::setTestNow(Carbon::parse('2026-09-10 11:05:00', 'UTC'));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(string $departureDate = '2026-09-12', bool $withCrew = true): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'phu-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'medium', 'duration_days' => 3,
            'max_participants' => 10, 'price_per_person' => 5500, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate,
            'return_date' => $departureDate,
            'total_seats' => 10, 'booked_seats' => 1,
            'transport_type' => 'van', 'status' => 'open',
        ]);

        if ($withCrew) {
            $vehicle = Vehicle::create([
                'name' => 'ตู้ 2', 'type' => 'van', 'capacity' => 10,
                'license_plate' => 'กข 999', 'driver_name' => 'ลุงชัย', 'driver_phone' => '0891234567',
            ]);
            $schedule->update(['vehicle_id' => $vehicle->id]);
            $schedule->staff()->attach(User::factory()->create(['nickname' => 'พี่ปิ', 'phone' => '0812223333'])->id);
        }

        return $schedule;
    }

    /** ลูกค้าที่แอดมินจองให้โดยไม่ได้กรอกอีเมล — ได้ที่อยู่ปลอมติดตัวมา */
    private function shadowBooking(TripSchedule $schedule): Booking
    {
        $user = User::factory()->create([
            'email' => 'manual_'.time().'_ab12@luilaykhao.com',
            'phone' => '0866666666',
        ]);
        $user->forceFill(['is_shadow' => true])->save();

        $booking = $this->booking($schedule, $user);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'ลูกค้าโทรมาจอง', 'phone' => '0866666666',
        ]);

        return $booking->fresh();
    }

    private function booking(TripSchedule $schedule, ?User $user = null): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => ($user ?: User::factory()->create())->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 5500,
            'paid_amount' => 5500,
        ]);
    }

    public function test_customer_with_a_real_email_gets_the_full_email(): void
    {
        $booking = $this->booking($this->schedule(), User::factory()->create(['email' => 'som@example.com']));

        (new SendTripBriefsJob)->handle(
            app(TripBriefService::class),
            app(MailService::class),
            app(SmsService::class),
        );

        Mail::assertQueued(TripBriefMail::class, fn ($mail) => $mail->hasTo('som@example.com') && ! $mail->isUpdate);
        $this->assertNotNull($booking->fresh()->brief_sent_at);
        $this->assertDatabaseMissing('sms_logs', ['booking_id' => $booking->id, 'sms_type' => 'trip_brief']);
    }

    public function test_shadow_booking_without_a_real_email_gets_an_sms_link_instead(): void
    {
        $booking = $this->shadowBooking($this->schedule());

        $this->runJob();

        // ที่อยู่ปลอมต้องไม่ถูกส่งหาเด็ดขาด — มันคือ hard bounce ที่กดคะแนนโดเมน
        Mail::assertNothingQueued();

        $log = SmsLog::where('booking_id', $booking->id)->where('sms_type', 'trip_brief')->first();
        $this->assertNotNull($log);
        // SmsService แปลงเบอร์เป็นรูปแบบสากลก่อนส่งเสมอ
        $this->assertSame('66866666666', $log->recipient);
        $this->assertStringContainsString('/t/'.$booking->fresh()->brief_token, $log->message);
        $this->assertNotNull($booking->fresh()->brief_sent_at);
    }

    public function test_does_not_send_twice_when_nothing_changed(): void
    {
        $booking = $this->booking($this->schedule(), User::factory()->create(['email' => 'som@example.com']));

        $this->runJob();
        Mail::assertQueuedCount(1);

        $this->runJob();
        Mail::assertQueuedCount(1);
    }

    public function test_sends_an_update_when_the_crew_changes(): void
    {
        $schedule = $this->schedule();
        $this->booking($schedule, User::factory()->create(['email' => 'som@example.com']));

        $this->runJob();
        Mail::assertQueuedCount(1);

        // แอดมินเปลี่ยนคนขับหลังส่งใบเดินทางไปแล้ว
        $schedule->vehicle->update(['driver_name' => 'ลุงสมศักดิ์', 'driver_phone' => '0877777777']);

        $this->runJob();
        Mail::assertQueuedCount(2);
        Mail::assertQueued(TripBriefMail::class, fn ($mail) => $mail->isUpdate === true);
    }

    public function test_waits_for_the_crew_before_sending_the_first_edition(): void
    {
        // D-2 แต่ยังไม่มีทั้งรถ สตาฟ และจุดขึ้นรถ → ยังไม่ส่ง
        $schedule = $this->schedule(withCrew: false);
        $booking = $this->booking($schedule, User::factory()->create(['email' => 'som@example.com']));

        $this->runJob();

        Mail::assertNothingQueued();
        $this->assertNull($booking->fresh()->brief_sent_at);
    }

    public function test_sends_anyway_on_the_day_before_even_without_a_crew(): void
    {
        // พรุ่งนี้เดินทางแล้ว — ข้อมูลเท่าที่มีก็ยังดีกว่าเงียบ
        $schedule = $this->schedule('2026-09-11', withCrew: false);
        $booking = $this->booking($schedule, User::factory()->create(['email' => 'som@example.com']));

        $this->runJob();

        Mail::assertQueued(TripBriefMail::class);
        $this->assertNotNull($booking->fresh()->brief_sent_at);
    }

    public function test_ignores_rounds_that_are_still_far_away_or_cancelled(): void
    {
        $this->booking($this->schedule('2026-09-20'), User::factory()->create(['email' => 'far@example.com']));

        $cancelled = $this->schedule();
        $cancelled->update(['status' => 'cancelled']);
        $this->booking($cancelled, User::factory()->create(['email' => 'cancelled@example.com']));

        $this->runJob();

        Mail::assertNothingQueued();
    }

    public function test_pickup_point_alone_is_enough_to_count_as_ready(): void
    {
        $schedule = $this->schedule(withCrew: false);
        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี', 'price' => 5500, 'pickup_time' => '21:00:00',
        ]);

        $booking = $this->booking($schedule, User::factory()->create(['email' => 'som@example.com']));
        BookingPassenger::create([
            'booking_id' => $booking->id, 'name' => 'สมชาย', 'pickup_point_id' => $point->id,
        ]);

        $this->runJob();

        Mail::assertQueued(TripBriefMail::class);
    }

    public function test_admin_can_send_the_brief_on_demand(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // รอบที่ยังอีกไกล — งานตามตารางยังไม่แตะ แต่แอดมินสั่งส่งเองได้
        $booking = $this->booking($this->schedule('2026-09-30'), User::factory()->create(['email' => 'som@example.com']));

        $response = $this->actingAs($admin)->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/trip-brief");

        $response->assertOk();
        $response->assertJsonPath('data.channel', 'email');
        Mail::assertQueued(TripBriefMail::class);
        $this->assertNotNull($booking->fresh()->brief_sent_at);
    }

    public function test_admin_cannot_send_a_brief_for_a_cancelled_booking(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $booking = $this->booking($this->schedule(), User::factory()->create(['email' => 'som@example.com']));
        $booking->update(['status' => 'cancelled']);

        // ใบนี้อยู่ในช่วง D-2 จึงได้ใบเดินทางไปแล้วตอนสร้าง — ล้างกระดานก่อนวัดของจริง
        Mail::fake();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/bookings/{$booking->booking_ref}/trip-brief")
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    public function test_a_last_minute_booking_gets_its_brief_at_once(): void
    {
        // 18:05 ของวันนี้ผ่านไปแล้ว ลูกค้าเพิ่งจองรอบที่รถออกพรุ่งนี้ตีสี่ —
        // ถ้ารอรอบส่งประจำวันถัดไป ใบเดินทางจะไปถึงหลังรถออกไปแล้วสิบกว่าชั่วโมง
        Carbon::setTestNow(Carbon::parse('2026-09-10 15:00:00', 'UTC')); // 22:00 ไทย

        $schedule = $this->schedule('2026-09-11');
        $schedule->update(['departs_at' => '2026-09-11 04:00:00']);

        $booking = $this->booking($schedule, User::factory()->create(['email' => 'som@example.com']));

        Mail::assertQueued(TripBriefMail::class, fn ($mail) => $mail->booking->id === $booking->id);
        $this->assertNotNull($booking->fresh()->brief_sent_at);
    }

    public function test_a_booking_far_out_is_left_to_the_daily_run(): void
    {
        $booking = $this->booking($this->schedule('2026-10-20'), User::factory()->create(['email' => 'som@example.com']));

        Mail::assertNothingQueued();
        $this->assertNull($booking->fresh()->brief_sent_at);
    }

    public function test_nothing_is_sent_once_the_van_has_left(): void
    {
        // ตี 5 ของวันเดินทาง รถออกไปตั้งแต่ตีสี่
        Carbon::setTestNow(Carbon::parse('2026-09-11 22:00:00', 'UTC')); // 05:00 ไทย ของวันที่ 12

        $schedule = $this->schedule('2026-09-12');
        $schedule->update(['departs_at' => '2026-09-12 04:00:00']);
        $booking = $this->booking($schedule, User::factory()->create(['email' => 'som@example.com']));

        Mail::assertNothingQueued();

        $this->runJob();

        Mail::assertNothingQueued();
        $this->assertNull($booking->fresh()->brief_sent_at);
    }

    public function test_no_update_chases_a_round_that_already_departed(): void
    {
        $schedule = $this->schedule();
        $this->booking($schedule, User::factory()->create(['email' => 'som@example.com']));

        $this->runJob();
        Mail::assertQueuedCount(1);

        // รถออกไปแล้ว แล้วแอดมินเพิ่งมาแก้ชื่อคนขับย้อนหลัง
        Carbon::setTestNow(Carbon::parse('2026-09-12 05:00:00', 'UTC'));
        $schedule->update(['departs_at' => '2026-09-12 04:00:00']);
        $schedule->vehicle->update(['driver_name' => 'ลุงสมศักดิ์']);

        $this->runJob();

        Mail::assertQueuedCount(1);
    }

    private function runJob(): void
    {
        (new SendTripBriefsJob)->handle(
            app(TripBriefService::class),
            app(MailService::class),
            app(SmsService::class),
        );
    }
}
