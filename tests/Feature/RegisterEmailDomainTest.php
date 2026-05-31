<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegisterEmailDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'สมชาย ใจดี',
            'title' => 'นาย',
            'email' => 'somchai@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_allows_registration_with_known_provider(): void
    {
        $res = $this->postJson('/api/v1/auth/register', $this->payload());

        $res->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'somchai@gmail.com']);
    }

    public function test_rejects_disposable_or_unknown_domain(): void
    {
        $res = $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'spammer@temp-mail.org',
        ]));

        $res->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'spammer@temp-mail.org']);
    }

    public function test_email_is_normalized_to_lowercase(): void
    {
        $res = $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'Somchai@GMAIL.com',
        ]));

        $res->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'somchai@gmail.com']);
    }
}
