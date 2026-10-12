<?php

namespace Tests\Feature;

use App\Models\SchedulePhoto;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * สตาฟอัปรูปเข้าอัลบั้มรอบจากแอป — หลายรูปต่อครั้ง, ลบได้แค่รูปตัวเอง,
 * และอัปได้ต่อหลังถูกปลดจากรอบตามกรอบวัน
 */
class StaffSchedulePhotoTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // ไม่มี bucket R2 → MediaDisk ตกไปที่ดิสก์ public ซึ่งปลอมไว้
        config()->set('filesystems.disks.r2.bucket', null);
        Storage::fake('public');

        Role::findOrCreate('staff');
        Role::findOrCreate('admin');

        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'staff-photo-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'hard',
            'duration_days' => 3,
            'max_participants' => 15,
            'price_per_person' => 4500,
            'status' => 'active',
        ]);

        $this->schedule = $this->makeSchedule($trip, now('Asia/Bangkok')->toDateString(), now('Asia/Bangkok')->addDays(2)->toDateString());

        $this->staff = User::factory()->create(['name' => 'สตาฟบอย']);
        $this->staff->assignRole('staff');
        $this->schedule->staff()->attach($this->staff->id);
    }

    private function makeSchedule(Trip $trip, string $departure, ?string $return): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $return,
            'total_seats' => 15,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function url(?TripSchedule $schedule = null, string $suffix = ''): string
    {
        return '/api/v1/staff/schedules/'.($schedule ?? $this->schedule)->id.'/photos'.$suffix;
    }

    private function upload(int $count = 3, ?User $as = null)
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[] = UploadedFile::fake()->image("p{$i}.jpg", 1200, 900);
        }

        return $this->actingAs($as ?? $this->staff)->postJson($this->url(), ['files' => $files]);
    }

    public function test_staff_uploads_several_photos_into_the_round_album(): void
    {
        $response = $this->upload(3);

        $response->assertCreated()
            ->assertJsonCount(3, 'data.uploaded')
            ->assertJsonPath('data.photos_count', 3)
            ->assertJsonPath('data.uploaded.0.uploaded_by', $this->staff->id)
            ->assertJsonPath('data.uploaded.0.uploader_name', 'สตาฟบอย');

        $photos = $this->schedule->photos()->get();
        $this->assertCount(3, $photos);
        $this->assertSame([1, 2, 3], $photos->pluck('pivot.sort_order')->all());

        foreach ($photos as $photo) {
            $this->assertSame($this->staff->id, $photo->uploaded_by);
            $this->assertStringStartsWith("schedules/{$this->schedule->id}/", $photo->path);
            Storage::disk('public')->assertExists($photo->path);
        }
    }

    public function test_uploads_append_after_existing_photos(): void
    {
        $this->upload(2)->assertCreated();
        $this->upload(2)->assertCreated();

        $this->assertSame([1, 2, 3, 4], $this->schedule->photos()->get()->pluck('pivot.sort_order')->all());
    }

    public function test_customer_album_payload_does_not_reveal_who_uploaded(): void
    {
        $this->upload(1)->assertCreated();

        $this->schedule->update(['photo_token' => 'tok-'.uniqid()]);
        $response = $this->getJson('/api/v1/album/'.$this->schedule->fresh()->photo_token.'/photos');

        $response->assertOk();
        $this->assertArrayNotHasKey('uploaded_by', $response->json('data.photos.0'));
        $this->assertArrayNotHasKey('uploader_name', $response->json('data.photos.0'));
    }

    public function test_index_lists_round_photos_with_upload_window(): void
    {
        $this->upload(2)->assertCreated();

        $this->actingAs($this->staff)->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'data.photos')
            ->assertJsonPath('data.can_upload', true)
            ->assertJsonPath('data.upload_until', now('Asia/Bangkok')->addDays(5)->toDateString())
            ->assertJsonPath('data.retention_days', SchedulePhoto::RETENTION_DAYS);
    }

    public function test_staff_of_another_round_cannot_upload(): void
    {
        $other = User::factory()->create();
        $other->assignRole('staff');

        $this->upload(1, $other)->assertForbidden();
        $this->assertSame(0, SchedulePhoto::count());
    }

    public function test_non_staff_customer_cannot_upload(): void
    {
        $customer = User::factory()->create();
        $this->schedule->staff()->attach($customer->id);

        $this->upload(1, $customer)->assertForbidden();
    }

    public function test_upload_is_closed_before_departure(): void
    {
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(5)->toDateString(),
        ]);

        $this->upload(1)->assertStatus(422);
        $this->actingAs($this->staff)->getJson($this->url())->assertJsonPath('data.can_upload', false);
    }

    public function test_released_staff_can_still_upload_within_grace_days(): void
    {
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->subDays(4)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(2)->toDateString(),
        ]);
        $this->schedule->staff()->updateExistingPivot($this->staff->id, ['released_at' => now()]);

        $this->upload(2)->assertCreated();

        $this->actingAs($this->staff)->getJson('/api/v1/staff/schedules/my')
            ->assertOk()
            ->assertJsonCount(0, 'data.schedules')
            ->assertJsonPath('data.photo_rounds.0.id', $this->schedule->id)
            ->assertJsonPath('data.photo_rounds.0.photos_count', 2);
    }

    public function test_upload_closes_after_grace_days(): void
    {
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->subDays(8)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(4)->toDateString(),
        ]);
        $this->schedule->staff()->updateExistingPivot($this->staff->id, ['released_at' => now()]);

        $this->upload(1)->assertStatus(422);

        $this->actingAs($this->staff)->getJson('/api/v1/staff/schedules/my')
            ->assertJsonCount(0, 'data.photo_rounds');
    }

    public function test_cancelled_round_never_takes_uploads(): void
    {
        $this->schedule->update(['status' => 'cancelled']);

        $this->upload(1)->assertStatus(422);
    }

    public function test_active_round_reports_photo_count(): void
    {
        $this->upload(2)->assertCreated();

        $this->actingAs($this->staff)->getJson('/api/v1/staff/schedules/my')
            ->assertOk()
            ->assertJsonPath('data.schedules.0.photos_count', 2)
            ->assertJsonPath('data.schedules.0.photo_upload_open', true);
    }

    public function test_staff_deletes_own_photo_and_file_is_removed(): void
    {
        $this->upload(1)->assertCreated();
        $photo = SchedulePhoto::first();

        $this->actingAs($this->staff)->deleteJson($this->url(suffix: "/{$photo->id}"))
            ->assertOk()
            ->assertJsonPath('data.photos_count', 0);

        $this->assertNull(SchedulePhoto::find($photo->id));
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_staff_cannot_delete_someone_elses_photo(): void
    {
        $colleague = User::factory()->create();
        $colleague->assignRole('staff');
        $this->schedule->staff()->attach($colleague->id);

        $this->upload(1, $colleague)->assertCreated();
        $photo = SchedulePhoto::first();

        $this->actingAs($this->staff)->deleteJson($this->url(suffix: "/{$photo->id}"))
            ->assertForbidden();

        $this->assertNotNull(SchedulePhoto::find($photo->id));
    }

    public function test_admin_upload_records_uploader_and_lists_name(): void
    {
        $admin = User::factory()->create(['name' => 'แอดมิน']);
        $admin->assignRole('admin');

        $this->actingAs($admin)->postJson("/api/v1/admin/schedules/{$this->schedule->id}/photos", [
            'files' => [UploadedFile::fake()->image('a.jpg', 800, 600)],
        ])->assertCreated();

        $this->assertSame($admin->id, SchedulePhoto::first()->uploaded_by);

        $this->actingAs($admin)->getJson("/api/v1/admin/schedules/{$this->schedule->id}/photos")
            ->assertOk()
            ->assertJsonPath('data.0.uploader_name', 'แอดมิน');

        // สตาฟลบรูปของแอดมินไม่ได้
        $this->actingAs($this->staff)
            ->deleteJson($this->url(suffix: '/'.SchedulePhoto::first()->id))
            ->assertForbidden();
    }
}
