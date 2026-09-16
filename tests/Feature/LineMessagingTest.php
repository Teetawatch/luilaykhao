<?php

namespace Tests\Feature;

use App\Models\FcmToken;
use App\Models\SmartNotification;
use App\Models\User;
use App\Services\LineMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ส่งการแจ้งเตือนหา "ลูกค้าที่จองผ่านไลน์แล้วไม่ได้ลงแอป" ทาง LINE OA
 *
 * ทุกข้อในนี้คือกติกาที่ถ้าหลุดแล้วจะเสียเงินหรือรบกวนลูกค้า ไม่ใช่แค่ทำงานผิด
 */
class LineMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        config([
            'line.channel_token' => 'channel-token',
            'line.liff_id' => '1234-abcd',
        ]);
    }

    /**
     * LINE รับข้อความไว้เรียบร้อย
     *
     * เรียกในแต่ละเทสต์ ไม่ใช่ใน setUp — Http::fake() ให้ stub ตัวแรกที่ตรงชนะ
     * การวาง stub เหมาโดเมนไว้ก่อนจะทำให้เทสต์ที่อยากจำลอง 403/400 ไม่มีทางได้
     */
    private function fakeLineAccepts(): void
    {
        Http::fake(['api.line.me/*' => Http::response(['sentMessages' => []])]);
    }

    private function lineCustomer(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'social_provider' => 'line',
            'social_id' => 'Uline-abc',
        ], $attributes));
        $user->assignRole('customer');

        return $user;
    }

    private function notify(User $user, string $type = 'booking_confirmed', array $data = []): SmartNotification
    {
        return SmartNotification::send($user->id, $type, 'ยืนยันการจองแล้ว', 'รอบเขาใหญ่ 10 ต.ค.', $data);
    }

    private function pushes(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/v2/bot/message/push'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_a_line_customer_without_the_app_gets_the_notification_in_line(): void
    {
        $this->fakeLineAccepts();
        $user = $this->lineCustomer();

        $this->notify($user, 'booking_confirmed', ['booking_ref' => 'LLK-20261010-0001']);

        $pushes = $this->pushes();
        $this->assertCount(1, $pushes, 'ไม่ได้ส่งข้อความเข้า LINE');
        $this->assertSame('Uline-abc', $pushes[0]['to']);

        $text = $pushes[0]['messages'][0]['text'];
        $this->assertStringContainsString('ยืนยันการจองแล้ว', $text);
        $this->assertStringContainsString('รอบเขาใหญ่ 10 ต.ค.', $text);
        $this->assertStringContainsString(
            'https://liff.line.me/1234-abcd?booking=LLK-20261010-0001',
            $text,
            'ลิงก์ต้องพากลับเข้าใบจองใบนั้น ไม่ใช่หน้าแรก'
        );
    }

    public function test_a_customer_who_has_the_app_is_not_messaged_twice(): void
    {
        $this->fakeLineAccepts();
        $user = $this->lineCustomer();
        FcmToken::create(['user_id' => $user->id, 'token' => 'fcm-token', 'platform' => 'ios', 'is_active' => true]);

        $this->notify($user);

        $this->assertSame([], $this->pushes(), 'คนที่มีแอปได้ push อยู่แล้ว ไม่ควรได้ LINE ซ้ำ');
    }

    public function test_a_token_that_is_no_longer_active_counts_as_no_app(): void
    {
        $this->fakeLineAccepts();
        $user = $this->lineCustomer();
        FcmToken::create(['user_id' => $user->id, 'token' => 'old', 'platform' => 'ios', 'is_active' => false]);

        $this->notify($user);

        $this->assertCount(1, $this->pushes(), 'ถอนแอปไปแล้วต้องกลับมาได้ข้อความทาง LINE');
    }

    public function test_a_customer_who_never_signed_in_with_line_is_skipped(): void
    {
        $this->fakeLineAccepts();
        $user = User::factory()->create(['social_provider' => null, 'social_id' => null]);

        $this->notify($user);

        $this->assertSame([], $this->pushes());
    }

    public function test_only_the_listed_notification_types_are_sent(): void
    {
        $this->fakeLineAccepts();
        $user = $this->lineCustomer();

        // แชทกับเรื่องของทีมงานไม่อยู่ในรายการ — ส่งทุกประเภทคือทั้งแพงและรบกวน
        $this->notify($user, 'staff_assignment');
        $this->notify($user, 'vehicle_approaching');

        $this->assertSame([], $this->pushes());

        $this->notify($user, 'waitlist_offered');

        $this->assertCount(1, $this->pushes(), 'เรื่องที่มีเส้นตายต้องส่ง');
    }

    public function test_nothing_is_sent_when_no_channel_token_is_configured(): void
    {
        $this->fakeLineAccepts();
        config(['line.channel_token' => '']);
        $user = $this->lineCustomer();

        $this->notify($user);

        $this->assertSame([], $this->pushes());
    }

    public function test_the_message_still_goes_out_without_a_liff_id_just_without_a_link(): void
    {
        $this->fakeLineAccepts();
        config(['line.liff_id' => '']);
        $user = $this->lineCustomer();

        $this->notify($user, 'booking_confirmed', ['booking_ref' => 'LLK-1']);

        $text = $this->pushes()[0]['messages'][0]['text'];
        $this->assertStringContainsString('ยืนยันการจองแล้ว', $text);
        $this->assertStringNotContainsString('liff.line.me', $text);
    }

    public function test_a_notification_with_no_booking_lands_on_my_bookings(): void
    {
        $this->fakeLineAccepts();
        $user = $this->lineCustomer();

        $this->notify($user, 'gift_received');

        $this->assertStringContainsString(
            'https://liff.line.me/1234-abcd?page=bookings',
            $this->pushes()[0]['messages'][0]['text']
        );
    }

    public function test_a_customer_who_blocked_the_official_account_is_remembered_and_skipped(): void
    {
        Http::fake(['api.line.me/*' => Http::response(['message' => 'You cannot send messages to this user'], 403)]);
        $user = $this->lineCustomer();

        $this->notify($user);

        $this->assertNotNull($user->fresh()->line_blocked_at, 'ต้องจำไว้ว่าส่งหาคนนี้ไม่ได้');
        $this->assertCount(1, $this->pushes());

        // ครั้งต่อไปต้องไม่ยิงซ้ำอีก
        $this->notify($user->fresh());

        $this->assertCount(1, $this->pushes(), 'ไม่ควรยิงซ้ำหาคนที่บล็อกเราไปแล้ว');
    }

    public function test_opening_liff_again_clears_the_blocked_flag(): void
    {
        $user = $this->lineCustomer(['line_blocked_at' => now()->subDay()]);

        config(['services.line.liff_channel_id' => 'test-channel']);
        Http::fake([
            'api.line.me/oauth2/v2.1/verify*' => Http::response(['client_id' => 'test-channel']),
            'api.line.me/v2/profile' => Http::response(['userId' => 'Uline-abc', 'displayName' => 'ลูกค้า']),
            'api.line.me/*' => Http::response(['sentMessages' => []]),
        ]);

        $this->postJson('/api/v1/auth/line/liff', ['access_token' => 'tok'])->assertSuccessful();

        $this->assertNull($user->fresh()->line_blocked_at, 'กลับมาเปิด LIFF แล้วต้องได้รับข้อความอีกครั้ง');
    }

    public function test_a_misconfigured_channel_pair_does_not_mark_the_customer_as_blocked(): void
    {
        // Login channel กับ Messaging channel คนละ provider — เป็นการตั้งค่าที่ผิด
        // ไม่ใช่ลูกค้าบล็อกเรา ห้ามจำว่าคนนี้ส่งไม่ได้ ไม่งั้นแก้ config แล้วยังเงียบอยู่
        Http::fake(['api.line.me/*' => Http::response(['message' => "The property, 'to', in the request body is invalid"], 400)]);
        $user = $this->lineCustomer();

        $this->notify($user);

        $this->assertNull($user->fresh()->line_blocked_at);
    }

    public function test_the_check_command_fails_loudly_when_nothing_is_configured(): void
    {
        config(['line.channel_token' => '']);

        $this->artisan('line:check')
            ->expectsOutputToContain('ยังไม่ได้ตั้ง LINE_CHANNEL_TOKEN')
            ->assertFailed();
    }

    public function test_the_check_command_sends_a_real_message_when_asked(): void
    {
        Http::fake([
            'api.line.me/v2/bot/info' => Http::response(['displayName' => 'ลุยเลเขา', 'basicId' => '@luilaykhao']),
            'api.line.me/*' => Http::response(['sentMessages' => []]),
        ]);
        $user = $this->lineCustomer();

        $this->artisan('line:check --to='.$user->id)->assertSuccessful();

        $this->assertCount(1, $this->pushes(), 'ต้องส่งข้อความทดสอบจริง');
    }

    public function test_the_check_command_refuses_a_customer_with_no_line_identity(): void
    {
        Http::fake(['api.line.me/v2/bot/info' => Http::response(['displayName' => 'ลุยเลเขา'])]);
        $user = User::factory()->create(['social_provider' => null, 'social_id' => null]);

        $this->artisan('line:check --to='.$user->id)->assertFailed();

        $this->assertSame([], $this->pushes());
    }

    public function test_a_line_failure_never_breaks_what_triggered_it(): void
    {
        Http::fake(['api.line.me/*' => fn () => throw new \RuntimeException('network down')]);
        $user = $this->lineCustomer();

        $notification = $this->notify($user);

        $this->assertDatabaseHas('smart_notifications', ['id' => $notification->id]);
        $this->assertFalse(app(LineMessagingService::class)->sendNotification($notification->fresh()));
    }
}
