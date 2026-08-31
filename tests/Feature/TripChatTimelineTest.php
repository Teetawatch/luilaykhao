<?php

namespace Tests\Feature;

use App\Jobs\PostTripChatTimelineJob;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripChatTimelineService;
use App\Services\TripFactsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TripChatTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrides = [], array $tripOverrides = []): TripSchedule
    {
        $trip = Trip::create(array_merge([
            'title' => 'ยอดดอยหลวง',
            'slug' => 'timeline-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงราย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 2500,
            'status' => 'active',
            'preparations' => ['เป้ 30 ลิตร', 'ถุงมือกันหนาว'],
        ], $tripOverrides));

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => '2026-08-15',
            'return_date' => '2026-08-16',
            'total_seats' => 12,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function bookOnto(TripSchedule $schedule): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500,
        ]);
    }

    private function timeline(): TripChatTimelineService
    {
        return app(TripChatTimelineService::class);
    }

    private function bangkok(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, TripChatTimelineService::TIMEZONE);
    }

    public function test_posts_the_seven_day_countdown_and_welcome_first(): void
    {
        $schedule = $this->makeSchedule();

        $posted = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-08 09:05'));

        $this->assertSame(['countdown_7d'], $posted);

        $messages = ChatMessage::orderBy('id')->get();
        // ห้องต้องเริ่มด้วยข้อความต้อนรับ แล้วค่อยเป็นข้อความไทม์ไลน์
        $this->assertCount(2, $messages);
        $this->assertNull($messages[0]->system_key);
        $this->assertSame('countdown_7d', $messages[1]->system_key);
        $this->assertStringContainsString('อีก 7 วัน', $messages[1]->body);
        $this->assertSame('system', $messages[1]->sender_role);
    }

    public function test_does_not_post_the_same_key_twice(): void
    {
        $schedule = $this->makeSchedule();
        $at = $this->bangkok('2026-08-08 09:05');

        $this->timeline()->syncFor($schedule, $at);
        $second = $this->timeline()->syncFor($schedule, $at->addHour());

        $this->assertSame([], $second);
        $this->assertSame(1, ChatMessage::whereNotNull('system_key')->count());
    }

    public function test_does_not_backfill_entries_whose_window_has_passed(): void
    {
        $schedule = $this->makeSchedule();

        // เปิดห้องเอาวันก่อนเดินทาง 1 วัน — ข้อความ 7/3/2 วันก่อนต้องไม่ถูกเทย้อนหลัง
        $posted = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-14 20:30'));

        $this->assertSame(['pickup_eve'], $posted);
    }

    public function test_pickup_eve_lists_every_pickup_point_with_time(): void
    {
        $schedule = $this->makeSchedule();
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี',
            'price' => 0,
            'pickup_time' => '19:30',
            'sort_order' => 0,
        ]);
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'nonthaburi',
            'region_label' => 'นนทบุรี',
            'pickup_location' => 'เซ็นทรัลรัตนาธิเบศร์',
            'price' => 0,
            'pickup_time' => '20:15',
            'sort_order' => 1,
        ]);

        $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-14 20:05'));

        $body = ChatMessage::where('system_key', 'pickup_eve')->value('body');
        $this->assertStringContainsString('กรุงเทพฯ', $body);
        $this->assertStringContainsString('ปั๊ม ปตท. วิภาวดี', $body);
        $this->assertStringContainsString('19:30', $body);
        $this->assertStringContainsString('นนทบุรี', $body);
        $this->assertStringContainsString('20:15', $body);
    }

    public function test_prepare_message_uses_the_trip_preparations(): void
    {
        $schedule = $this->makeSchedule();

        $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-12 19:10'));

        $body = ChatMessage::where('system_key', 'prepare_3d')->value('body');
        $this->assertStringContainsString('เป้ 30 ลิตร', $body);
        $this->assertStringContainsString('ถุงมือกันหนาว', $body);
    }

    public function test_departure_message_fires_three_hours_before_the_actual_departure(): void
    {
        // รถออกคืนก่อนวันทริป — เวลาที่เก็บคือเวลาไทย
        $schedule = $this->makeSchedule([
            'departure_date' => '2026-08-15',
            'departs_at' => '2026-08-14 22:00:00',
        ]);

        $tooEarly = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-14 18:00'));
        $this->assertNotContains('departure_soon', $tooEarly);

        $posted = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-14 19:10'));
        $this->assertContains('departure_soon', $posted);
        $this->assertStringContainsString(
            '22:00',
            ChatMessage::where('system_key', 'departure_soon')->value('body'),
        );
    }

    public function test_trip_end_and_photo_expiry_messages_post_after_the_trip(): void
    {
        $schedule = $this->makeSchedule();

        $end = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-16 20:10'));
        $this->assertSame(['trip_end'], $end);

        // ห้องถูกลบ 3 วันหลังจบทริป — เตือนเซฟรูปก่อน 1 วัน
        $expiring = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-18 10:05'));
        $this->assertSame(['photos_expiring'], $expiring);
    }

    private function assignStaff(TripSchedule $schedule, string $nickname, ?string $phone): User
    {
        $staff = User::factory()->create([
            'name' => "{$nickname} ใจดี",
            'nickname' => $nickname,
            'phone' => $phone,
        ]);
        $schedule->staff()->attach($staff->id);

        return $staff;
    }

    private function assignVehicle(TripSchedule $schedule, ?string $driverPhone = '0801112222'): Vehicle
    {
        $vehicle = Vehicle::create([
            'name' => 'รถตู้คันที่ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'ฮก 8899',
            'driver_name' => 'พี่สมชาย',
            'driver_phone' => $driverPhone,
        ]);

        $schedule->vehicle_id = $vehicle->id;
        $schedule->save();
        $schedule->refresh();

        return $vehicle;
    }

    public function test_crew_summary_posts_two_days_ahead_with_staff_and_driver_phones(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->assignStaff($schedule, 'พี่หนึ่ง', '0812223333');
        $this->assignStaff($schedule, 'พี่สอง', '0834445555');
        $this->assignVehicle($schedule);

        $posted = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-13 10:05'));

        $this->assertContains('crew_contacts', $posted);

        $body = ChatMessage::where('system_key', 'crew_contacts')->value('body');
        $this->assertStringContainsString('พี่หนึ่ง', $body);
        $this->assertStringContainsString('081-222-3333', $body);
        $this->assertStringContainsString('พี่สอง', $body);
        $this->assertStringContainsString('083-444-5555', $body);
        $this->assertStringContainsString('พี่สมชาย', $body);
        $this->assertStringContainsString('080-111-2222', $body);
        $this->assertStringContainsString('ฮก 8899', $body);
    }

    public function test_crew_summary_waits_for_missing_data_then_posts_as_soon_as_it_lands(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->assignStaff($schedule, 'พี่หนึ่ง', '0812223333');

        // ยังไม่มีคนขับ — ห้ามโพสต์สรุปครึ่ง ๆ กลาง ๆ ตั้งแต่ 2 วันก่อน
        $early = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-13 10:05'));
        $this->assertNotContains('crew_contacts', $early);

        $this->assignVehicle($schedule);

        // แอดมินกรอกข้อมูลคนขับวันรุ่งขึ้น — job รอบถัดไปต้องโพสต์ทันที
        $later = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-14 09:00'));
        $this->assertContains('crew_contacts', $later);
        $this->assertStringContainsString(
            '080-111-2222',
            ChatMessage::where('system_key', 'crew_contacts')->value('body'),
        );
    }

    public function test_crew_summary_posts_what_it_has_when_departure_is_close(): void
    {
        $schedule = $this->makeSchedule();
        $this->assignStaff($schedule, 'พี่หนึ่ง', '0812223333');

        // รถออก 05:30 ของวันทริป — เลยเส้นตายรอข้อมูล (3 ชม.ก่อนออก) แล้ว
        $posted = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-15 03:30'));

        $this->assertContains('crew_contacts', $posted);

        $body = ChatMessage::where('system_key', 'crew_contacts')->value('body');
        $this->assertStringContainsString('081-222-3333', $body);
        $this->assertStringContainsString(TripFactsService::PENDING_DRIVER, $body);
    }

    public function test_crew_summary_needs_no_driver_on_a_flight_round(): void
    {
        $schedule = $this->makeSchedule(['transport_type' => 'flight']);
        $this->assignStaff($schedule, 'พี่หนึ่ง', '0812223333');

        $posted = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-13 10:05'));

        $this->assertContains('crew_contacts', $posted);

        $body = ChatMessage::where('system_key', 'crew_contacts')->value('body');
        $this->assertStringContainsString('พี่หนึ่ง', $body);
        $this->assertStringNotContainsString('คนขับ', $body);
    }

    public function test_crew_summary_is_posted_once_even_when_staff_change_later(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->assignStaff($schedule, 'พี่หนึ่ง', '0812223333');
        $this->assignVehicle($schedule);

        $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-13 10:05'));
        $this->assignStaff($schedule, 'พี่สอง', '0834445555');
        $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-14 09:00'));

        $this->assertSame(1, ChatMessage::where('system_key', 'crew_contacts')->count());
    }

    public function test_faq_message_lists_the_trip_faqs(): void
    {
        $schedule = $this->makeSchedule([], [
            'faqs' => [
                ['question' => 'ต้องเตรียมเงินสดไปเท่าไหร่?', 'answer' => 'ประมาณ 500 บาทสำหรับค่าอาหารระหว่างทาง'],
                ['question' => 'มีห้องน้ำระหว่างทางไหม?', 'answer' => 'มีทุกจุดจอดพักครับ'],
            ],
        ]);

        $posted = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-13 10:35'));

        $this->assertContains('trip_faq', $posted);

        $body = ChatMessage::where('system_key', 'trip_faq')->value('body');
        $this->assertStringContainsString('ต้องเตรียมเงินสดไปเท่าไหร่?', $body);
        $this->assertStringContainsString('ประมาณ 500 บาท', $body);
        $this->assertStringContainsString('มีห้องน้ำระหว่างทางไหม?', $body);
    }

    public function test_faq_message_caps_the_list_and_points_to_the_app(): void
    {
        $faqs = [];
        for ($i = 1; $i <= 7; $i++) {
            $faqs[] = ['question' => "คำถามข้อ {$i}?", 'answer' => "คำตอบข้อ {$i}"];
        }

        $schedule = $this->makeSchedule([], ['faqs' => $faqs]);

        $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-13 10:35'));

        $body = ChatMessage::where('system_key', 'trip_faq')->value('body');
        $this->assertStringContainsString('คำถามข้อ 5?', $body);
        $this->assertStringNotContainsString('คำถามข้อ 6?', $body);
        $this->assertStringContainsString('ยังมีอีก 2 คำถาม', $body);
    }

    public function test_no_faq_message_when_the_trip_has_none(): void
    {
        $schedule = $this->makeSchedule();

        $posted = $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-13 10:35'));

        $this->assertNotContains('trip_faq', $posted);
    }

    public function test_cancelled_rounds_get_no_timeline_messages(): void
    {
        $schedule = $this->makeSchedule(['status' => 'cancelled']);

        $this->assertSame([], $this->timeline()->syncFor($schedule, $this->bangkok('2026-08-08 09:05')));
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_job_skips_rounds_without_any_booking(): void
    {
        $this->travelTo($this->bangkok('2026-08-08 09:05'));
        $empty = $this->makeSchedule();
        $booked = $this->makeSchedule();
        $this->bookOnto($booked);

        (new PostTripChatTimelineJob)->handle($this->timeline());

        $this->assertSame(0, ChatMessage::where('schedule_id', $empty->id)->count());
        $this->assertSame(
            1,
            ChatMessage::where('schedule_id', $booked->id)->where('system_key', 'countdown_7d')->count(),
        );
    }
}
