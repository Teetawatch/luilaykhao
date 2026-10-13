<?php

namespace Tests\Feature;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * บันทึกอุปกรณ์รับแจ้งเตือน — push-to-start token ของ Live Activity เป็นของแถม
 * ที่ห้ามทำให้ FCM token ตกไปด้วย (เคยยาวเกินเพดาน ทั้งคำขอโดน 422 แล้ว
 * iPhone เครื่องนั้นไม่ได้รับแจ้งเตือนอะไรเลย)
 */
class PushTokenRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_long_start_token_from_newer_ios_is_saved_with_the_fcm_token(): void
    {
        $user = User::factory()->create();
        $startToken = str_repeat('ab', 160); // 320 ตัว — เกินเพดานเดิม 200

        $this->actingAs($user)->postJson('/api/v1/notifications/push-token', [
            'token' => 'fcm-token-1',
            'platform' => 'iOS',
            'live_activity_start_token' => $startToken,
        ])->assertOk();

        $this->assertDatabaseHas('fcm_tokens', [
            'token' => 'fcm-token-1',
            'user_id' => $user->id,
            'live_activity_start_token' => $startToken,
            'is_active' => true,
        ]);
    }

    public function test_an_unusable_start_token_is_dropped_but_the_fcm_token_is_still_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/notifications/push-token', [
            'token' => 'fcm-token-2',
            'platform' => 'iOS',
            'live_activity_start_token' => str_repeat('a', 2000),
        ])->assertOk();

        $saved = FcmToken::where('token', 'fcm-token-2')->first();
        $this->assertNotNull($saved, 'FCM token ต้องถูกบันทึกแม้ start token จะใช้ไม่ได้');
        $this->assertNull($saved->live_activity_start_token);
    }

    public function test_an_unusable_start_token_does_not_wipe_the_one_already_stored(): void
    {
        $user = User::factory()->create();
        FcmToken::create([
            'token' => 'fcm-token-3',
            'user_id' => $user->id,
            'live_activity_start_token' => 'good-start-token',
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/v1/notifications/push-token', [
            'token' => 'fcm-token-3',
            'live_activity_start_token' => ['not', 'a', 'string'],
        ])->assertOk();

        $this->assertSame(
            'good-start-token',
            FcmToken::where('token', 'fcm-token-3')->value('live_activity_start_token'),
        );
    }

    public function test_the_fcm_token_itself_is_still_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/notifications/push-token', [
            'live_activity_start_token' => 'abc',
        ])->assertStatus(422);
    }
}
