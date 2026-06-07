<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SchedulePhoto;
use App\Models\Trip;
use App\Models\TripPhoto;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Force PhotoController to use a fake "public" disk during tests.
        // The disk() helper falls back to 'public' when r2 bucket is not configured.
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

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'Photo Trip',
            'slug' => 'photo-trip',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_upload_photos_to_a_trip(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/trips/{$trip->id}/photos", [
                'files' => [
                    UploadedFile::fake()->image('one.jpg', 800, 600),
                    UploadedFile::fake()->image('two.jpg', 800, 600),
                ],
            ]);

        $response->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, $trip->photos()->count());
        $first = $trip->photos()->first();
        Storage::disk('public')->assertExists($first->path);
        $this->assertStringStartsWith("trips/{$trip->id}/", $first->path);
    }

    public function test_uploaded_photos_appear_in_public_trip_resource(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/trips/{$trip->id}/photos", [
                'files' => [UploadedFile::fake()->image('p.jpg', 600, 400)],
            ])->assertCreated();

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonStructure(['data' => ['photos' => [['id', 'url', 'sort_order']]]]);
    }

    public function test_admin_can_delete_a_trip_photo_and_file_is_removed(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/trips/{$trip->id}/photos", [
                'files' => [UploadedFile::fake()->image('zap.jpg', 400, 300)],
            ])->assertCreated();

        $photo = $trip->photos()->first();
        $path = $photo->path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/trips/{$trip->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertSame(0, $trip->photos()->count());
        Storage::disk('public')->assertMissing($path);
    }

    private function makeSchedule(Trip $trip): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(7)->toDateString(),
            'return_date' => now()->addDays(8)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    public function test_admin_can_upload_photos_to_a_schedule(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('s.jpg', 600, 400)],
            ])->assertCreated()
            ->assertJsonCount(1, 'data');

        $this->assertSame(1, $schedule->photos()->count());
    }

    public function test_schedule_photos_are_not_exposed_publicly(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('s.jpg')],
            ])->assertCreated();

        $response = $this->getJson("/api/v1/schedules/{$schedule->id}")->assertOk();
        $data = $response->json('data');
        $this->assertArrayNotHasKey('photos', $data, 'Schedule resource must not leak photos publicly.');
    }

    public function test_booking_owner_can_fetch_their_schedule_photos(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])->assertCreated();

        $customer = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => 'LLK-TEST-0001',
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'payment_type' => 'full',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/photos")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'url', 'sort_order']]]);
    }

    public function test_non_owner_cannot_fetch_booking_photos(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('x.jpg')],
            ])->assertCreated();

        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $booking = Booking::create([
            'booking_ref' => 'LLK-TEST-0002',
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'payment_type' => 'full',
        ]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/photos")
            ->assertNotFound();
    }

    public function test_cancelled_booking_cannot_fetch_photos(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('x.jpg')],
            ])->assertCreated();

        $customer = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => 'LLK-TEST-0003',
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'cancelled',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'payment_type' => 'full',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/photos")
            ->assertNotFound();
    }

    public function test_upload_generates_a_downscaled_thumbnail(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('big.jpg', 2400, 1600)],
            ])->assertCreated()
            ->assertJsonStructure(['data' => [['id', 'url', 'thumb_url']]]);

        $photo = $schedule->photos()->first();

        // Full image is stored untouched; a separate thumbnail exists alongside it.
        $this->assertNotNull($photo->thumb_path);
        $this->assertNotSame($photo->path, $photo->thumb_path);
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists($photo->thumb_path);

        // Thumbnail's longest edge is capped at 800px; the response exposes its url.
        [$tw, $th] = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_path));
        $this->assertLessThanOrEqual(800, max($tw, $th));
        $this->assertStringContainsString('/thumbs/', $photo->thumb_path);
        $this->assertNotNull($response->json('data.0.thumb_url'));
    }

    public function test_deleting_a_photo_also_removes_its_thumbnail(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", [
                'files' => [UploadedFile::fake()->image('z.jpg', 1200, 800)],
            ])->assertCreated();

        $photo = $schedule->photos()->first();
        $full = $photo->path;
        $thumb = $photo->thumb_path;
        Storage::disk('public')->assertExists($thumb);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/schedules/{$schedule->id}/photos/{$photo->id}")
            ->assertOk();

        Storage::disk('public')->assertMissing($full);
        Storage::disk('public')->assertMissing($thumb);
    }

    public function test_admin_can_apply_one_rounds_photos_to_other_rounds(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $source = $this->makeSchedule($trip);
        $roundB = $this->makeSchedule($trip);
        $roundC = $this->makeSchedule($trip);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos", [
                'files' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])->assertCreated();

        $sourcePhotoIds = $source->photos()->pluck('schedule_photos.id')->sort()->values()->all();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos/apply", [
                'schedule_ids' => [$roundB->id, $roundC->id],
            ])->assertOk()
            ->assertJsonPath('data.attached', 4)
            ->assertJsonPath('data.schedules', 2);

        // Same photo records (same R2 objects) are now shared across all three rounds.
        $this->assertSame($sourcePhotoIds, $roundB->photos()->pluck('schedule_photos.id')->sort()->values()->all());
        $this->assertSame($sourcePhotoIds, $roundC->photos()->pluck('schedule_photos.id')->sort()->values()->all());
        $this->assertSame(2, SchedulePhoto::count(), 'No duplicate photo rows/files were created.');
    }

    public function test_apply_is_idempotent_and_skips_already_attached_photos(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $source = $this->makeSchedule($trip);
        $target = $this->makeSchedule($trip);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos", [
                'files' => [UploadedFile::fake()->image('a.jpg')],
            ])->assertCreated();

        $apply = fn () => $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos/apply", [
                'schedule_ids' => [$target->id],
            ]);

        $apply()->assertOk()->assertJsonPath('data.attached', 1);
        $apply()->assertOk()->assertJsonPath('data.attached', 0);

        $this->assertSame(1, $target->photos()->count());
    }

    public function test_apply_rejects_rounds_from_a_different_trip(): void
    {
        $admin = $this->makeAdmin();
        $source = $this->makeSchedule($this->makeTrip());

        $otherTrip = Trip::create([
            'title' => 'Other Trip', 'slug' => 'other-trip', 'type' => 'trekking',
            'location' => 'Pai', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 8, 'price_per_person' => 1000, 'status' => 'active',
        ]);
        $foreign = $this->makeSchedule($otherTrip);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos", [
                'files' => [UploadedFile::fake()->image('a.jpg')],
            ])->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos/apply", [
                'schedule_ids' => [$foreign->id],
            ])->assertStatus(422);

        $this->assertSame(0, $foreign->photos()->count());
    }

    public function test_deleting_a_shared_photo_from_one_round_keeps_the_file_for_others(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $source = $this->makeSchedule($trip);
        $target = $this->makeSchedule($trip);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos", [
                'files' => [UploadedFile::fake()->image('a.jpg')],
            ])->assertCreated();

        $photo = $source->photos()->first();
        $path = $photo->path;

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos/apply", [
                'schedule_ids' => [$target->id],
            ])->assertOk();

        // Delete from the source round only — file must survive because target still uses it.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/schedules/{$source->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertSame(0, $source->photos()->count());
        $this->assertSame(1, $target->photos()->count());
        Storage::disk('public')->assertExists($path);

        // Delete from the last round — now the file is removed from disk.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/schedules/{$target->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertSame(0, SchedulePhoto::count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_schedule_reorder_is_independent_per_round(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $source = $this->makeSchedule($trip);
        $target = $this->makeSchedule($trip);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos", [
                'files' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$source->id}/photos/apply", [
                'schedule_ids' => [$target->id],
            ])->assertOk();

        $ids = $source->photos()->pluck('schedule_photos.id')->all();
        $reversed = array_reverse($ids);

        // Reorder only the target round.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$target->id}/photos/reorder", [
                'order' => $reversed,
            ])->assertOk();

        $this->assertSame($reversed, $target->photos()->pluck('schedule_photos.id')->all());
        // Source round order is untouched.
        $this->assertSame($ids, $source->photos()->pluck('schedule_photos.id')->all());
    }

    public function test_upload_rejects_non_image_files(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/trips/{$trip->id}/photos", [
                'files' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
            ])->assertStatus(422);
    }

    public function test_unauthenticated_user_cannot_upload(): void
    {
        $trip = $this->makeTrip();
        $this->postJson("/api/v1/admin/trips/{$trip->id}/photos", [
            'files' => [UploadedFile::fake()->image('p.jpg')],
        ])->assertStatus(401);
    }

    public function test_reorder_updates_sort_order(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/trips/{$trip->id}/photos", [
                'files' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                    UploadedFile::fake()->image('c.jpg'),
                ],
            ])->assertCreated();

        $ids = $trip->photos()->orderBy('sort_order')->pluck('id')->toArray();
        $reversed = array_reverse($ids);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/trips/{$trip->id}/photos/reorder", [
                'order' => $reversed,
            ])->assertOk();

        $newOrder = TripPhoto::where('trip_id', $trip->id)->orderBy('sort_order')->pluck('id')->toArray();
        $this->assertSame($reversed, $newOrder);
    }
}
