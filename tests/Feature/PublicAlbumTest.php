<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    public function test_album_offers_no_zip_download(): void
    {
        // ดาวน์โหลดทั้งอัลบั้มเคยสร้าง zip ใน /tmp ของเซิร์ฟเวอร์จนดิสก์ VPS เต็ม
        // จึงถอดออก ลูกค้าเลือกบันทึกเองทีละรูป
        $schedule = $this->makeScheduleWithPhotos(2);
        $token = $schedule->ensurePhotoToken();
        $ids = $schedule->photos->pluck('id')->implode(',');

        $this->assertNoRouteFor('GET', "/album/{$token}/download");

        $response = $this->get("/album/{$token}/download?ids={$ids}");
        $this->assertNotSame('application/zip', $response->headers->get('content-type'));
    }

    public function test_face_search_endpoints_are_gone(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $token = $schedule->ensurePhotoToken();
        $photoId = $schedule->photos()->first()->id;

        $this->assertNoRouteFor('POST', "/api/v1/album/{$token}/face-consent");
        $this->assertNoRouteFor('DELETE', "/api/v1/album/{$token}/face-consent");
        $this->assertNoRouteFor('GET', "/album/{$token}/photo/{$photoId}");
        $this->assertFalse(Schema::hasTable('face_search_consents'));

        $this->getJson("/api/v1/album/{$token}/photos")
            ->assertOk()
            ->assertJsonMissingPath('data.face_search_consent_version');

        $this->get("/album/{$token}")
            ->assertOk()
            ->assertDontSee('face-api')
            ->assertDontSee('ใบหน้า');
    }

    public function test_app_no_longer_gets_an_album_link_endpoint(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $schedule->ensurePhotoToken();
        $customer = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'payment_type' => 'full',
        ]);

        $this->assertNoRouteFor('GET', "/api/v1/bookings/{$booking->booking_ref}/album");
    }

    /** Only the SPA's catch-all (or nothing at all) may answer a removed URL. */
    private function assertNoRouteFor(string $method, string $uri): void
    {
        try {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame('{any?}', $route->uri(), "{$method} {$uri} is still routed to {$route->uri()}");
    }

    public function test_opening_the_album_counts_the_visitor(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $token = $schedule->ensurePhotoToken();

        $this->assertSame(0, $schedule->photo_views_count);

        $this->withHeaders(['User-Agent' => 'first-visitor'])
            ->getJson("/api/v1/album/{$token}/photos")
            ->assertOk()
            ->assertJsonPath('data.views_count', 1);

        $this->assertSame(1, $schedule->fresh()->photo_views_count);
    }

    public function test_the_same_visitor_refreshing_does_not_inflate_the_count(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $token = $schedule->ensurePhotoToken();

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders(['User-Agent' => 'same-visitor'])
                ->getJson("/api/v1/album/{$token}/photos")
                ->assertOk();
        }

        $this->assertSame(1, $schedule->fresh()->photo_views_count);
    }

    public function test_a_second_visitor_adds_to_the_count(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $token = $schedule->ensurePhotoToken();

        $this->withHeaders(['User-Agent' => 'visitor-a'])->getJson("/api/v1/album/{$token}/photos")->assertOk();

        $this->withHeaders(['User-Agent' => 'visitor-b'])
            ->getJson("/api/v1/album/{$token}/photos")
            ->assertOk()
            ->assertJsonPath('data.views_count', 2);
    }

    public function test_downloading_photos_does_not_count_as_a_view(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $token = $schedule->ensurePhotoToken();
        $photoId = $schedule->photos()->first()->id;

        $this->get("/album/{$token}/download/{$photoId}")->assertOk();

        $this->assertSame(0, $schedule->fresh()->photo_views_count);
    }
}
