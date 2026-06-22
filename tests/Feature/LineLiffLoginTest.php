<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LineLiffLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        config(['services.line.liff_channel_id' => 'test-channel']);
    }

    /** Fake the LINE verify + profile endpoints for a given identity. */
    private function fakeLine(string $channelId = 'test-channel', array $profile = []): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/verify*' => Http::response([
                'client_id' => $channelId,
                'expires_in' => 2592000,
                'scope' => 'profile',
            ]),
            'api.line.me/v2/profile' => Http::response(array_merge([
                'userId' => 'Uline123',
                'displayName' => 'ลูกค้า LINE',
                'pictureUrl' => 'https://line.example/p.jpg',
            ], $profile)),
        ]);
    }

    public function test_first_login_creates_customer_and_returns_token(): void
    {
        $this->fakeLine();

        $res = $this->postJson('/api/v1/auth/line/liff', ['access_token' => 'valid-token']);

        $res->assertSuccessful()
            ->assertJsonPath('data.user.name', 'ลูกค้า LINE')
            ->assertJsonPath('data.user.social_provider', 'line')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'roles']]]);

        $user = User::where('social_provider', 'line')->where('social_id', 'Uline123')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('customer'));
        $this->assertNull($user->password);
    }

    public function test_repeat_login_reuses_the_same_account(): void
    {
        $this->fakeLine();

        $this->postJson('/api/v1/auth/line/liff', ['access_token' => 'valid-token'])->assertSuccessful();
        $this->postJson('/api/v1/auth/line/liff', ['access_token' => 'valid-token'])->assertSuccessful();

        $this->assertSame(1, User::where('social_provider', 'line')->where('social_id', 'Uline123')->count());
    }

    public function test_rejects_token_issued_for_a_different_channel(): void
    {
        $this->fakeLine(channelId: 'someone-elses-channel');

        $res = $this->postJson('/api/v1/auth/line/liff', ['access_token' => 'foreign-token']);

        $res->assertStatus(401);
        $this->assertSame(0, User::where('social_provider', 'line')->count());
    }

    public function test_rejects_when_line_cannot_verify_the_token(): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/verify*' => Http::response(['error' => 'invalid_request'], 400),
        ]);

        $res = $this->postJson('/api/v1/auth/line/liff', ['access_token' => 'bad-token']);

        $res->assertStatus(401);
    }

    public function test_requires_an_access_token(): void
    {
        $res = $this->postJson('/api/v1/auth/line/liff', []);

        $res->assertStatus(422)->assertJsonValidationErrors('access_token');
    }
}
