<?php

namespace Tests\Feature;

use App\Models\FaceSearchConsent;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "ค้นหารูปของฉันด้วยใบหน้า" บนหน้าอัลบั้มสาธารณะ
 *
 * ตัวการเทียบใบหน้าอยู่ในเบราว์เซอร์ ฝั่งเซิร์ฟเวอร์จึงมีแค่ 3 อย่างให้ทดสอบ:
 * บันทึก/ถอนความยินยอม PDPA, ส่งรูปแบบ same-origin ให้สแกนได้, และดาวน์โหลด
 * เฉพาะรูปที่ค้นเจอเป็น ZIP
 */
class AlbumFaceSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('filesystems.disks.r2.bucket', null);
        Storage::fake('public');
    }

    private function makeScheduleWithPhotos(int $count = 3): TripSchedule
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $trip = Trip::create([
            'title' => 'Face Trip', 'slug' => 'face-trip-'.Str::random(6), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 8, 'price_per_person' => 1000, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => '2026-08-07',
            'return_date' => '2026-08-08',
            'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);

        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = UploadedFile::fake()->image("f{$i}.jpg", 600, 400);
        }
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/photos", ['files' => $files])
            ->assertCreated();

        return $schedule;
    }

    public function test_album_payload_exposes_the_current_consent_version(): void
    {
        $token = $this->makeScheduleWithPhotos(1)->ensurePhotoToken();

        $this->getJson("/api/v1/album/{$token}/photos")
            ->assertOk()
            ->assertJsonPath('data.face_search_consent_version', FaceSearchConsent::CURRENT_VERSION);
    }

    public function test_consent_is_recorded_without_any_biometric_payload(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $token = $schedule->ensurePhotoToken();
        $subject = (string) Str::uuid();

        $this->postJson("/api/v1/album/{$token}/face-consent", [
            'subject_key' => $subject,
            'consent_version' => FaceSearchConsent::CURRENT_VERSION,
            'accepted' => true,
        ])->assertOk();

        $consent = FaceSearchConsent::where('photo_token', $token)->firstOrFail();
        $this->assertSame($subject, $consent->subject_key);
        $this->assertSame($schedule->id, $consent->trip_schedule_id);
        $this->assertNotNull($consent->consented_at);
        $this->assertTrue($consent->isActive());
    }

    public function test_consenting_again_from_the_same_browser_updates_one_row(): void
    {
        $token = $this->makeScheduleWithPhotos(1)->ensurePhotoToken();
        $subject = (string) Str::uuid();
        $payload = [
            'subject_key' => $subject,
            'consent_version' => FaceSearchConsent::CURRENT_VERSION,
            'accepted' => true,
        ];

        $this->postJson("/api/v1/album/{$token}/face-consent", $payload)->assertOk();
        $this->postJson("/api/v1/album/{$token}/face-consent", $payload)->assertOk();

        $this->assertSame(1, FaceSearchConsent::where('photo_token', $token)->count());
    }

    public function test_an_outdated_consent_version_is_rejected(): void
    {
        $token = $this->makeScheduleWithPhotos(1)->ensurePhotoToken();

        $this->postJson("/api/v1/album/{$token}/face-consent", [
            'subject_key' => (string) Str::uuid(),
            'consent_version' => '2000-01-01',
            'accepted' => true,
        ])->assertStatus(409);

        $this->assertSame(0, FaceSearchConsent::count());
    }

    public function test_consent_requires_an_explicit_acceptance(): void
    {
        $token = $this->makeScheduleWithPhotos(1)->ensurePhotoToken();

        $this->postJson("/api/v1/album/{$token}/face-consent", [
            'subject_key' => (string) Str::uuid(),
            'consent_version' => FaceSearchConsent::CURRENT_VERSION,
            'accepted' => false,
        ])->assertStatus(422);

        $this->assertSame(0, FaceSearchConsent::count());
    }

    public function test_customer_can_withdraw_consent(): void
    {
        $token = $this->makeScheduleWithPhotos(1)->ensurePhotoToken();
        $subject = (string) Str::uuid();

        $this->postJson("/api/v1/album/{$token}/face-consent", [
            'subject_key' => $subject,
            'consent_version' => FaceSearchConsent::CURRENT_VERSION,
            'accepted' => true,
        ])->assertOk();

        $this->deleteJson("/api/v1/album/{$token}/face-consent", ['subject_key' => $subject])
            ->assertOk();

        $consent = FaceSearchConsent::where('photo_token', $token)->firstOrFail();
        $this->assertNotNull($consent->revoked_at);
        $this->assertFalse($consent->isActive());
    }

    public function test_consent_endpoints_reject_an_unknown_album(): void
    {
        $this->postJson('/api/v1/album/nope/face-consent', [
            'subject_key' => (string) Str::uuid(),
            'consent_version' => FaceSearchConsent::CURRENT_VERSION,
            'accepted' => true,
        ])->assertNotFound();

        $this->deleteJson('/api/v1/album/nope/face-consent', [
            'subject_key' => (string) Str::uuid(),
        ])->assertNotFound();
    }

    public function test_photos_stream_inline_from_the_same_origin_for_scanning(): void
    {
        $schedule = $this->makeScheduleWithPhotos(1);
        $token = $schedule->ensurePhotoToken();
        $photoId = $schedule->photos()->first()->id;

        $response = $this->get("/album/{$token}/photo/{$photoId}");

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_a_photo_from_another_album_is_not_reachable(): void
    {
        $mine = $this->makeScheduleWithPhotos(1);
        $other = $this->makeScheduleWithPhotos(1);

        $this->get("/album/{$mine->ensurePhotoToken()}/photo/{$other->photos()->first()->id}")
            ->assertNotFound();
    }

    public function test_only_the_matched_photos_end_up_in_the_zip(): void
    {
        $schedule = $this->makeScheduleWithPhotos(3);
        $token = $schedule->ensurePhotoToken();
        $ids = $schedule->photos()->pluck('schedule_photos.id')->take(2)->implode(',');

        $response = $this->get("/album/{$token}/download?ids={$ids}");
        $response->assertOk();

        $this->assertSame(2, $this->zipEntryCount($response->streamedContent()));
    }

    public function test_ids_from_another_album_are_ignored(): void
    {
        $mine = $this->makeScheduleWithPhotos(2);
        $other = $this->makeScheduleWithPhotos(1);
        $token = $mine->ensurePhotoToken();

        // เลขรูปของอัลบั้มอื่นถูกตัดทิ้ง — เหลือแต่รูปของอัลบั้มนี้
        $wanted = $mine->photos()->first()->id.','.$other->photos()->first()->id;
        $response = $this->get("/album/{$token}/download?ids={$wanted}");
        $response->assertOk();
        $this->assertSame(1, $this->zipEntryCount($response->streamedContent()));

        // ขอเฉพาะรูปของอัลบั้มอื่นล้วน ๆ = ไม่มีอะไรให้ดาวน์โหลด
        $this->get("/album/{$token}/download?ids={$other->photos()->first()->id}")
            ->assertNotFound();
    }

    private function zipEntryCount(string $content): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'facezip');
        file_put_contents($tmp, $content);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $count = $zip->numFiles;
        $zip->close();
        @unlink($tmp);

        return $count;
    }
}
