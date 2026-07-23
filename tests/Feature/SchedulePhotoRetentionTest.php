<?php

namespace Tests\Feature;

use App\Jobs\PurgeExpiredSchedulePhotosJob;
use App\Models\SchedulePhoto;
use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * รูปให้ลูกค้าโหลดเก็บไว้ {@see SchedulePhoto::RETENTION_DAYS} วันนับจากวันอัปโหลด
 * แล้วถูกลบทั้งแถว ความสัมพันธ์กับรอบ และไฟล์จริงบนดิสก์ — ไม่ทิ้งไฟล์กำพร้าไว้บน R2
 */
class SchedulePhotoRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('filesystems.disks.r2.bucket', null);
        Storage::fake('public');
    }

    private function makeSchedule(?Trip $trip = null): TripSchedule
    {
        $trip ??= Trip::create([
            'title' => 'Retention Trip',
            'slug' => 'retention-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->subDays(10)->toDateString(),
            'return_date' => now()->subDays(10)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'closed',
        ]);
    }

    /** สร้างรูปพร้อมไฟล์จริงบนดิสก์ และย้อนวันอัปโหลดตามต้องการ */
    private function makePhoto(TripSchedule $schedule, string $name, int $daysAgo): SchedulePhoto
    {
        $path = "schedules/{$schedule->id}/{$name}.jpg";
        $thumb = "schedules/{$schedule->id}/thumbs/{$name}.jpg";
        Storage::disk('public')->put($path, 'full');
        Storage::disk('public')->put($thumb, 'thumb');

        $photo = SchedulePhoto::create([
            'disk' => 'public',
            'path' => $path,
            'thumb_path' => $thumb,
            'url' => "http://example.test/{$name}.jpg",
        ]);
        $schedule->photos()->attach($photo->id, ['sort_order' => 1]);

        // created_at ถูกตั้งโดย timestamps จึงต้องเขียนทับตรง ๆ
        SchedulePhoto::where('id', $photo->id)->update([
            'created_at' => now()->subDays($daysAgo),
        ]);

        return $photo->fresh();
    }

    public function test_photos_older_than_the_retention_window_are_deleted_with_their_files(): void
    {
        $schedule = $this->makeSchedule();
        $old = $this->makePhoto($schedule, 'old', SchedulePhoto::RETENTION_DAYS + 1);

        (new PurgeExpiredSchedulePhotosJob)->handle();

        $this->assertDatabaseMissing('schedule_photos', ['id' => $old->id]);
        $this->assertSame(0, DB::table('schedule_photo')->where('photo_id', $old->id)->count());
        $this->assertSame(0, $schedule->photos()->count());

        // ไฟล์ถูกลบจริงบนดิสก์ (คิวเป็น sync ในเทสต์ งานกวาดไฟล์จึงรันทันที)
        Storage::disk('public')->assertMissing($old->path);
        Storage::disk('public')->assertMissing($old->thumb_path);
    }

    public function test_photos_inside_the_retention_window_are_kept(): void
    {
        $schedule = $this->makeSchedule();
        $fresh = $this->makePhoto($schedule, 'fresh', SchedulePhoto::RETENTION_DAYS - 1);

        (new PurgeExpiredSchedulePhotosJob)->handle();

        $this->assertDatabaseHas('schedule_photos', ['id' => $fresh->id]);
        $this->assertSame(1, $schedule->photos()->count());
        Storage::disk('public')->assertExists($fresh->path);
    }

    public function test_expired_photo_shared_with_another_round_is_removed_everywhere(): void
    {
        $roundA = $this->makeSchedule();
        $roundB = $this->makeSchedule($roundA->trip);

        $shared = $this->makePhoto($roundA, 'shared', SchedulePhoto::RETENTION_DAYS + 2);
        $roundB->photos()->attach($shared->id, ['sort_order' => 1]);

        (new PurgeExpiredSchedulePhotosJob)->handle();

        // อายุของไฟล์เป็นตัวตัดสิน ไม่ใช่จำนวนรอบที่ใช้ร่วมกัน
        $this->assertDatabaseMissing('schedule_photos', ['id' => $shared->id]);
        $this->assertSame(0, $roundA->photos()->count());
        $this->assertSame(0, $roundB->photos()->count());
        Storage::disk('public')->assertMissing($shared->path);
    }

    public function test_photo_payload_exposes_when_it_will_be_deleted(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->ensurePhotoToken();
        $photo = $this->makePhoto($schedule, 'soon', 1);

        $response = $this->getJson("/api/v1/album/{$schedule->photo_token}/photos")
            ->assertOk()
            ->assertJsonPath('data.retention_days', SchedulePhoto::RETENTION_DAYS);

        $expiresAt = $response->json('data.photos.0.expires_at');
        $this->assertNotNull($expiresAt);
        $this->assertSame(
            $photo->created_at->addDays(SchedulePhoto::RETENTION_DAYS)->toISOString(),
            $expiresAt,
        );
    }
}
