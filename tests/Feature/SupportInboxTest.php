<?php

namespace Tests\Feature;

use App\Jobs\SendSupportPushJob;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\SupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportInboxTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_opening_conversation_seeds_a_welcome_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/support/conversation')
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/support/messages')
            ->assertOk()
            ->assertJsonPath('data.messages.0.sender_role', 'system')
            ->assertJsonPath('data.messages.0.body', SupportService::WELCOME_BODY);

        // ห้องเดียวต่อคน + ข้อความต้อนรับตัวเดียว ไม่ซ้ำเมื่อเรียกหลายครั้ง
        $this->assertSame(1, SupportConversation::where('user_id', $user->id)->count());
        $this->assertSame(1, SupportMessage::where('sender_role', 'system')->count());
    }

    public function test_customer_can_send_a_message_and_push_is_queued(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/support/messages', ['body' => 'จองทริปเขาใหญ่ยังไงครับ'])
            ->assertCreated()
            ->assertJsonPath('data.sender_role', 'customer')
            ->assertJsonPath('data.is_mine', true);

        Bus::assertDispatched(SendSupportPushJob::class);
    }

    public function test_message_requires_body_or_image(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/support/messages', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('body');
    }

    public function test_customer_can_send_image_message(): void
    {
        Bus::fake();
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/support/messages', [
                'image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', null);

        $this->assertNotEmpty($response->json('data.image_url'));
        $message = SupportMessage::where('sender_role', 'customer')->firstOrFail();
        Storage::disk('public')->assertExists($message->image_path);
    }

    public function test_customer_unread_reflects_admin_replies_only(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();

        // ลูกค้าเปิดห้อง (welcome) แล้วอ่าน
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/support/messages')->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/support/read')->assertOk();

        $conversation = SupportConversation::where('user_id', $user->id)->firstOrFail();

        // ทีมงานตอบ 2 ข้อความ
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/support/conversations/{$conversation->id}/messages", ['body' => 'สวัสดีค่ะ'])
            ->assertCreated();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/support/conversations/{$conversation->id}/messages", ['body' => 'ยินดีช่วยเหลือค่ะ'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/support/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    public function test_admin_sees_conversation_in_inbox_after_customer_writes(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/support/messages', ['body' => 'ขอสอบถามเรื่องมัดจำครับ'])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/support/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $user->id)
            ->assertJsonPath('data.0.unread_count', 1);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/support/conversations/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_admin_opening_conversation_clears_admin_unread(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/support/messages', ['body' => 'สวัสดีครับ'])
            ->assertCreated();

        $conversation = SupportConversation::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/support/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.conversation.unread_count', 0)
            ->assertJsonPath('data.conversation.user.id', $user->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/support/conversations/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_admin_mark_read_clears_unread_without_reloading(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/support/messages', ['body' => 'ทักครับ'])
            ->assertCreated();

        $conversation = SupportConversation::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/support/conversations/{$conversation->id}/read")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/support/conversations/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_admin_can_close_and_reopen_conversation(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();
        $conversation = app(SupportService::class)->conversationFor($user);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/support/conversations/{$conversation->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        // ลูกค้าทักใหม่ → เคสเด้งกลับเป็น open อัตโนมัติ
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/support/messages', ['body' => 'มีเรื่องสอบถามเพิ่มครับ'])
            ->assertCreated();

        $this->assertSame('open', $conversation->fresh()->status);
    }

    public function test_customer_cannot_access_admin_support_endpoints(): void
    {
        $user = User::factory()->create();
        $conversation = app(SupportService::class)->conversationFor($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/support/conversations')
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/support/conversations/{$conversation->id}/messages", ['body' => 'x'])
            ->assertStatus(403);
    }
}
