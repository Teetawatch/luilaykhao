<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * อัปโหลดสื่อของแอดมิน — เพดานขนาดไฟล์แยกตามชนิด (วิดีโอ 200MB, รูปภาพ 15MB)
 */
class AdminMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaDisk::name());
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_presign_refuses_an_oversize_video_before_a_byte_is_sent(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/media/presign', [
                'filename' => 'trip.mp4',
                'content_type' => 'video/mp4',
                'size' => 210 * 1024 * 1024,
            ])
            ->assertStatus(422);
    }

    public function test_presign_refuses_a_content_type_outside_the_allowlist(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/media/presign', [
                'filename' => 'payload.exe',
                'content_type' => 'application/x-msdownload',
                'size' => 1024,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content_type');
    }

    public function test_presign_reports_unsupported_on_a_disk_that_cannot_sign(): void
    {
        // The fake disk stands in for local dev's 'public' disk, which has no
        // presigning — the client is told to fall back to the plain POST.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/media/presign', [
                'filename' => 'trip.mp4',
                'content_type' => 'video/mp4',
                'size' => 10 * 1024 * 1024,
            ])
            ->assertOk()
            ->assertJsonPath('data.supported', false);
    }

    public function test_confirm_rejects_a_path_it_could_not_have_issued(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/media/confirm', ['path' => 'slips/secret.jpg'])
            ->assertStatus(422);
    }

    public function test_confirm_deletes_an_object_that_arrived_over_the_cap(): void
    {
        $path = 'media/1700000000_abc12345.mp4';
        Storage::disk(MediaDisk::name())->put($path, str_repeat('x', 16 * 1024 * 1024));

        // Presign trusted the client's declared size; the real object is checked here.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/media/confirm', ['path' => $path])
            ->assertOk();

        $imagePath = 'media/1700000000_abc12346.jpg';
        Storage::disk(MediaDisk::name())->put($imagePath, str_repeat('x', 16 * 1024 * 1024));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/media/confirm', ['path' => $imagePath])
            ->assertStatus(422);

        Storage::disk(MediaDisk::name())->assertMissing($imagePath);
    }

    public function test_video_up_to_200mb_is_accepted(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/upload-image', [
                'file' => UploadedFile::fake()->create('trip.mp4', 150 * 1024, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_video_over_200mb_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/upload-image', [
                'file' => UploadedFile::fake()->create('trip.mp4', 210 * 1024, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_image_stays_capped_at_15mb(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/upload-image', [
                'file' => UploadedFile::fake()->create('cover.jpg', 20 * 1024, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/upload-image', [
                'file' => UploadedFile::fake()->create('cover.jpg', 10 * 1024, 'image/jpeg'),
            ])
            ->assertOk();
    }
}
