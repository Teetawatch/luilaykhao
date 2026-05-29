<?php

namespace Tests\Feature;

use App\Models\Booking;
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
