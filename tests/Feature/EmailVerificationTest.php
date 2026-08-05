<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationMail;
use App\Mail\WelcomeRegistrationMail;
use App\Models\User;
use App\Support\AccountLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    private function unverified(): User
    {
        $user = User::factory()->create([
            'email' => 'somchai@example.com',
            'email_verified_at' => null,
        ]);
        $user->assignRole('customer');

        return $user;
    }

    public function test_registering_sends_one_email_carrying_the_verification_link(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'สมชาย ใจดี',
            'title' => 'นาย',
            'email' => 'new@gmail.com',
            'phone' => '0812345678',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertCreated();

        Mail::assertQueuedCount(1);
        Mail::assertQueued(WelcomeRegistrationMail::class, function (WelcomeRegistrationMail $mail) {
            return $mail->verifyUrl !== null
                && str_contains($mail->verifyUrl, '/verify-email/');
        });

        $this->assertNull(User::where('email', 'new@gmail.com')->first()->email_verified_at);
    }

    public function test_me_reports_whether_the_address_is_verified(): void
    {
        $user = $this->unverified();

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);

        $user->markEmailAsVerified();

        $this->actingAs($user->fresh())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_clicking_a_valid_link_verifies_the_address(): void
    {
        $user = $this->unverified();

        $this->get(AccountLinks::verifyEmail($user))
            ->assertRedirectContains('/profile?verified=success');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_already_verified_link_says_so_instead_of_failing(): void
    {
        $user = $this->unverified();
        $url = AccountLinks::verifyEmail($user);

        $this->get($url)->assertRedirectContains('verified=success');
        $this->get($url)->assertRedirectContains('verified=already');
    }

    public function test_a_tampered_link_does_not_verify_anyone(): void
    {
        $user = $this->unverified();
        $other = User::factory()->create(['email_verified_at' => null]);

        // Same signature, different account id — the classic forged-URL attempt.
        $url = str_replace(
            '/verify-email/'.$user->id.'/',
            '/verify-email/'.$other->id.'/',
            AccountLinks::verifyEmail($user),
        );

        $this->get($url)->assertRedirectContains('verified=expired');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_an_expired_link_lands_on_a_page_that_can_resend(): void
    {
        $user = $this->unverified();
        $url = AccountLinks::verifyEmail($user);

        $this->travel(AccountLinks::VERIFY_TTL_HOURS + 1)->hours();

        $this->get($url)->assertRedirectContains('verified=expired');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    /**
     * The link is pinned to the address it was mailed to, so it dies the moment
     * that address changes — otherwise a link sent to an old inbox would keep
     * verifying an address its owner no longer controls.
     */
    public function test_changing_the_address_invalidates_links_already_sent(): void
    {
        $user = $this->unverified();
        $url = AccountLinks::verifyEmail($user);

        $user->forceFill(['email' => 'moved@example.com'])->save();

        $this->get($url)->assertRedirectContains('verified=invalid');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_mails_a_fresh_link(): void
    {
        Mail::fake();
        $user = $this->unverified();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/resend-verification')
            ->assertOk();

        Mail::assertQueued(EmailVerificationMail::class, function (EmailVerificationMail $mail) use ($user) {
            return $mail->hasTo($user->email) && str_contains($mail->verifyUrl, '/verify-email/');
        });
    }

    public function test_resend_is_a_no_op_once_verified(): void
    {
        Mail::fake();
        $user = $this->unverified();
        $user->markEmailAsVerified();

        $this->actingAs($user->fresh())
            ->postJson('/api/v1/auth/email/resend-verification')
            ->assertOk();

        Mail::assertNothingQueued();
    }

    /**
     * Social sign-ins that gave us no address get a synthesised one. Mailing it
     * would bounce every time, so the endpoint refuses instead.
     */
    public function test_resend_refuses_a_placeholder_social_address(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'line_abc123@social.local',
            'email_verified_at' => null,
            'social_provider' => 'line',
        ]);
        $user->assignRole('customer');

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/resend-verification')
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/email/resend-verification')->assertStatus(401);
    }
}
