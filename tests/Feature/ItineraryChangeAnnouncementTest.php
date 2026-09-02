<?php

namespace Tests\Feature;

use App\Jobs\AnnounceItineraryChangeJob;
use App\Models\ChatMessage;
use App\Models\ScheduleItineraryItem;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatService;
use App\Services\ScheduleItineraryService;
use App\Services\TripChatTimelineService;
use App\Services\TripFactsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * แอดมินแก้กำหนดการหลังจากห้องได้ข้อความ itinerary_2d ไปแล้ว = ข้อมูลในห้องผิด
 * ชุดเทสนี้คุมว่าห้องต้องรู้ และต้องไม่ถูกกวนเวลาไม่มีอะไรเปลี่ยนจริง
 */
class ItineraryChangeAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง 2 วัน 1 คืน',
            'slug' => 'change-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3200,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(2)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function item(TripSchedule $schedule, string $time, string $title): ScheduleItineraryItem
    {
        return ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id,
            'item_date' => $schedule->departure_date->toDateString(),
            'time' => $time,
            'title' => $title,
            'sort_order' => $schedule->itineraryItems()->count(),
        ]);
    }

    /** จำลองว่าห้องนี้ได้ข้อความกำหนดการ D-2 ไปแล้ว */
    private function seedFirstAnnouncement(TripSchedule $schedule): void
    {
        app(TripChatTimelineService::class)->syncFor(
            $schedule->fresh(),
            CarbonImmutable::parse($schedule->departure_date->toDateString(), 'Asia/Bangkok')
                ->subDays(2)
                ->setTime(9, 5),
        );

        $this->assertTrue(
            ChatMessage::where('schedule_id', $schedule->id)
                ->where('system_key', 'itinerary_2d')
                ->exists(),
            'ต้องมีข้อความกำหนดการ D-2 ก่อนถึงจะทดสอบการแจ้งแก้ไขได้',
        );
    }

    private function announce(TripSchedule $schedule): void
    {
        (new AnnounceItineraryChangeJob($schedule->id))->handle(
            app(ChatService::class),
            app(TripFactsService::class),
        );
    }

    private function announcements(TripSchedule $schedule): int
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->where('sender_role', 'system')
            ->whereNull('system_key')
            ->where('body', 'like', '%กำหนดการมีการปรับ%')
            ->count();
    }

    public function test_editing_the_itinerary_tells_the_room_the_new_version(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $point = $this->item($schedule, '20:00', 'ออกเดินทางจากกรุงเทพฯ');
        $this->seedFirstAnnouncement($schedule);

        $point->update(['time' => '21:00']);
        $this->announce($schedule->fresh());

        $body = ChatMessage::where('schedule_id', $schedule->id)->latest('id')->value('body');
        $this->assertStringContainsString('กำหนดการมีการปรับ', $body);
        $this->assertStringContainsString('21:00 น. ออกเดินทางจากกรุงเทพฯ', $body);
        $this->assertSame(1, $this->announcements($schedule));
    }

    public function test_the_room_is_not_bothered_when_nothing_actually_changed(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $point = $this->item($schedule, '20:00', 'ออกเดินทางจากกรุงเทพฯ');
        $this->seedFirstAnnouncement($schedule);

        // แก้แล้วแก้กลับ — เนื้อกำหนดการเท่าเดิม ไม่ต้องกวนห้อง
        $point->update(['time' => '21:00']);
        $point->update(['time' => '20:00']);
        $this->announce($schedule->fresh());

        $this->assertSame(0, $this->announcements($schedule));
    }

    public function test_a_second_run_with_the_same_plan_posts_nothing_more(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $point = $this->item($schedule, '20:00', 'ออกเดินทางจากกรุงเทพฯ');
        $this->seedFirstAnnouncement($schedule);

        $point->update(['time' => '21:00']);
        $this->announce($schedule->fresh());
        $this->announce($schedule->fresh());

        $this->assertSame(1, $this->announcements($schedule));
    }

    public function test_rounds_that_never_got_the_itinerary_message_stay_quiet(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $point = $this->item($schedule, '20:00', 'ออกเดินทางจากกรุงเทพฯ');

        $point->update(['time' => '21:00']);
        $this->announce($schedule->fresh());

        $this->assertSame(0, $this->announcements($schedule));
    }

    public function test_finished_and_cancelled_rounds_stay_quiet(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $point = $this->item($schedule, '20:00', 'ออกเดินทางจากกรุงเทพฯ');
        $this->seedFirstAnnouncement($schedule);

        // จบทริปแล้ว — แอดมินมักมาจัดกำหนดการย้อนหลังเพื่อใช้ซ้ำรอบหน้า
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->subDays(5)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(4)->toDateString(),
        ]);
        $point->update(['time' => '21:00']);
        $this->announce($schedule->fresh());
        $this->assertSame(0, $this->announcements($schedule));

        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(2)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
            'status' => 'cancelled',
        ]);
        $point->update(['time' => '22:00']);
        $this->announce($schedule->fresh());
        $this->assertSame(0, $this->announcements($schedule));
    }

    public function test_staff_check_in_never_counts_as_a_plan_change(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $point = $this->item($schedule, '20:00', 'ออกเดินทางจากกรุงเทพฯ');
        $staff = User::factory()->create();

        app(ScheduleItineraryService::class)->setReached($point, $staff, true);

        // ครั้งเดียวคือตอนสร้างจุดนั้น — การเช็คอินของสตาฟต้องไม่นับเป็นการแก้แผน
        Bus::assertDispatchedTimes(AnnounceItineraryChangeJob::class, 1);
    }

    public function test_editing_a_point_queues_the_announcement_after_a_debounce(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $point = $this->item($schedule, '20:00', 'ออกเดินทางจากกรุงเทพฯ');

        $point->update(['time' => '21:00']);

        Bus::assertDispatched(
            AnnounceItineraryChangeJob::class,
            fn (AnnounceItineraryChangeJob $job) => $job->scheduleId === $schedule->id
                && $job->delay !== null,
        );
    }
}
