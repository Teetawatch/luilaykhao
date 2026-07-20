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
