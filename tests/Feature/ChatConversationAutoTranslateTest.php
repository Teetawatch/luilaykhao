<?php

namespace Tests\Feature;

use App\Events\ChatPrivateSignal;
use App\Events\SupportMessageTranslated;
use App\Jobs\TranslateChatBacklogJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ConversationMessageTranslation;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แปลอัตโนมัติเฟส 2 — แชทส่วนตัวกับทีมงาน และกล่องแชทซัพพอร์ต (คุยกันสองฝั่ง
 * แบบ Grab: ลูกค้าต่างชาติ ↔ ทีมงานไทย)
 */
class ChatConversationAutoTranslateTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.chat_auto_translate' => true,
        ]);
        Event::fake([ChatPrivateSignal::class, SupportMessageTranslated::class]);
        $this->mock(FcmService::class, fn (MockInterface $m) => $m->shouldReceive('sendToUser'));
        Role::findOrCreate('staff');
        Role::findOrCreate('admin');

        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'conv-translate-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2900, 'status' => 'active',
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
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2900,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'Traveller', 'nationality' => 'TH']);

        return $user;
    }

    private function answer(string $text, string $source = 'th'): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode([
                'translation' => $text, 'source_lang' => $source, 'same_language' => false,
            ], JSON_UNESCAPED_UNICODE)]],
        ];
    }

    private function fakeTranslation(string $text, string $source = 'th'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->answer($text, $source))]);
    }

    private function privateUrl(string $path = 'messages'): string
    {
        return "/api/v1/schedules/{$this->schedule->id}/chat/private/{$path}";
    }

    // ── แชทส่วนตัวกับทีมงาน ─────────────────────────────────────────────

    public function test_foreign_traveller_asks_in_english_and_staff_read_thai(): void
    {
        $this->fakeTranslation('มียาแก้แพ้ไหมครับ', 'en');
        $guest = $this->traveller(['locale' => 'en']);

        $id = $this->actingAs($guest, 'sanctum')
            ->postJson($this->privateUrl(), ['body' => 'Do you have any allergy medicine?'])
            ->assertCreated()
            ->json('data.message.id');

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['system'][0]['text'], 'Thai'));
        Event::assertDispatched(ChatPrivateSignal::class, fn (ChatPrivateSignal $e) => $e->name === ChatPrivateSignal::TRANSLATED
            && $e->payload['message_id'] === $id
            && $e->payload['translations']->th['text'] === 'มียาแก้แพ้ไหมครับ');

        $messages = $this->actingAs($this->staff, 'sanctum')
            ->getJson($this->privateUrl().'?user_id='.$guest->id)
            ->assertOk()
            ->assertJsonPath('data.auto_translate.lang', 'th')
            ->json('data.messages');
        $this->assertSame('มียาแก้แพ้ไหมครับ', collect($messages)->firstWhere('id', $id)['translations']['th']['text']);
    }

    public function test_staff_thai_reply_is_translated_for_the_foreign_traveller(): void
    {
        $this->fakeTranslation('Yes, I will bring it to your seat.');
        $guest = $this->traveller(['locale' => 'en']);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->privateUrl(), ['user_id' => $guest->id, 'body' => 'มีครับ เดี๋ยวเอาไปให้ที่ที่นั่งนะครับ'])
            ->assertCreated();

        $this->assertSame(['en'], ConversationMessageTranslation::pluck('lang')->all());
        $this->actingAs($guest, 'sanctum')
            ->getJson($this->privateUrl())
            ->assertJsonPath('data.auto_translate.lang', 'en')
            ->assertJsonPath('data.messages.0.translations.en.text', 'Yes, I will bring it to your seat.');
    }

    public function test_thai_traveller_and_thai_staff_never_call_the_model(): void
    {
        Http::fake();
        $thai = $this->traveller();

        $this->actingAs($thai, 'sanctum')->postJson($this->privateUrl(), ['body' => 'ขอยาแก้เมารถหน่อยค่ะ'])->assertCreated();
        $this->actingAs($thai, 'sanctum')->postJson($this->privateUrl(), ['body' => 'thank you'])->assertCreated();

        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessageTranslation::count());
    }

    public function test_deleting_a_private_message_removes_its_translations(): void
    {
        $this->fakeTranslation('มียาแก้แพ้ไหมครับ', 'en');
        $guest = $this->traveller(['locale' => 'en']);
        $id = $this->actingAs($guest, 'sanctum')
            ->postJson($this->privateUrl(), ['body' => 'Do you have any allergy medicine?'])
            ->json('data.message.id');
        $this->assertSame(1, ConversationMessageTranslation::count());

        $this->actingAs($guest, 'sanctum')->deleteJson($this->privateUrl("messages/{$id}"))->assertOk();

        $this->assertSame(0, ConversationMessageTranslation::count());
    }

    public function test_private_backlog_queues_old_messages_for_the_reader(): void
    {
        Bus::fake([TranslateChatBacklogJob::class]);
        $guest = $this->traveller(['locale' => 'en']);
        $thai = $this->actingAs($this->staff, 'sanctum')
            ->postJson($this->privateUrl(), ['user_id' => $guest->id, 'body' => 'ทักมาได้ตลอดนะครับ'])
            ->json('data.message.id');
        ConversationMessageTranslation::query()->delete();

        $this->actingAs($guest, 'sanctum')
            ->postJson($this->privateUrl('translations'), ['lang' => 'en', 'message_ids' => [$thai]])
            ->assertOk()
            ->assertJsonPath('data.pending', [$thai]);

        Bus::assertDispatched(TranslateChatBacklogJob::class, fn ($job) => $job->kind === 'private' && $job->messageIds === [$thai]);
    }

    public function test_another_traveller_cannot_read_someone_elses_thread_translations(): void
    {
        $guest = $this->traveller(['locale' => 'en']);
        $other = $this->traveller();

        $this->actingAs($other, 'sanctum')
            ->postJson($this->privateUrl('translations'), ['user_id' => $guest->id, 'lang' => 'th', 'message_ids' => [1]])
            ->assertForbidden();
    }

    // ── กล่องแชทซัพพอร์ต ────────────────────────────────────────────────

    public function test_foreign_customer_writes_support_in_english_and_admin_reads_thai(): void
    {
        $this->fakeTranslation('จองให้เพื่อนได้ไหมครับ', 'en');
        $customer = User::factory()->create(['nationality' => 'FR']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $id = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/support/messages', ['body' => 'Can I book for a friend?'])
            ->assertCreated()
            ->json('data.id');

        Event::assertDispatched(SupportMessageTranslated::class, fn ($e) => $e->messageId === $id
            && $e->translations['th']['text'] === 'จองให้เพื่อนได้ไหมครับ');

        $conversationId = SupportMessage::find($id)->conversation_id;
        $data = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/support/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.conversation.auto_translate.lang', 'th')
            ->assertJsonPath('data.conversation.auto_translate.customer_lang', 'en')
            ->json('data.messages');
        $this->assertSame('จองให้เพื่อนได้ไหมครับ', collect($data)->firstWhere('id', $id)['translations']['th']['text']);
    }

    public function test_admin_thai_reply_is_translated_for_the_customer(): void
    {
        $this->fakeTranslation('Yes, you can.');
        $customer = User::factory()->create(['locale' => 'en']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/support/conversation')->assertOk()
            ->assertJsonPath('data.auto_translate.lang', 'en');
        $conversationId = SupportConversation::where('user_id', $customer->id)->value('id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/support/conversations/{$conversationId}/messages", ['body' => 'ได้ครับ'])
            ->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['system'][0]['text'], 'English'));
    }

    public function test_thai_customer_support_chat_never_calls_the_model(): void
    {
        Http::fake();
        $customer = User::factory()->create(['locale' => 'th', 'nationality' => 'TH']);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/support/messages', ['body' => 'สอบถามทริปค่ะ'])->assertCreated();

        Http::assertNothingSent();
    }

    public function test_support_backlog_includes_the_thai_welcome_message(): void
    {
        Bus::fake([TranslateChatBacklogJob::class]);
        $customer = User::factory()->create(['locale' => 'en']);
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/support/conversation')->assertOk();
        $welcome = SupportMessage::where('sender_role', 'system')->value('id');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/support/translations', ['lang' => 'en', 'message_ids' => [$welcome]])
            ->assertOk()
            ->assertJsonPath('data.pending', [$welcome]);

        Bus::assertDispatched(TranslateChatBacklogJob::class, fn ($job) => $job->kind === 'support');
    }

    public function test_customer_cannot_use_the_admin_backlog(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/admin/support/conversations/1/translations', ['lang' => 'th', 'message_ids' => [1]])
            ->assertForbidden();
    }
}
