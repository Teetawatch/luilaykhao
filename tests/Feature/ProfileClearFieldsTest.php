<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileClearFieldsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * หน้าโปรไฟล์บนเว็บส่งทุกช่องเสมอ ช่องที่ลบจนว่างมาเป็นสตริงว่าง
     * ต้องถูกล้างเป็น null จริง ไม่ใช่ค้างค่าเดิมไว้
     */
    public function test_empty_strings_clear_optional_profile_fields(): void
    {
        $user = User::factory()->create([
            'nickname' => 'เอ',
            'allergies' => 'แพ้กุ้ง',
            'health_notes' => 'หอบหืด',
            'id_card' => '1234567890123',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', [
                'name' => $user->name,
                'title' => '',
                'nickname' => '',
                'allergies' => '',
                'health_notes' => '',
                'id_card' => '',
                'blood_group' => 'O+',
            ])
            ->assertOk()
            ->assertJsonPath('data.nickname', null)
            ->assertJsonPath('data.allergies', null)
            ->assertJsonPath('data.blood_group', 'O+');

        $user->refresh();
        $this->assertNull($user->nickname);
        $this->assertNull($user->allergies);
        $this->assertNull($user->health_notes);
        $this->assertNull($user->id_card);
    }

    public function test_password_only_request_leaves_profile_untouched(): void
    {
        $user = User::factory()->create(['nickname' => 'บี', 'allergies' => 'แพ้ถั่ว']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/profile', [
                'password' => 'newpass123',
                'password_confirmation' => 'newpass123',
            ])
            ->assertOk()
            ->assertJsonPath('data.has_password', true);

        $user->refresh();
        $this->assertSame('บี', $user->nickname);
        $this->assertSame('แพ้ถั่ว', $user->allergies);
    }
}
