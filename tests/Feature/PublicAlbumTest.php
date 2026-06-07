<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicAlbumTest extends TestCase
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

    private function makeScheduleWithPhotos(int $count = 2): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Album Trip', 'slug' => 'album-trip', 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 8, 'price_per_person' => 1000, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-06-07',
            'return_date' => '2026-06-08',
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);

        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = UploadedFile::fake()->image("p{$i}.jpg", 600, 400);
        }
        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", ['files' => $files])
            ->assertCreated();

        return $schedule;
    }

    public function test_admin_can_enable_and_revoke_the_share_link(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeScheduleWithPhotos(1);

        // No link yet.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/schedules/{$schedule->id}/photos/share")
            ->assertOk()
            ->assertJsonPath('data.token', null);

        // Enable.
        $token = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos/share")
            ->assertOk()
            ->json('data.token');

        $this->assertNotEmpty($token);
        $this->assertSame($token, $schedule->fresh()->photo_token);

        // Revoke.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/schedules/{$schedule->id}/photos/share")
            ->assertOk()
            ->assertJsonPath('data.token', null);

        $this->assertNull($schedule->fresh()->photo_token);
    }

    public function test_rotating_the_link_invalidates_the_old_token(): void
    {
        $admin = $this->makeAdmin();
        $schedule = $this->makeScheduleWithPhotos(1);

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos/share")
            ->json('data.token');

        $second = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos/share", ['rotate' => true])
            ->json('data.token');

        $this->assertNotSame($first, $second);
        $this->getJson("/api/v1/album/{$first}/photos")->assertNotFound();
        $this->getJson("/api/v1/album/{$second}/photos")->assertOk();
    }

    public function test_anyone_with_the_token_can_view_the_album(): void
    {
        $schedule = $this->makeScheduleWithPhotos(2);
        $token = $schedule->ensurePhotoToken();

        // No authentication at all — public access.
        $this->getJson("/api/v1/album/{$token}/photos")
            ->assertOk()
            ->assertJsonPath('data.trip_title', 'Album Trip')
            ->assertJsonPath('data.count', 2)
            ->assertJsonCount(2, 'data.photos')
            ->assertJsonStructure(['data' => ['photos' => [['id', 'url']]]]);
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->getJson('/api/v1/album/doesnotexist/photos')->assertNotFound();
    }

    public function test_public_can_download_a_single_photo(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $token = $schedule->ensurePhotoToken();
        $photoId = $schedule->photos()->first()->id;

        $response = $this->get("/album/{$token}/download/{$photoId}");
        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_public_can_download_all_photos_as_zip(): void
    {
        $schedule = $this->makeScheduleWithPhotos(3);
        $token = $schedule->ensurePhotoToken();

        $response = $this->get("/album/{$token}/download");
        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('content-type'));

        // The streamed body should be a valid, non-empty zip with 3 entries.
        $content = $response->streamedContent();
        $this->assertNotEmpty($content);

        $tmp = tempnam(sys_get_temp_dir(), 'ziptest');
        file_put_contents($tmp, $content);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertSame(3, $zip->numFiles);
        $zip->close();
        @unlink($tmp);
    }

    public function test_download_requires_a_valid_token(): void
    {
        $this->get('/album/nope/download')->assertNotFound();
    }
}
