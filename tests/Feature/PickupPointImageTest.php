<?php

namespace Tests\Feature;

use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PickupPointImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Force the upload helper to fall back to the public disk during tests.
        config()->set('filesystems.default', 'public');
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
            'title' => 'Pickup Trip',
            'slug' => 'pickup-trip',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);
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

    public function test_admin_can_upload_a_pickup_point_image(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/pickup-points/image', [
                'file' => UploadedFile::fake()->image('point.jpg', 800, 600),
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['url']]);

        $files = Storage::disk('public')->files('pickup-points');
        $this->assertCount(1, $files);
    }

    public function test_pickup_point_image_upload_rejects_non_image(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/pickup-points/image', [
                'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])->assertStatus(422);
    }

    public function test_unauthenticated_user_cannot_upload_pickup_image(): void
    {
        $this->postJson('/api/v1/admin/pickup-points/image', [
            'file' => UploadedFile::fake()->image('p.jpg'),
        ])->assertStatus(401);
    }

    public function test_schedule_pickup_point_stores_and_exposes_image_url(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/pickup-points", [
                'region' => 'north',
                'region_label' => 'ภาคเหนือ',
                'pickup_location' => 'ปั๊ม ปตท. เชียงใหม่',
                'price' => 500,
                'image_url' => 'https://cdn.example.com/pickup-points/abc.jpg',
            ])
            ->assertCreated()
            ->assertJsonPath('data.image_url', 'https://cdn.example.com/pickup-points/abc.jpg');

        $this->assertDatabaseHas('schedule_pickup_points', [
            'schedule_id' => $schedule->id,
            'image_url' => 'https://cdn.example.com/pickup-points/abc.jpg',
        ]);
    }

    public function test_schedule_pickup_image_appears_in_public_schedule_resource(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip());
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'north',
            'region_label' => 'ภาคเหนือ',
            'pickup_location' => 'ปั๊ม ปตท. เชียงใหม่',
            'price' => 500,
            'image_url' => 'https://cdn.example.com/pickup-points/abc.jpg',
        ]);

        $this->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.pickup_points.0.image_url', 'https://cdn.example.com/pickup-points/abc.jpg');
    }

    public function test_vehicle_pickup_point_stores_and_exposes_image_url(): void
    {
        $admin = $this->makeAdmin();
        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'กข-1234',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/vehicles/{$vehicle->id}/pickup-points", [
                'region' => 'central',
                'region_label' => 'ภาคกลาง',
                'pickup_location' => 'BTS หมอชิต',
                'image_url' => 'https://cdn.example.com/pickup-points/mochit.jpg',
            ])
            ->assertCreated()
            ->assertJsonPath('data.image_url', 'https://cdn.example.com/pickup-points/mochit.jpg');

        $this->assertDatabaseHas('vehicle_pickup_points', [
            'vehicle_id' => $vehicle->id,
            'image_url' => 'https://cdn.example.com/pickup-points/mochit.jpg',
        ]);
    }

    public function test_pickup_point_images_endpoint_returns_distinct_used_images(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        // Two schedule points share the same image (should dedupe to one)
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'north', 'region_label' => 'ภาคเหนือ',
            'pickup_location' => 'จุด A', 'price' => 100,
            'image_url' => 'https://cdn.example.com/pickup-points/shared.jpg',
        ]);
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'north', 'region_label' => 'ภาคเหนือ',
            'pickup_location' => 'จุด B', 'price' => 100,
            'image_url' => 'https://cdn.example.com/pickup-points/shared.jpg',
        ]);
        // A point without an image should be excluded
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'south', 'region_label' => 'ภาคใต้',
            'pickup_location' => 'จุด C', 'price' => 100, 'image_url' => null,
        ]);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 10, 'license_plate' => 'กข-1234',
        ]);
        $vehicle->pickupPoints()->create([
            'region' => 'central', 'region_label' => 'ภาคกลาง',
            'pickup_location' => 'BTS หมอชิต',
            'image_url' => 'https://cdn.example.com/pickup-points/mochit.jpg',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/pickup-points/images')
            ->assertOk()
            ->assertJsonStructure(['data' => [['url', 'label']]]);

        $urls = collect($response->json('data'))->pluck('url');
        $this->assertCount(2, $urls); // shared.jpg deduped + mochit.jpg
        $this->assertTrue($urls->contains('https://cdn.example.com/pickup-points/shared.jpg'));
        $this->assertTrue($urls->contains('https://cdn.example.com/pickup-points/mochit.jpg'));
    }

    public function test_invalid_image_url_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/pickup-points", [
                'region' => 'north',
                'region_label' => 'ภาคเหนือ',
                'pickup_location' => 'จุดทดสอบ',
                'price' => 100,
                'image_url' => 'not-a-valid-url',
            ])->assertStatus(422);
    }
}
