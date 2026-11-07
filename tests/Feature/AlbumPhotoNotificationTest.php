<?php

namespace Tests\Feature;

use App\Jobs\NotifyAlbumPhotosAddedJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\AlbumPhotoNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "รูปทริปมาแล้ว" — สตาฟ/แอดมินเพิ่มรูปเข้าอัลบั้มรอบ ลูกค้าได้แจ้งเตือนครั้งเดียวต่อชุด
 */
class AlbumPhotoNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Trip $trip;

    private TripSchedule $schedule;

    private User $staff;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.r2.bucket', null);
        Storage::fake('public');

        // 12:00 เวลาไทย — นอกช่วงห้ามรบกวน
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-07 12:00', 'Asia/Bangkok'));

        Role::findOrCreate('staff');
        Role::findOrCreate('admin');

        $this->trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'album-push-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'max_participants' => 15,
            'price_per_person' => 4500,
            'status' => 'active',
        ]);

        $this->schedule = $this->makeSchedule('2026-11-05', '2026-11-07');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');
        $this->schedule->staff()->attach($this->staff->id);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeSchedule(string $departure, ?string $return): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => $departure,
            'return_date' => $return,
            'total_seats' => 15,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function book(?TripSchedule $schedule = null, string $status = 'confirmed', ?User $owner = null): Booking
    {
        return Booking::create([
            'booking_ref' => 'LLK-ALB-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'user_id' => ($owner ?? User::factory()->create())->id,
            'schedule_id' => ($schedule ?? $this->schedule)->id,
            'status' => $status,
            'total_amount' => 4500,
            'paid_amount' => $status === 'confirmed' ? 4500 : 0,
            'payment_type' => 'full',
        ]);
    }

    private function staffUpload(int $count = 3, ?TripSchedule $schedule = null)
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[] = UploadedFile::fake()->image("p{$i}.jpg", 800, 600);
        }

        return $this->actingAs($this->staff)
            ->postJson('/api/v1/staff/schedules/'.($schedule ?? $this->schedule)->id.'/photos', ['files' => $files]);
    }

    private function albumPushes(): Collection
    {
        return SmartNotification::where('type', NotifyAlbumPhotosAddedJob::TYPE)->get();
    }

    public function test_travellers_get_one_push_with_their_own_booking_ref(): void
    {
        $booking = $this->book();
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);
        $pending = $this->book(status: 'pending');
        $cancelled = $this->book(status: 'cancelled');

        $this->staffUpload(3)->assertCreated();

        $pushes = $this->albumPushes();
        $this->assertEqualsCanonicalizing(
            [$booking->user_id, $friend->id],
            $pushes->pluck('user_id')->all(),
        );
        $this->assertNotContains($pending->user_id, $pushes->pluck('user_id'));
        $this->assertNotContains($cancelled->user_id, $pushes->pluck('user_id'));
        $this->assertNotContains($this->staff->id, $pushes->pluck('user_id'));

        $push = $pushes->firstWhere('user_id', $friend->id);
        $this->assertSame('📸 รูปจากทริปมาแล้ว', $push->title);
        $this->assertStringContainsString('ทริปภูกระดึง', $push->body);
        $this->assertStringContainsString('3 รูป', $push->body);
        $this->assertSame($booking->booking_ref, $push->data['booking_ref']);
        $this->assertSame('album', $push->data['route']);
    }

    public function test_a_batch_of_uploads_schedules_a_single_delayed_push(): void
    {
        Queue::fake([NotifyAlbumPhotosAddedJob::class]);
        $this->book();

        $this->staffUpload(3)->assertCreated();
        $this->staffUpload(3)->assertCreated();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('a.jpg')],
            ])->assertCreated();

        Queue::assertPushed(NotifyAlbumPhotosAddedJob::class, 1);
        Queue::assertPushed(NotifyAlbumPhotosAddedJob::class, function ($job) {
            $at = CarbonImmutable::instance($job->delay);

            return $job->scheduleId === $this->schedule->id
                && (int) round(now()->diffInMinutes($at)) === AlbumPhotoNotifier::SETTLE_MINUTES;
        });
    }

    public function test_the_job_counts_every_photo_of_the_batch_when_it_runs(): void
    {
        Queue::fake([NotifyAlbumPhotosAddedJob::class]);
        $booking = $this->book();

        $this->staffUpload(3)->assertCreated();
        $this->staffUpload(2)->assertCreated();

        $job = Queue::pushed(NotifyAlbumPhotosAddedJob::class)->first();
        app()->call([$job, 'handle']);

        $push = $this->albumPushes()->sole();
        $this->assertSame($booking->user_id, $push->user_id);
        $this->assertStringContainsString('5 รูป', $push->body);
        $this->assertFalse(Cache::has(AlbumPhotoNotifier::pendingKey($this->schedule->id)));
    }

    public function test_a_later_batch_says_more_photos_and_waits_for_the_cooldown(): void
    {
        $this->book();
        $this->staffUpload(2)->assertCreated(); // ส่งทันที (คิว sync)
        $this->assertCount(1, $this->albumPushes());

        Queue::fake([NotifyAlbumPhotosAddedJob::class]);
        Carbon::setTestNow(now()->addMinutes(20));
        $this->staffUpload(4)->assertCreated();

        $job = Queue::pushed(NotifyAlbumPhotosAddedJob::class)->sole();
        $this->assertGreaterThanOrEqual(
            AlbumPhotoNotifier::COOLDOWN_MINUTES - 20 - 1,
            (int) now()->diffInMinutes(CarbonImmutable::instance($job->delay)),
        );

        app()->call([$job, 'handle']);
        $latest = $this->albumPushes()->sortByDesc('id')->first();
        $this->assertSame('📸 มีรูปทริปเพิ่มแล้ว', $latest->title);
        $this->assertStringContainsString('อีก 4 รูป', $latest->body);
    }

    public function test_uploads_at_night_wait_until_morning(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-07 23:30', 'Asia/Bangkok'));
        Queue::fake([NotifyAlbumPhotosAddedJob::class]);
        $this->book();

        $this->staffUpload(1)->assertCreated();

        Queue::assertPushed(NotifyAlbumPhotosAddedJob::class, function ($job) {
            $at = CarbonImmutable::instance($job->delay)->setTimezone('Asia/Bangkok');

            return $at->format('Y-m-d H:i') === '2026-11-08 08:00';
        });
    }

    public function test_no_push_before_the_round_departs_or_when_it_is_cancelled(): void
    {
        Queue::fake([NotifyAlbumPhotosAddedJob::class]);

        $future = $this->makeSchedule('2026-11-20', '2026-11-22');
        $this->book($future);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$future->id}/photos", [
                'files' => [UploadedFile::fake()->image('a.jpg')],
            ])->assertCreated();

        $cancelled = $this->makeSchedule('2026-11-05', '2026-11-06');
        $cancelled->update(['status' => 'cancelled']);
        app(AlbumPhotoNotifier::class)->photosAdded($cancelled);

        Queue::assertNothingPushed();
    }

    public function test_no_push_when_the_photos_were_removed_before_sending(): void
    {
        Queue::fake([NotifyAlbumPhotosAddedJob::class]);
        $this->book();
        $this->staffUpload(2)->assertCreated();

        foreach ($this->schedule->photos()->get() as $photo) {
            $this->actingAs($this->admin)
                ->deleteJson("/api/v1/admin/schedules/{$this->schedule->id}/photos/{$photo->id}")
                ->assertOk();
        }

        app()->call([Queue::pushed(NotifyAlbumPhotosAddedJob::class)->sole(), 'handle']);

        $this->assertCount(0, $this->albumPushes());
    }

    public function test_applying_photos_to_another_round_notifies_that_round(): void
    {
        $sibling = $this->makeSchedule('2026-11-05', '2026-11-07');
        $siblingBooking = $this->book($sibling);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ])->assertCreated();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->schedule->id}/photos/apply", [
                'schedule_ids' => [$sibling->id],
            ])->assertOk();

        $push = $this->albumPushes()->firstWhere('user_id', $siblingBooking->user_id);
        $this->assertNotNull($push);
        $this->assertStringContainsString('2 รูป', $push->body);
        $this->assertSame($siblingBooking->booking_ref, $push->data['booking_ref']);
    }

    public function test_a_linked_friend_can_open_the_booking_photos(): void
    {
        $booking = $this->book();
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);
        $this->staffUpload(2)->assertCreated();

        $this->actingAs($friend)
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/photos")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/photos")
            ->assertNotFound();
    }
}
