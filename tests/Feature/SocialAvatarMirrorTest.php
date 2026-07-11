<?php

namespace Tests\Feature;

use App\Jobs\MirrorSocialAvatarJob;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SocialAvatarMirrorTest extends TestCase
{
    use RefreshDatabase;

    private const PICTURE_URL = 'https://profile.line-scdn.net/0hAbcDefGhIjkL';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaDisk::name());
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        config(['services.line.liff_channel_id' => 'test-channel']);
    }

    public function test_line_login_mirrors_the_profile_picture_onto_our_storage(): void
    {
        $this->fakeLineLogin();

        $response = $this->postJson('/api/v1/auth/line/liff', [
            'access_token' => 'valid-token',
        ]);

        $response->assertOk();

        $user = User::where('social_provider', 'line')->where('social_id', 'U-line-123')->firstOrFail();

        $expectedPath = 'avatars/social/'.sha1(self::PICTURE_URL).'.jpg';

        $this->assertSame($expectedPath, $user->avatar);
        Storage::disk(MediaDisk::name())->assertExists($expectedPath);

        // avatar_url must now serve our stable copy, not the LINE CDN URL.
        $this->assertStringNotContainsString('line-scdn.net', $user->avatar_url);
    }

    public function test_returning_user_with_a_dead_avatar_gets_it_refreshed(): void
    {
        $user = User::factory()->create([
            'social_provider' => 'line',
            'social_id' => 'U-line-123',
            // A stale LINE URL that has since 404'd.
            'avatar' => 'https://profile.line-scdn.net/0hDeadUrl',
        ]);

        $this->fakeLineLogin();

        $this->postJson('/api/v1/auth/line/liff', ['access_token' => 'valid-token'])->assertOk();

        $user->refresh();

        $this->assertSame('avatars/social/'.sha1(self::PICTURE_URL).'.jpg', $user->avatar);
        Storage::disk(MediaDisk::name())->assertExists($user->avatar);
    }

    public function test_job_clears_a_dead_avatar_when_the_source_is_gone(): void
    {
        $user = User::factory()->create([
            'avatar' => self::PICTURE_URL,
        ]);

        Http::fake([self::PICTURE_URL => Http::response('', 404)]);

        (new MirrorSocialAvatarJob($user->id, self::PICTURE_URL))->handle();

        $user->refresh();

        // Fell back to null so avatar_url yields the generated placeholder.
        $this->assertNull($user->avatar);
    }

    public function test_job_ignores_a_non_image_response(): void
    {
        $user = User::factory()->create(['avatar' => 'avatars/social/existing.jpg']);

        Http::fake([self::PICTURE_URL => Http::response('<html>error</html>', 200, ['Content-Type' => 'text/html'])]);

        (new MirrorSocialAvatarJob($user->id, self::PICTURE_URL))->handle();

        $user->refresh();

        // Left the existing avatar untouched rather than storing an HTML blob.
        $this->assertSame('avatars/social/existing.jpg', $user->avatar);
    }

    private function fakeLineLogin(): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/verify*' => Http::response(['client_id' => 'test-channel'], 200),
            'api.line.me/v2/profile' => Http::response([
                'userId' => 'U-line-123',
                'displayName' => 'สมชาย ใจดี',
                'pictureUrl' => self::PICTURE_URL,
            ], 200),
            'profile.line-scdn.net/*' => Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }
}
