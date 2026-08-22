<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_birth_date_from_the_signup_form_is_stored(): void
    {
        $res = $this->postJson('/api/v1/auth/register', $this->payload([
            'birth_date' => '1998-04-21',
        ]));

        $res->assertSuccessful()
            ->assertJsonPath('data.user.birth_date', '1998-04-21');

        $this->assertSame(
            '1998-04-21',
            User::where('email', 'somchai@gmail.com')->first()->birth_date->format('Y-m-d'),
        );
    }

    public function test_birth_date_in_the_future_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'birth_date' => now()->addDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('birth_date');
    }

    public function test_registration_still_works_without_a_birth_date(): void
    {
        // ช่องทางอื่น (LIFF/โซเชียล) ยังไม่ส่งวันเกิดมา ต้องไม่พัง
        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertSuccessful()
            ->assertJsonPath('data.user.birth_date', null);
    }

    public function test_empty_birth_date_from_a_web_form_is_treated_as_not_given(): void
    {
        // ฟอร์มเว็บส่งสตริงว่างมาเมื่อผู้ใช้ไม่ได้เลือกวัน ต้องไม่กลายเป็น 422
        $this->postJson('/api/v1/auth/register', $this->payload([
            'birth_date' => '',
        ]))->assertSuccessful()->assertJsonPath('data.user.birth_date', null);
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
