<?php

namespace Tests\Feature;

use App\Jobs\DeleteMediaFilesJob;
use App\Jobs\GeneratePhotoThumbnailJob;
use App\Models\SchedulePhoto;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Thumbnailing and file removal are deliberately off the request path: doing them
 * inline made uploads take minutes and made a bulk delete time out and roll back.
 * These tests pin that contract — the HTTP request must only touch the DB and the
 * original object, and hand everything else to the queue.
 */
class PhotoAsyncProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('filesystems.disks.r2.bucket', null);
        Storage::fake('public');
    }

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeTrip(string $slug = 'photo-async-trip'): Trip
    {
        return Trip::create([
            'title' => 'Photo Async Trip',
            'slug' => $slug,
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(?Trip $trip = null): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => ($trip ?? $this->makeTrip())->id,
            'departure_date' => now()->addDays(7)->toDateString(),
            'return_date' => now()->addDays(8)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    public function test_upload_returns_without_building_thumbnails_and_queues_one_job_per_photo(): void
    {
        Queue::fake();

        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [
                    UploadedFile::fake()->image('a.jpg', 2400, 1600),
                    UploadedFile::fake()->image('b.jpg', 2400, 1600),
                ],
            ])->assertCreated();

        $photos = $schedule->photos()->get();
        $this->assertCount(2, $photos);

        foreach ($photos as $photo) {
            // Original is stored inline — the customer's download must work at once.
            Storage::disk('public')->assertExists($photo->path);
            // Thumbnail is the queue's job, so nothing is written yet.
            $this->assertNull($photo->thumb_path);
        }

        Queue::assertPushed(GeneratePhotoThumbnailJob::class, 2);
    }

    public function test_queued_job_builds_the_thumbnail_from_the_stored_original(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('big.jpg', 2400, 1600)],
            ])->assertCreated();

        $photo = $schedule->photos()->first();
        $photo->update(['thumb_path' => null, 'thumb_url' => null]);

        (new GeneratePhotoThumbnailJob(SchedulePhoto::class, $photo->id))->handle();

        $photo->refresh();
        $this->assertNotNull($photo->thumb_path);
        $this->assertStringContainsString('/thumbs/', $photo->thumb_path);
        Storage::disk('public')->assertExists($photo->thumb_path);

        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_path));
        $this->assertLessThanOrEqual(800, max($w, $h));
    }

    public function test_thumbnail_job_is_idempotent_and_survives_a_deleted_photo(): void
    {
        $schedule = $this->makeSchedule();
        $photo = SchedulePhoto::create([
            'disk' => 'public',
            'path' => 'schedules/x/gone.jpg',
            'url' => 'http://example.test/gone.jpg',
        ]);
        $schedule->photos()->attach($photo->id, ['sort_order' => 1]);

        // Original missing from the bucket — must be a no-op, not an exception.
        (new GeneratePhotoThumbnailJob(SchedulePhoto::class, $photo->id))->handle();
        $this->assertNull($photo->fresh()->thumb_path);

        // Row gone entirely — also a no-op, since a retry can outlive the photo.
        $id = $photo->id;
        SchedulePhoto::where('id', $id)->delete();
        (new GeneratePhotoThumbnailJob(SchedulePhoto::class, $id))->handle();
    }

    public function test_deleting_all_photos_queues_a_single_sweep_job_for_every_file(): void
    {
        Queue::fake();

        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule();

        $photos = collect(range(1, 3))->map(fn ($i) => SchedulePhoto::create([
            'disk' => 'public',
            'path' => "schedules/{$schedule->id}/p{$i}.jpg",
            'thumb_path' => "schedules/{$schedule->id}/thumbs/p{$i}.jpg",
            'url' => "http://example.test/p{$i}.jpg",
        ]));
        foreach ($photos as $i => $photo) {
            $schedule->photos()->attach($photo->id, ['sort_order' => $i + 1]);
        }

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/schedules/{$schedule->id}/photos")
            ->assertOk()
            ->assertJsonPath('data.detached', 3)
            ->assertJsonPath('data.files_removed', 3);

        // Rows are gone synchronously; the bucket is swept by exactly one job that
        // carries every path (original + thumbnail), not one job per photo.
        $this->assertSame(0, $schedule->photos()->count());
        $this->assertSame(0, SchedulePhoto::whereIn('id', $photos->pluck('id'))->count());

        Queue::assertPushed(DeleteMediaFilesJob::class, 1);
        Queue::assertPushed(DeleteMediaFilesJob::class, function (DeleteMediaFilesJob $job) {
            return $job->disk === 'public' && count($job->paths) === 6;
        });
    }

    public function test_delete_all_does_not_queue_files_still_shared_with_another_round(): void
    {
        Queue::fake();

        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $roundA = $this->makeSchedule($trip);
        $roundB = $this->makeSchedule($trip);

        $shared = SchedulePhoto::create([
            'disk' => 'public',
            'path' => 'schedules/shared.jpg',
            'url' => 'http://example.test/shared.jpg',
        ]);
        $only = SchedulePhoto::create([
            'disk' => 'public',
            'path' => 'schedules/only.jpg',
            'url' => 'http://example.test/only.jpg',
        ]);

        $roundA->photos()->attach([$shared->id => ['sort_order' => 1], $only->id => ['sort_order' => 2]]);
        $roundB->photos()->attach($shared->id, ['sort_order' => 1]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/schedules/{$roundA->id}/photos")
            ->assertOk()
            ->assertJsonPath('data.files_removed', 1);

        // The shared photo still belongs to round B, so its file must survive.
        $this->assertNotNull($shared->fresh());
        $this->assertNull($only->fresh());

        Queue::assertPushed(DeleteMediaFilesJob::class, function (DeleteMediaFilesJob $job) {
            return $job->paths === ['schedules/only.jpg'];
        });
    }

    public function test_admin_schedules_list_carries_a_photo_count(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $withPhotos = $this->makeSchedule($trip);
        $empty = $this->makeSchedule($trip);

        foreach (range(1, 2) as $i) {
            $photo = SchedulePhoto::create([
                'disk' => 'public',
                'path' => "schedules/{$withPhotos->id}/p{$i}.jpg",
                'url' => "http://example.test/p{$i}.jpg",
            ]);
            $withPhotos->photos()->attach($photo->id, ['sort_order' => $i]);
        }

        // The admin page reads this instead of fetching each round's full photo list.
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/schedules?per_page=50')
            ->assertOk();

        $counts = collect($response->json('data'))->pluck('photos_count', 'id');
        $this->assertSame(2, $counts[$withPhotos->id]);
        $this->assertSame(0, $counts[$empty->id]);
    }

    public function test_sweep_job_removes_files_and_tolerates_missing_ones(): void
    {
        Storage::disk('public')->put('schedules/keep.jpg', 'x');
        Storage::disk('public')->put('schedules/drop.jpg', 'x');

        (new DeleteMediaFilesJob('public', ['schedules/drop.jpg', 'schedules/never-existed.jpg']))->handle();

        Storage::disk('public')->assertMissing('schedules/drop.jpg');
        Storage::disk('public')->assertExists('schedules/keep.jpg');
    }
}
