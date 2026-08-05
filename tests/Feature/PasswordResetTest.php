<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    private function customer(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => 'somchai@example.com',
            'password' => Hash::make('old-password'),
        ], $attributes));

        $user->assignRole('customer');

        return $user;
    }

    public function test_forgot_password_mails_a_reset_link(): void
    {
        Mail::fake();
        $user = $this->customer();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'somchai@example.com',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && str_contains($mail->resetUrl, '/reset-password?')
                && str_contains($mail->resetUrl, 'token=');
        });

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_matches_the_address_case_insensitively(): void
    {
        Mail::fake();
        $this->customer();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => '  SomChai@Example.com ',
        ])->assertOk();

        Mail::assertQueued(PasswordResetMail::class);
    }

    /**
     * The whole point of the generic response: an attacker must not be able to
     * ask this endpoint which addresses have accounts.
     */
    public function test_unknown_address_is_answered_identically_and_mails_nothing(): void
    {
        Mail::fake();
        $this->customer();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'somchai@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));

        Mail::assertQueuedCount(1);
    }

    public function test_reset_sets_the_new_password_and_lets_the_customer_log_in(): void
    {
        Mail::fake();
        $user = $this->customer();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'brand-new-pass',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'old-password',
        ])->assertStatus(401);
    }

    public function test_reset_revokes_every_existing_session(): void
    {
        Mail::fake();
        $user = $this->customer();
        $user->createToken('auth-token');
        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_marks_the_address_verified(): void
    {
        Mail::fake();
        $user = $this->customer(['email_verified_at' => null]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        Mail::fake();
        $user = $this->customer();
        $token = Password::createToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
    }

    public function test_a_forged_token_is_rejected(): void
    {
        Mail::fake();
        $user = $this->customer();
        Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-the-real-token',
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    /**
     * A token issued for one account must not be spendable on another — this is
     * the difference between "forgot password" and "take over any account".
     */
    public function test_a_token_issued_for_another_account_is_rejected(): void
    {
        Mail::fake();
        $victim = $this->customer();
        $attacker = $this->customer(['email' => 'attacker@example.com']);
        $attackerToken = Password::createToken($attacker);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $attackerToken,
            'email' => $victim->email,
            'password' => 'pwned-password',
            'password_confirmation' => 'pwned-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $victim->fresh()->password));
    }

    public function test_reset_requires_a_confirmed_password_of_at_least_eight_characters(): void
    {
        Mail::fake();
        $user = $this->customer();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'long-enough-password',
            'password_confirmation' => 'different-password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * Social accounts have password = null. Resetting is how they add one, so
     * the broker must not choke on the missing hash.
     */
    public function test_a_social_only_account_can_set_its_first_password(): void
    {
        Mail::fake();
        $user = $this->customer(['password' => null, 'social_provider' => 'google', 'social_id' => 'g-1']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'my-first-password',
            'password_confirmation' => 'my-first-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('my-first-password', $user->fresh()->password));
    }
}
