<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\Trip;
use App\Models\TripPost;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TripPostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaDisk::name());
        config()->set('services.thaibulksms.enabled', false);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
    }

    private function makeTrip(string $slug = 'feed-trip'): Trip
    {
        return Trip::create([
            'title' => 'Feed Trip', 'slug' => $slug, 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2000, 'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, string $departureDate): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate,
            'return_date' => date('Y-m-d', strtotime($departureDate.' +1 day')),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /** จอง confirmed บนรอบที่เดินทางไปแล้ว = มีสิทธิ์โพสต์ */
    private function makeTraveler(Trip $trip, ?string $departureDate = null): array
    {
        $user = User::factory()->create();
        $schedule = $this->makeSchedule($trip, $departureDate ?? now()->subDays(7)->toDateString());

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2000,
        ]);

        return [$user, $booking, $schedule];
    }

    private function makePost(User $user, Trip $trip, ?string $caption = 'สนุกมาก!'): TripPost
    {
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/posts", [
                'images' => [UploadedFile::fake()->image('a.jpg', 800, 600)],
                'caption' => $caption,
            ])
            ->assertCreated();

        return TripPost::findOrFail($response->json('data.id'));
    }

    // ── สิทธิ์การโพสต์ ────────────────────────────────────────

    public function test_traveler_can_post_photos_to_feed(): void
    {
        $trip = $this->makeTrip();
        [$user, , $schedule] = $this->makeTraveler($trip);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/posts", [
                'images' => [
                    UploadedFile::fake()->image('a.jpg', 800, 600),
                    UploadedFile::fake()->image('b.jpg', 800, 600),
                ],
                'caption' => 'วิวสวยมาก 🏔️',
            ])
            ->assertCreated()
            ->assertJsonPath('data.caption', 'วิวสวยมาก 🏔️')
            ->assertJsonPath('data.is_mine', true);

        $post = TripPost::findOrFail($response->json('data.id'));
        $this->assertCount(2, $post->photos);
        $this->assertEquals($schedule->id, $post->schedule_id);
        Storage::disk(MediaDisk::name())->assertExists($post->photos[0]['path']);
    }

    public function test_companion_member_can_post(): void
    {
        $trip = $this->makeTrip();
        [, $booking] = $this->makeTraveler($trip);

        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
            'invited_by' => $booking->user_id,
            'accepted_at' => now(),
        ]);

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/posts", [
                'images' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertCreated();
    }

    public function test_non_traveler_cannot_post(): void
    {
        $trip = $this->makeTrip();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/posts", [
                'images' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertStatus(422);
    }

    public function test_future_trip_booking_cannot_post_yet(): void
    {
        $trip = $this->makeTrip();
        [$user] = $this->makeTraveler($trip, now()->addDays(10)->toDateString());

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/posts", [
                'images' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertStatus(422);
    }

    public function test_guest_cannot_post(): void
    {
        $trip = $this->makeTrip();

        $this->postJson("/api/v1/trips/{$trip->slug}/posts", [
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertUnauthorized();
    }

    // ── ฟีดสาธารณะ ────────────────────────────────────────────

    public function test_public_feed_lists_published_posts_only(): void
    {
        $trip = $this->makeTrip();
        [$user] = $this->makeTraveler($trip);

        $visible = $this->makePost($user, $trip, 'โพสต์ปกติ');
        $hidden = $this->makePost($user, $trip, 'โพสต์ที่ถูกซ่อน');
        $hidden->update(['status' => TripPost::STATUS_HIDDEN]);

        $this->getJson("/api/v1/trips/{$trip->slug}/posts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_aggregate_feed_spans_trips(): void
    {
        $tripA = $this->makeTrip('feed-a');
        $tripB = $this->makeTrip('feed-b');
        [$userA] = $this->makeTraveler($tripA);
        [$userB] = $this->makeTraveler($tripB);

        $this->makePost($userA, $tripA);
        $this->makePost($userB, $tripB);

        $this->getJson('/api/v1/trip-posts')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_trip_feed_includes_can_post_for_logged_in_viewer(): void
    {
        $trip = $this->makeTrip();
        [$traveler] = $this->makeTraveler($trip);
        $stranger = User::factory()->create();

        $this->actingAs($traveler, 'sanctum')
            ->getJson("/api/v1/trips/{$trip->slug}/posts")
            ->assertOk()
            ->assertJsonPath('meta.can_post', true);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/trips/{$trip->slug}/posts")
            ->assertOk()
            ->assertJsonPath('meta.can_post', false);
    }

    // ── ไลก์ ─────────────────────────────────────────────────

    public function test_like_toggles_and_notifies_owner(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);
        $liker = User::factory()->create();

        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/v1/trip-posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.likes_count', 1);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'trip_post_liked',
        ]);

        // ฟีดบอกว่าผู้ดูคนนี้ไลก์แล้ว
        $this->actingAs($liker, 'sanctum')
            ->getJson("/api/v1/trips/{$trip->slug}/posts")
            ->assertJsonPath('data.0.liked_by_me', true);

        // กดซ้ำ = เลิกไลก์
        $this->actingAs($liker, 'sanctum')
            ->postJson("/api/v1/trip-posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', false)
            ->assertJsonPath('data.likes_count', 0);
    }

    public function test_liking_own_post_does_not_notify(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/trip-posts/{$post->id}/like")
            ->assertOk();

        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'trip_post_liked',
        ]);
    }

    // ── คอมเมนต์ ─────────────────────────────────────────────

    public function test_comment_flow_with_notification_and_delete(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);
        $commenter = User::factory()->create();

        $commentId = $this->actingAs($commenter, 'sanctum')
            ->postJson("/api/v1/trip-posts/{$post->id}/comments", [
                'body' => 'รูปสวยมากครับ',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertEquals(1, $post->fresh()->comments_count);
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $owner->id,
            'type' => 'trip_post_comment',
        ]);

        // รายการคอมเมนต์อ่านได้สาธารณะ
        $this->getJson("/api/v1/trip-posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'รูปสวยมากครับ');

        // เจ้าของคอมเมนต์ลบเองได้
        $this->actingAs($commenter, 'sanctum')
            ->deleteJson("/api/v1/trip-posts/{$post->id}/comments/{$commentId}")
            ->assertOk();
        $this->assertEquals(0, $post->fresh()->comments_count);
    }

    public function test_random_user_cannot_delete_others_comment(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);
        $commenter = User::factory()->create();
        $random = User::factory()->create();

        $commentId = $this->actingAs($commenter, 'sanctum')
            ->postJson("/api/v1/trip-posts/{$post->id}/comments", ['body' => 'สวัสดี'])
            ->json('data.id');

        $this->actingAs($random, 'sanctum')
            ->deleteJson("/api/v1/trip-posts/{$post->id}/comments/{$commentId}")
            ->assertStatus(422);
    }

    // ── รายงาน / ซ่อน ────────────────────────────────────────

    public function test_report_notifies_admins_and_auto_hides_at_threshold(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        for ($i = 1; $i <= TripPost::AUTO_HIDE_REPORTS; $i++) {
            $reporter = User::factory()->create();
            $this->actingAs($reporter, 'sanctum')
                ->postJson("/api/v1/trip-posts/{$post->id}/report", [
                    'reason' => 'ไม่เหมาะสม',
                ])
                ->assertOk();
        }

        $post->refresh();
        $this->assertEquals(TripPost::AUTO_HIDE_REPORTS, $post->reports_count);
        $this->assertEquals(TripPost::STATUS_HIDDEN, $post->status);

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $admin->id,
            'type' => 'trip_post_reported',
        ]);
    }

    public function test_cannot_report_twice(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);
        $reporter = User::factory()->create();

        $this->actingAs($reporter, 'sanctum')
            ->postJson("/api/v1/trip-posts/{$post->id}/report")
            ->assertOk();

        $this->actingAs($reporter, 'sanctum')
            ->postJson("/api/v1/trip-posts/{$post->id}/report")
            ->assertStatus(422);
    }

    // ── ลบ / แอดมิน ──────────────────────────────────────────

    public function test_owner_can_delete_own_post_and_files_are_removed(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);
        $path = $post->photos[0]['path'];

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/trip-posts/{$post->id}")
            ->assertOk();

        $this->assertDatabaseMissing('trip_posts', ['id' => $post->id]);
        Storage::disk(MediaDisk::name())->assertMissing($path);
    }

    public function test_non_owner_cannot_delete_post(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);
        $random = User::factory()->create();

        $this->actingAs($random, 'sanctum')
            ->deleteJson("/api/v1/trip-posts/{$post->id}")
            ->assertForbidden();
    }

    public function test_admin_can_hide_unhide_and_list_all(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $post = $this->makePost($owner, $trip);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/trip-posts/{$post->id}/hide")
            ->assertOk();
        $this->assertEquals(TripPost::STATUS_HIDDEN, $post->fresh()->status);

        // ฟีดสาธารณะไม่เห็นโพสต์ที่ซ่อน แต่แอดมินเห็น
        $this->getJson("/api/v1/trips/{$trip->slug}/posts")->assertJsonCount(0, 'data');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/trip-posts?status=hidden')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/trip-posts/{$post->id}/unhide")
            ->assertOk();
        $this->assertEquals(TripPost::STATUS_PUBLISHED, $post->fresh()->status);
    }

    public function test_admin_can_filter_reported_and_gets_counts(): void
    {
        $trip = $this->makeTrip();
        [$owner] = $this->makeTraveler($trip);
        $reported = $this->makePost($owner, $trip);
        $clean = $this->makePost($owner, $trip);

        $reporter = User::factory()->create();
        $this->actingAs($reporter, 'sanctum')
            ->postJson("/api/v1/trip-posts/{$reported->id}/report")
            ->assertOk();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // ตัวกรอง "ถูกรายงาน" คืนเฉพาะโพสต์ที่มีรายงาน
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/trip-posts?reported=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reported->id)
            ->assertJsonPath('data.0.reports_count', 1);

        // meta.counts สรุปยอดสำหรับ badge
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/trip-posts')
            ->assertOk()
            ->assertJsonPath('meta.counts.total', 2)
            ->assertJsonPath('meta.counts.reported', 1)
            ->assertJsonPath('meta.counts.hidden', 0);

        $this->assertNotNull($clean);
    }
}
