<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ContentReport;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\ContentFilterService;
use App\Services\ModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * เครื่องมือดูแลเนื้อหา (UGC) ตามที่ App Store Guideline 1.2 กำหนด:
 * กรองตอนโพสต์ / รายงานได้ / ซ่อนอัตโนมัติ / บล็อกผู้ใช้ได้
 */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.thaibulksms.enabled', false);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Moderation Trip', 'slug' => 'mod-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 1500, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function bookOnto(User $user, TripSchedule $schedule): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'P', 'phone' => '0812345678',
        ]);

        return $booking;
    }

    private function makeReview(User $user, TripSchedule $schedule, string $comment = 'ดีมาก'): Review
    {
        $booking = $this->bookOnto($user, $schedule);

        return Review::create([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'trip_id' => $schedule->trip_id,
            'rating' => 5,
            'comment' => $comment,
        ]);
    }

    // ── รายงานเนื้อหา ────────────────────────────────────────

    public function test_user_can_report_a_chat_message(): void
    {
        $schedule = $this->makeSchedule();
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $this->bookOnto($author, $schedule);
        $this->bookOnto($reporter, $schedule);

        $message = ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $author->id,
            'sender_role' => 'customer', 'body' => 'ข้อความที่มีปัญหา',
        ]);

        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports', [
                'type' => 'chat_message',
                'id' => $message->id,
                'reason' => 'harassment',
                'note' => 'พูดจาไม่ดี',
            ])
            ->assertOk();

        $this->assertDatabaseHas('content_reports', [
            'reporter_id' => $reporter->id,
            'author_id' => $author->id,
            'reportable_type' => 'chat_message',
            'reportable_id' => $message->id,
            'reason' => 'harassment',
            'status' => ContentReport::STATUS_OPEN,
        ]);
        $this->assertEquals(1, $message->fresh()->reports_count);
    }

    public function test_the_same_person_cannot_report_the_same_thing_twice(): void
    {
        $schedule = $this->makeSchedule();
        $reporter = User::factory()->create();
        $review = $this->makeReview(User::factory()->create(), $schedule);

        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports', ['type' => 'review', 'id' => $review->id, 'reason' => 'spam'])
            ->assertOk();

        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports', ['type' => 'review', 'id' => $review->id, 'reason' => 'spam'])
            ->assertStatus(422);

        $this->assertEquals(1, ContentReport::count());
        $this->assertEquals(1, $review->fresh()->reports_count);
    }

    public function test_reporting_your_own_content_is_rejected(): void
    {
        $schedule = $this->makeSchedule();
        $author = User::factory()->create();
        $review = $this->makeReview($author, $schedule);

        $this->actingAs($author, 'sanctum')
            ->postJson('/api/v1/reports', ['type' => 'review', 'id' => $review->id])
            ->assertStatus(422);
    }

    public function test_unknown_content_type_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/reports', ['type' => 'booking', 'id' => 1])
            ->assertStatus(422);
    }

    /** เนื้อหาที่ถูกรายงานครบเกณฑ์ต้องหายไปเองระหว่างรอแอดมิน */
    public function test_content_auto_hides_once_it_hits_the_report_threshold(): void
    {
        $schedule = $this->makeSchedule();
        $review = $this->makeReview(User::factory()->create(), $schedule);

        for ($i = 0; $i < ModerationService::AUTO_HIDE_REPORTS; $i++) {
            $this->actingAs(User::factory()->create(), 'sanctum')
                ->postJson('/api/v1/reports', ['type' => 'review', 'id' => $review->id, 'reason' => 'spam'])
                ->assertOk();
        }

        $this->assertFalse((bool) $review->fresh()->is_approved);

        // และหายไปจากรีวิวสาธารณะทันที
        $this->getJson('/api/v1/reviews?trip_id='.$schedule->trip_id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_hidden_chat_message_is_not_returned_to_anyone(): void
    {
        $schedule = $this->makeSchedule();
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $this->bookOnto($author, $schedule);
        $this->bookOnto($viewer, $schedule);

        $message = ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $author->id,
            'sender_role' => 'customer', 'body' => 'ข้อความที่ถูกซ่อน',
        ]);

        app(ModerationService::class)->hide('chat_message', $message->id);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertOk();

        $bodies = collect($response->json('data.messages'))->pluck('body');
        $this->assertFalse($bodies->contains('ข้อความที่ถูกซ่อน'));
    }

    // ── บล็อกผู้ใช้ ──────────────────────────────────────────

    public function test_blocking_hides_chat_messages_both_ways(): void
    {
        $schedule = $this->makeSchedule();
        $blocker = User::factory()->create();
        $blocked = User::factory()->create();
        $this->bookOnto($blocker, $schedule);
        $this->bookOnto($blocked, $schedule);

        ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $blocked->id,
            'sender_role' => 'customer', 'body' => 'ข้อความจากคนที่ถูกบล็อก',
        ]);
        ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => $blocker->id,
            'sender_role' => 'customer', 'body' => 'ข้อความจากคนที่บล็อก',
        ]);

        $this->actingAs($blocker, 'sanctum')
            ->postJson('/api/v1/me/blocks', ['user_id' => $blocked->id])
            ->assertOk();

        // ฝั่งคนบล็อก: ไม่เห็นข้อความของอีกฝ่าย
        $seenByBlocker = collect(
            $this->actingAs($blocker, 'sanctum')
                ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
                ->json('data.messages')
        )->pluck('body');
        $this->assertFalse($seenByBlocker->contains('ข้อความจากคนที่ถูกบล็อก'));
        $this->assertTrue($seenByBlocker->contains('ข้อความจากคนที่บล็อก'));

        // ฝั่งคนถูกบล็อก: ก็ไม่เห็นของอีกฝ่ายเช่นกัน ไม่งั้นจะตอบโต้ข้อความที่
        // อีกฝ่ายมองไม่เห็นได้
        $seenByBlocked = collect(
            $this->actingAs($blocked, 'sanctum')
                ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
                ->json('data.messages')
        )->pluck('body');
        $this->assertFalse($seenByBlocked->contains('ข้อความจากคนที่บล็อก'));
        $this->assertTrue($seenByBlocked->contains('ข้อความจากคนที่ถูกบล็อก'));
    }

    public function test_blocking_hides_reviews_from_the_blocked_author(): void
    {
        $schedule = $this->makeSchedule();
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $this->makeReview($author, $schedule, 'รีวิวของคนที่จะถูกบล็อก');

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/reviews?trip_id='.$schedule->trip_id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/me/blocks', ['user_id' => $author->id])
            ->assertOk();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/reviews?trip_id='.$schedule->trip_id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_blocked_list_and_unblock(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create(['name' => 'คนที่ถูกบล็อก']);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/me/blocks', ['user_id' => $other->id])
            ->assertOk();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/me/blocks')
            ->assertOk()
            ->assertJsonCount(1, 'data.blocks')
            ->assertJsonPath('data.blocks.0.user_id', $other->id);

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/v1/me/blocks/{$other->id}")
            ->assertOk();

        $this->assertDatabaseCount('user_blocks', 0);
    }

    public function test_cannot_block_yourself_or_staff(): void
    {
        $viewer = User::factory()->create();
        $staff = User::factory()->create();
        $staff->assignRole('driver');

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/me/blocks', ['user_id' => $viewer->id])
            ->assertStatus(422);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/me/blocks', ['user_id' => $staff->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('user_blocks', 0);
    }

    public function test_blocking_twice_does_not_duplicate(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($viewer, 'sanctum')
                ->postJson('/api/v1/me/blocks', ['user_id' => $other->id])
                ->assertOk();
        }

        $this->assertDatabaseCount('user_blocks', 1);
    }

    // ── ตัวกรองคำ ───────────────────────────────────────────

    public function test_profanity_is_rejected_when_sending_a_chat_message(): void
    {
        $schedule = $this->makeSchedule();
        $user = User::factory()->create();
        $this->bookOnto($user, $schedule);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", ['body' => 'ไอ้เวรเอ๊ย'])
            ->assertStatus(422);

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_the_filter_sees_through_spacing_and_repeated_letters(): void
    {
        $filter = app(ContentFilterService::class);

        $this->assertTrue($filter->contains('ค ว ย'));
        $this->assertTrue($filter->contains('คววววย'));
        $this->assertTrue($filter->contains('what the FUCK'));
    }

    /** คำสุภาพที่มีคำต้องห้ามซ่อนอยู่เป็นบางส่วน ต้องไม่โดนกรอง */
    public function test_the_filter_does_not_catch_innocent_words(): void
    {
        $filter = app(ContentFilterService::class);

        $this->assertFalse($filter->contains('ระวังเหยียบรากไม้ด้วยนะครับ'));
        $this->assertFalse($filter->contains('แม่งานทริปนี้คือพี่โต้ง'));
        $this->assertFalse($filter->contains('เอาหีบใส่ของขึ้นรถด้วย'));
        $this->assertFalse($filter->contains('the class starts at 9'));
        $this->assertFalse($filter->contains(null));
    }

    // ── ฝั่งแอดมิน ──────────────────────────────────────────

    public function test_admin_sees_the_report_queue_and_can_hide_content(): void
    {
        $schedule = $this->makeSchedule();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $review = $this->makeReview(User::factory()->create(), $schedule, 'รีวิวที่โดนรายงาน');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/reports', ['type' => 'review', 'id' => $review->id, 'reason' => 'spam'])
            ->assertOk();

        $list = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/moderation/reports')
            ->assertOk()
            ->assertJsonPath('meta.counts.open', 1)
            ->assertJsonPath('data.0.preview.excerpt', 'รีวิวที่โดนรายงาน');

        $reportId = $list->json('data.0.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/moderation/reports/{$reportId}/resolve", ['action' => 'hide'])
            ->assertOk();

        $this->assertFalse((bool) $review->fresh()->is_approved);
        $this->assertDatabaseHas('content_reports', [
            'id' => $reportId,
            'status' => ContentReport::STATUS_ACTIONED,
            'resolved_by' => $admin->id,
        ]);
    }

    public function test_dismissing_closes_every_open_report_on_the_same_content(): void
    {
        $schedule = $this->makeSchedule();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $review = $this->makeReview(User::factory()->create(), $schedule);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs(User::factory()->create(), 'sanctum')
                ->postJson('/api/v1/reports', ['type' => 'review', 'id' => $review->id, 'reason' => 'spam']);
        }

        $reportId = ContentReport::first()->id;

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/moderation/reports/{$reportId}/resolve", ['action' => 'dismiss'])
            ->assertOk();

        $this->assertEquals(0, ContentReport::open()->count());
        $this->assertTrue((bool) $review->fresh()->is_approved);
    }

    public function test_customers_cannot_reach_the_admin_queue(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/admin/moderation/reports')
            ->assertStatus(403);
    }

    public function test_admin_can_see_how_often_a_user_has_been_reported(): void
    {
        $schedule = $this->makeSchedule();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $author = User::factory()->create();
        $review = $this->makeReview($author, $schedule);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/reports', ['type' => 'review', 'id' => $review->id, 'reason' => 'spam']);
        UserBlock::create(['blocker_id' => User::factory()->create()->id, 'blocked_id' => $author->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/moderation/users/{$author->id}")
            ->assertOk()
            ->assertJsonPath('data.reports_received', 1)
            ->assertJsonPath('data.reports_open', 1)
            ->assertJsonPath('data.times_blocked', 1);
    }

    public function test_guests_cannot_report_or_block(): void
    {
        $this->postJson('/api/v1/reports', ['type' => 'review', 'id' => 1])
            ->assertStatus(401);

        $this->postJson('/api/v1/me/blocks', ['user_id' => 1])
            ->assertStatus(401);

        $this->getJson('/api/v1/me/blocks')->assertStatus(401);
    }

    public function test_report_reasons_are_served_to_the_app(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/moderation/reasons')
            ->assertOk()
            ->assertJsonCount(count(ModerationService::REASONS), 'data.reasons')
            ->assertJsonPath('data.reasons.0.key', 'spam');
    }
}
