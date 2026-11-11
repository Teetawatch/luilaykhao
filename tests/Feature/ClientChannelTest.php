<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\ClientChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * เว็บ แอป และ LIFF ใช้ API ชุดเดียวกัน — token ต้องแยกตามช่องทาง (token เว็บ
 * หมดอายุเมื่อไม่ใช้), ใบจองต้องรู้ว่ามาจากไหน และเซิร์ฟเวอร์ต้องรู้เวอร์ชันแอป
 */
class ClientChannelTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'traveller@example.com',
            'password' => Hash::make('secret-pass'),
        ]);
    }

    private function login(array $headers = []): PersonalAccessToken
    {
        $token = $this->withHeaders($headers)->postJson('/api/v1/auth/login', [
            'email' => 'traveller@example.com',
            'password' => 'secret-pass',
        ])->assertOk()->json('data.token');

        return PersonalAccessToken::findToken($token);
    }

    public function test_web_login_gets_an_expiring_web_token(): void
    {
        $this->user();

        $token = $this->login(['X-Client' => 'web']);

        $this->assertSame('web', $token->name);
        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays(ClientChannel::webTokenTtlDays())->timestamp,
            $token->expires_at->timestamp,
            60,
        );
    }

    public function test_app_login_gets_a_non_expiring_app_token(): void
    {
        $this->user();

        $this->assertSame('app', $this->login(['X-Client' => 'app'])->name);
        $this->assertNull(PersonalAccessToken::latest('id')->first()->expires_at);

        // แอปที่ส่งแค่ X-Platform ก็นับเป็นแอป
        $this->assertSame('app', $this->login(['X-Platform' => 'android'])->name);
    }

    public function test_login_without_client_headers_keeps_the_legacy_token(): void
    {
        $this->user();

        $token = $this->login();

        // แอปรุ่นเก่าที่ไม่ส่ง header ต้องไม่ได้ token ที่หมดอายุแล้วหลุดออกจากระบบ
        $this->assertSame(ClientChannel::LEGACY_TOKEN, $token->name);
        $this->assertNull($token->expires_at);
    }

    public function test_active_web_token_slides_forward(): void
    {
        $user = $this->user();
        $plain = ClientChannel::issueToken($user, ClientChannel::WEB);
        $token = PersonalAccessToken::findToken($plain);
        $token->forceFill(['expires_at' => now()->addDays(3)])->save();

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertTrue($token->fresh()->expires_at->greaterThan(now()->addDays(ClientChannel::webTokenTtlDays() - 1)));
    }

    public function test_token_last_used_at_is_stamped_at_most_hourly(): void
    {
        $user = $this->user();
        $plain = ClientChannel::issueToken($user, ClientChannel::APP);
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNull($token->last_used_at);

        $hit = function () use ($plain) {
            // guard ถือ token ตัวเดิมไว้ข้าม request ในเทสต์ — ลืมทิ้งให้อ่านจากฐานข้อมูลใหม่
            $this->app['auth']->forgetGuards();
            $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();
        };

        $hit();
        $this->assertEqualsWithDelta(now()->timestamp, $token->fresh()->last_used_at->timestamp, 5);

        // ใช้ไปเมื่อ 10 นาทีก่อน — ไม่ต้องเขียนซ้ำ (Sanctum เดิมเขียนทุก request)
        $recent = now()->subMinutes(10)->startOfSecond();
        $token->forceFill(['last_used_at' => $recent])->save();
        DB::enableQueryLog();
        $hit();
        $tokenWrites = array_filter(
            DB::getQueryLog(),
            fn ($q) => str_starts_with($q['query'], 'update "personal_access_tokens"'),
        );
        DB::disableQueryLog();
        $this->assertSame([], $tokenWrites);
        $this->assertSame($recent->timestamp, $token->fresh()->last_used_at->timestamp);

        // เกินชั่วโมง — เขียนใหม่
        $token->forceFill(['last_used_at' => now()->subHours(2)])->save();
        $hit();
        $this->assertEqualsWithDelta(now()->timestamp, $token->fresh()->last_used_at->timestamp, 5);
    }

    public function test_expired_web_token_is_rejected(): void
    {
        $user = $this->user();
        $plain = ClientChannel::issueToken($user, ClientChannel::WEB);
        PersonalAccessToken::findToken($plain)->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_app_token_is_never_given_an_expiry(): void
    {
        $user = $this->user();
        $plain = ClientChannel::issueToken($user, ClientChannel::APP);

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($plain)->expires_at);
    }

    public function test_app_version_and_platform_are_recorded(): void
    {
        $user = $this->user();
        $plain = ClientChannel::issueToken($user, ClientChannel::APP);

        $this->withToken($plain)
            ->withHeaders(['X-Client' => 'app', 'X-Platform' => 'ios', 'X-App-Version' => '1.8.2+140'])
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $user->refresh();
        $this->assertSame('1.8.2+140', $user->app_version);
        $this->assertSame('ios', $user->app_platform);
        $this->assertNotNull($user->app_seen_at);
    }

    public function test_garbage_app_version_is_ignored(): void
    {
        $user = $this->user();
        $plain = ClientChannel::issueToken($user, ClientChannel::APP);

        $this->withToken($plain)
            ->withHeaders(['X-Platform' => 'ios', 'X-App-Version' => '<script>'])
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertNull($user->fresh()->app_version);
    }

    public function test_web_requests_never_touch_app_version(): void
    {
        $user = $this->user();
        $user->forceFill(['app_version' => '1.0.0', 'app_platform' => 'android', 'app_seen_at' => now()->subDays(3)])->save();
        $plain = ClientChannel::issueToken($user, ClientChannel::WEB);

        $this->withToken($plain)->withHeaders(['X-Client' => 'web'])->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame('1.0.0', $user->fresh()->app_version);
    }

    // ── ช่องทางของใบจอง ─────────────────────────────────────────────────

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Channel Trip',
            'slug' => 'channel-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function book(string $plainToken, array $headers = [], array $extra = []): Booking
    {
        Mail::fake();
        // ทุกใบจองเป็น request ใหม่จาก client คนละตัว — ไม่ให้ header/ผู้ใช้ที่ guard
        // จำไว้จาก request ก่อนหน้าในเทสต์เดียวกันติดมาด้วย
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $ref = $this->withToken($plainToken)->withHeaders($headers)->postJson('/api/v1/bookings', [
            'schedule_id' => $this->schedule()->id,
            'passengers' => [[
                'title' => 'นาย',
                'name' => 'ผู้เดินทาง หนึ่ง',
                'nickname' => 'หนึ่ง',
                'id_card' => '1234567890121',
                'phone' => '0812345678',
                'blood_group' => 'O',
                'halal_food' => false,
                'emergency_contact' => 'แม่',
                'emergency_phone' => '0898765432',
            ]],
            ...$extra,
        ])->assertCreated()->json('data.booking_ref');

        return Booking::where('booking_ref', $ref)->firstOrFail();
    }

    public function test_booking_source_comes_from_the_token(): void
    {
        $user = $this->user();

        $booking = $this->book(ClientChannel::issueToken($user, ClientChannel::APP));
        $this->assertSame('app', $booking->source);

        // token บอกช่องทางชัดแล้ว header ที่ขัดกันแต่งทับไม่ได้
        $booking = $this->book(ClientChannel::issueToken($user, ClientChannel::LIFF), ['X-Client' => 'web']);
        $this->assertSame('liff', $booking->source);
    }

    public function test_legacy_token_falls_back_to_client_header(): void
    {
        $user = $this->user();
        $legacy = $user->createToken(ClientChannel::LEGACY_TOKEN)->plainTextToken;

        $this->assertSame('web', $this->book($legacy, ['X-Client' => 'web'])->source);
        $this->assertNull($this->book($legacy)->source);
    }

    public function test_admin_skip_payment_booking_is_marked_admin(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = $this->user();
        $admin->assignRole('admin');

        $booking = $this->book(ClientChannel::issueToken($admin, ClientChannel::WEB), ['X-Client' => 'web'], ['skip_payment' => true]);

        $this->assertSame('admin', $booking->source);
    }

    public function test_admin_can_filter_bookings_by_source(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = $this->user();
        $admin->assignRole('admin');

        $web = $this->book(ClientChannel::issueToken($admin, ClientChannel::WEB));
        $app = $this->book(ClientChannel::issueToken($admin, ClientChannel::APP));
        $unknown = $this->book($admin->createToken(ClientChannel::LEGACY_TOKEN)->plainTextToken);

        $this->assertSame([$app->booking_ref], $this->adminRefs($admin, 'app'));
        $this->assertSame([$web->booking_ref], $this->adminRefs($admin, 'web'));
        $this->assertSame([$unknown->booking_ref], $this->adminRefs($admin, 'unknown'));
    }

    private function adminRefs(User $admin, string $source): array
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/bookings?source='.$source)
            ->assertOk()
            ->json('data'))
            ->pluck('booking_ref')
            ->all();
    }

    // ── ยกเลิก token รุ่นเก่า ────────────────────────────────────────────

    public function test_revoke_legacy_tokens_is_a_dry_run_without_force(): void
    {
        $user = $this->user();
        $user->createToken(ClientChannel::LEGACY_TOKEN);

        $this->artisan('auth:revoke-legacy-tokens')->assertSuccessful();

        $this->assertSame(1, PersonalAccessToken::where('name', ClientChannel::LEGACY_TOKEN)->count());
    }

    public function test_revoke_legacy_tokens_only_removes_old_auth_tokens(): void
    {
        $user = $this->user();
        $legacy = $user->createToken(ClientChannel::LEGACY_TOKEN)->plainTextToken;
        $web = ClientChannel::issueToken($user, ClientChannel::WEB);
        $app = ClientChannel::issueToken($user, ClientChannel::APP);
        $driver = $user->createToken(ClientChannel::DRIVER_TOKEN)->plainTextToken;

        $this->artisan('auth:revoke-legacy-tokens', ['--force' => true])->assertSuccessful();

        $this->assertNull(PersonalAccessToken::findToken($legacy));
        foreach ([$web, $app, $driver] as $plain) {
            $this->assertNotNull(PersonalAccessToken::findToken($plain));
        }

        // คนที่ถือ token เก่าได้ 401 — แอป/เว็บพาไปล็อกอินใหม่เอง
        $this->app['auth']->forgetGuards();
        $this->withToken($legacy)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_revoke_legacy_tokens_keeps_tokens_issued_after_the_cutoff(): void
    {
        $user = $this->user();
        $old = $user->createToken(ClientChannel::LEGACY_TOKEN);
        $old->accessToken->forceFill(['created_at' => now()->subDays(2)])->save();
        $fresh = $user->createToken(ClientChannel::LEGACY_TOKEN)->plainTextToken;

        $this->artisan('auth:revoke-legacy-tokens', [
            '--force' => true,
            '--before' => now()->subDay()->toDateTimeString(),
        ])->assertSuccessful();

        $this->assertNull(PersonalAccessToken::find($old->accessToken->id));
        $this->assertNotNull(PersonalAccessToken::findToken($fresh));
    }

    // ── rate limit ───────────────────────────────────────────────────────

    public function test_login_limit_is_per_account_not_per_shared_ip(): void
    {
        $this->user();

        foreach (range(1, 10) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => 'traveller@example.com', 'password' => 'wrong'])
                ->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'traveller@example.com', 'password' => 'wrong'])
            ->assertStatus(429);

        // อีกคนที่ใช้เน็ตเดียวกันยังเข้าสู่ระบบได้
        User::factory()->create(['email' => 'friend@example.com', 'password' => Hash::make('friend-pass')]);
        $this->postJson('/api/v1/auth/login', ['email' => 'friend@example.com', 'password' => 'friend-pass'])
            ->assertOk();
    }

    public function test_public_trip_list_caps_per_page(): void
    {
        $this->getJson('/api/v1/trips?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }
}
