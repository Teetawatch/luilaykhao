<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatPushTranslator;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * push ของแชทเป็นภาษาของคนรับ — ข้อความใช้คำแปลอัตโนมัติ คำประกอบใช้ lang/en.json
 * คนอ่านไทยได้ต้นฉบับทันที แปลไม่ได้ก็ส่งต้นฉบับ push ไม่หาย
 */
class ChatPushTranslationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{user: int, title: string, body: string, type: string}> */
    private array $pushes = [];

    private TripSchedule $schedule;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.chat_auto_translate' => true,
        ]);
        $this->mock(FcmService::class, function (MockInterface $m) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title, $body, $data = []) {
                $this->pushes[] = ['user' => (int) $userId, 'title' => $title, 'body' => $body, 'type' => $data['type'] ?? ''];
            });
        });
        Role::findOrCreate('staff');
        Role::findOrCreate('admin');

        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว', 'slug' => 'push-tr-'.uniqid(), 'type' => 'trekking', 'location' => 'เชียงใหม่',
            'difficulty' => 'medium', 'duration_days' => 2, 'max_participants' => 10, 'price_per_person' => 2500, 'status' => 'active',
        ]);
        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(4)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $this->staff = User::factory()->create(['locale' => 'th', 'nationality' => 'TH', 'nickname' => 'พี่ต้น']);
        $this->staff->assignRole('staff');
        $this->schedule->staff()->attach($this->staff->id, ['assigned_by' => $this->staff->id]);
    }

    private function traveller(array $attrs = []): User
    {
        $user = User::factory()->create($attrs + ['locale' => 'th']);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(), 'user_id' => $user->id, 'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(), 'status' => 'confirmed', 'total_amount' => 2500,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'Traveller', 'nationality' => 'TH']);

        return $user;
    }

    private function fakeTranslation(string $text, string $source = 'th'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode([
                'translation' => $text, 'source_lang' => $source, 'same_language' => false,
            ], JSON_UNESCAPED_UNICODE)]],
        ])]);
    }

    private function pushTo(User $user, string $type): ?array
    {
        return collect($this->pushes)->first(fn ($p) => $p['user'] === $user->id && $p['type'] === $type);
    }

    public function test_trip_chat_push_reaches_each_reader_in_their_language(): void
    {
        $this->fakeTranslation('The van leaves at 5 a.m. tomorrow.');
        $guest = $this->traveller(['locale' => 'en']);
        $thai = $this->traveller();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/messages", ['body' => 'พรุ่งนี้รถออกตีห้านะครับ'])
            ->assertCreated();

        $this->assertSame('พี่ต้น: The van leaves at 5 a.m. tomorrow.', $this->pushTo($guest, 'chat_message')['body']);
        $this->assertSame('พี่ต้น: พรุ่งนี้รถออกตีห้านะครับ', $this->pushTo($thai, 'chat_message')['body']);
        // push กับงานแปลในห้องใช้คำแปลเดียวกัน — เรียกโมเดลครั้งเดียว
        Http::assertSentCount(1);
    }

    public function test_a_mention_uses_english_wording(): void
    {
        $this->fakeTranslation('Please bring your passport.');
        $guest = $this->traveller(['locale' => 'en']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/messages", [
                'body' => 'อย่าลืมพาสปอร์ตนะครับ',
                'mentions' => [$guest->id],
            ])
            ->assertCreated();

        $push = $this->pushTo($guest, 'chat_message');
        $this->assertSame('พี่ต้น mentioned you: Please bring your passport.', $push['body']);
        $this->assertStringStartsWith('📣 ', $push['title']);
    }

    public function test_over_budget_the_push_still_goes_out_in_the_original(): void
    {
        DB::table('chat_translation_usage')->insert([
            'date' => now('Asia/Bangkok')->toDateString(), 'model' => 'claude-haiku-5-5',
            'calls' => 1, 'cost_usd' => 1000 / 36, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake();
        $guest = $this->traveller(['locale' => 'en']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/messages", ['body' => 'พรุ่งนี้รถออกตีห้านะครับ'])
            ->assertCreated();

        $this->assertSame('พี่ต้น: พรุ่งนี้รถออกตีห้านะครับ', $this->pushTo($guest, 'chat_message')['body']);
        Http::assertNothingSent();
    }

    public function test_private_reply_reaches_the_foreign_traveller_translated(): void
    {
        $this->fakeTranslation('Yes, I will bring it to your seat.');
        $guest = $this->traveller(['locale' => 'en']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/private/messages", [
                'user_id' => $guest->id, 'body' => 'มีครับ เดี๋ยวเอาไปให้ที่ที่นั่งนะครับ',
            ])
            ->assertCreated();

        $push = $this->pushTo($guest, 'chat_private');
        $this->assertSame('🔒 The team replied', $push['title']);
        $this->assertStringStartsWith('พี่ต้น: Yes, I will bring it to your seat. · ', $push['body']);
    }

    public function test_private_question_in_english_reaches_thai_staff_in_thai(): void
    {
        $this->fakeTranslation('มียาแก้แพ้ไหมครับ', 'en');
        $guest = $this->traveller(['locale' => 'en', 'nickname' => 'Tom']);

        $this->actingAs($guest, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/private/messages", ['body' => 'Do you have allergy medicine?'])
            ->assertCreated();

        $push = $this->pushTo($this->staff, 'chat_private');
        $this->assertSame('🔒 Tom ถามทีมงานแบบส่วนตัว', $push['title']);
        $this->assertStringStartsWith('มียาแก้แพ้ไหมครับ · ', $push['body']);
    }

    public function test_support_reply_reaches_the_customer_in_english(): void
    {
        $this->fakeTranslation('Yes, you can book for a friend.');
        $customer = User::factory()->create(['locale' => 'en']);
        $admin = User::factory()->create(['nickname' => 'แอดมินเอ']);
        $admin->assignRole('admin');
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/support/conversation')->assertOk();
        $conversationId = SupportConversation::where('user_id', $customer->id)->value('id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/support/conversations/{$conversationId}/messages", ['body' => 'จองให้เพื่อนได้ครับ'])
            ->assertCreated();

        $push = $this->pushTo($customer, 'support_message');
        $this->assertSame('💬 Luilaykhao team', $push['title']);
        $this->assertSame('แอดมินเอ: Yes, you can book for a friend.', $push['body']);
    }

    public function test_photo_and_voice_previews_follow_the_language(): void
    {
        $text = app(ChatPushTranslator::class);
        $photo = new ChatMessage(['image_path' => 'chat/a.jpg']);
        $voice = new ChatMessage(['audio_path' => 'chat/a.m4a', 'audio_duration_ms' => 65000]);

        $this->assertSame('📷 Sent a photo', $text->inLanguage('en', fn () => $text->preview($photo, null)));
        $this->assertSame('📷 ส่งรูปภาพ', $text->inLanguage('th', fn () => $text->preview($photo, null)));
        $this->assertSame('🎤 Voice message 1:05', $text->inLanguage('zh', fn () => $text->preview($voice, null)));
    }
}
