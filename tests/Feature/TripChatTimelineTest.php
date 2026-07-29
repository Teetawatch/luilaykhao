<?php

namespace Tests\Feature;

use App\Jobs\PostTripChatTimelineJob;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\TripChatTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripChatTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
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
        ]);

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
