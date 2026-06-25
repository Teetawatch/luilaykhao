<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_avatar_returns_a_resolvable_url(): void
    {
        Storage::fake(MediaDisk::name());
        $user = User::factory()->create(['avatar' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', [
                'avatar' => UploadedFile::fake()->image('me.jpg'),
            ])
            ->assertOk();

        // The stored value is a disk-relative path…
        $user->refresh();
        $this->assertStringStartsWith('avatars/', $user->avatar);
        Storage::disk(MediaDisk::name())->assertExists($user->avatar);

        // …and the URL returned to the app must point at the media disk (where
        // the file actually lives), not the app web root, so the image renders.
        $avatarUrl = $response->json('data.avatar_url');
        $this->assertNotNull($avatarUrl);
        $this->assertSame($user->avatar_url, $avatarUrl);
        $this->assertStringContainsString($user->avatar, $avatarUrl);
    }
}
