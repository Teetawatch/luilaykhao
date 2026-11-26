<?php

namespace Tests\Feature;

use App\Events\ChatMessageTranslated;
use App\Jobs\TranslateChatBacklogJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ChatMessageTranslation;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แชทแปลอัตโนมัติแบบ Grab — ข้อความใหม่ถูกแปลเป็นภาษาของคนในห้องที่อ่าน
 * ภาษาอื่น ห้องคนไทยล้วนไม่เรียกโมเดลเลย
 */
class ChatAutoTranslateTest extends TestCase
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
        Event::fake([ChatMessageTranslated::class]);
        Role::findOrCreate('staff');

        $trip = Trip::create([
            'title' => 'เกาะหลีเป๊ะ', 'slug' => 'auto-translate-'.uniqid(), 'type' => 'island',
            'location' => 'สตูล', 'difficulty' => 'easy', 'duration_days' => 3,
            'max_participants' => 10, 'price_per_person' => 4900, 'status' => 'active',
        ]);
        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(12)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);

        $this->staff = User::factory()->create(['locale' => 'th', 'nationality' => 'TH']);
        $this->staff->assignRole('staff');
        $this->schedule->staff()->attach($this->staff->id, ['assigned_by' => $this->staff->id]);
    }

    private function traveller(array $attrs = [], string $nationality = 'TH'): User
    {
        $user = User::factory()->create($attrs + ['locale' => 'th']);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 4900,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'Traveller', 'nationality' => $nationality]);

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

    private function fakeTranslations(array ...$answers): void
    {
        $sequence = Http::sequence();
        foreach ($answers as $answer) {
            $sequence->push($answer);
        }
        Http::fake(['api.anthropic.com/*' => $sequence]);
    }

    private function send(User $author, string $body): int
    {
        return (int) $this->actingAs($author, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/messages", ['body' => $body])
            ->assertCreated()
            ->json('data.id');
    }

    /** ข้อความหนึ่งตามที่ GET chat/messages ส่งให้ผู้ชม */
    private function listed(User $viewer, int $id): array
    {
        $messages = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/messages")
            ->assertOk()
            ->json('data.messages');

        return collect($messages)->firstWhere('id', $id);
    }

    public function test_a_thai_only_room_never_calls_the_model(): void
    {
        Http::fake();
        $this->traveller();

        $this->send($this->staff, 'พรุ่งนี้รถออกตีห้านะครับ');
        $this->send($this->staff, 'see you at 5');

        Http::assertNothingSent();
        $this->assertSame(0, ChatMessageTranslation::count());
        Event::assertNotDispatched(ChatMessageTranslated::class);
    }

    public function test_thai_message_is_translated_for_the_foreign_traveller_and_broadcast(): void
    {
        $this->fakeTranslations($this->answer('The van leaves at 5 a.m. tomorrow.'));
        $foreigner = $this->traveller(['locale' => 'en']);

        $id = $this->send($this->staff, 'พรุ่งนี้รถออกตีห้านะครับ');

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['system'][0]['text'], 'English'));
        Event::assertDispatched(ChatMessageTranslated::class, fn (ChatMessageTranslated $e) => $e->messageId === $id
            && $e->body === 'พรุ่งนี้รถออกตีห้านะครับ'
            && $e->translations['en']['text'] === 'The van leaves at 5 a.m. tomorrow.');

        $this->assertSame(
            ['en' => ['text' => 'The van leaves at 5 a.m. tomorrow.', 'source_lang' => 'th']],
            $this->listed($foreigner, $id)['translations'],
        );
    }

    public function test_english_from_the_foreigner_is_translated_into_thai_only(): void
    {
        $this->fakeTranslations($this->answer('รถจอดตรงไหนครับ', 'en'));
        $foreigner = $this->traveller(['locale' => 'en']);

        $id = $this->send($foreigner, 'Where does the van park?');

        // อังกฤษไม่ต้องแปลเป็นอังกฤษ — เรียกโมเดลครั้งเดียว (ไทย)
        Http::assertSentCount(1);
        $this->assertSame(['th'], ChatMessageTranslation::where('message_id', $id)->pluck('lang')->all());
    }

    public function test_emoji_or_numbers_only_are_not_translated(): void
    {
        Http::fake();
        $this->traveller(['locale' => 'en']);

        $this->send($this->staff, '👍👍 555');
        $this->send($this->staff, '08:30');

        Http::assertNothingSent();
    }

    public function test_foreign_nationality_on_the_booking_counts_and_chinese_app_reads_chinese(): void
    {
        $passport = $this->traveller([], 'DE');
        $chinese = $this->traveller(['locale' => 'zh']);
        $service = app(ChatTranslationService::class);

        $this->assertSame('en', $service->preferredLanguage($passport, $this->schedule));
        $this->assertSame('zh', $service->preferredLanguage($chinese, $this->schedule));
        $this->assertSame('th', $service->preferredLanguage($this->staff, $this->schedule));
        $this->assertSame(['en', 'th', 'zh'], $service->roomLanguages($this->schedule));
    }

    public function test_turning_auto_translate_off_removes_the_reader_from_the_room(): void
    {
        $foreigner = $this->traveller(['locale' => 'en']);
        $service = app(ChatTranslationService::class);
        $this->assertSame(['en', 'th'], $service->roomLanguages($this->schedule));

        $this->actingAs($foreigner, 'sanctum')
            ->putJson('/api/v1/me/chat-translation', ['lang' => 'en', 'auto' => false])
            ->assertOk()
            ->assertJsonPath('data.auto', false);

        Http::fake();
        $this->send($this->staff, 'พรุ่งนี้รถออกตีห้านะครับ');

        $this->assertSame(['th'], $service->roomLanguages($this->schedule));
        Http::assertNothingSent();
    }

    public function test_room_payload_tells_the_app_its_reading_language(): void
    {
        $foreigner = $this->traveller(['locale' => 'en']);

        $this->actingAs($foreigner, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/room")
            ->assertOk()
            ->assertJsonPath('data.auto_translate.available', true)
            ->assertJsonPath('data.auto_translate.enabled', true)
            ->assertJsonPath('data.auto_translate.lang', 'en')
            ->assertJsonPath('data.auto_translate.chosen', false);

        $foreigner->forceFill(['chat_translate_lang' => 'zh'])->save();

        $this->actingAs($foreigner, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/room")
            ->assertJsonPath('data.auto_translate.lang', 'zh')
            ->assertJsonPath('data.auto_translate.chosen', true);
    }

    public function test_an_edit_is_translated_again_and_the_old_translation_is_not_shown(): void
    {
        $this->fakeTranslations($this->answer('Leaving at 5.'), $this->answer('Moved to 6.'));
        $foreigner = $this->traveller(['locale' => 'en']);
        $id = $this->send($this->staff, 'ออกตีห้านะครับ');

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/schedules/{$this->schedule->id}/chat/messages/{$id}", ['body' => 'เลื่อนเป็นหกโมงนะครับ'])
            ->assertOk();

        Http::assertSentCount(2);
        $this->assertSame('Moved to 6.', $this->listed($foreigner, $id)['translations']['en']['text']);
    }

    public function test_daily_room_budget_stops_auto_translation(): void
    {
        config(['services.anthropic.chat_auto_translate_daily_per_room' => 1]);
        $this->fakeTranslations($this->answer('One.'), $this->answer('Two.'));
        $this->traveller(['locale' => 'en']);

        $this->send($this->staff, 'ข้อความแรก');
        $second = $this->send($this->staff, 'ข้อความที่สอง');

        Http::assertSentCount(1);
        $this->assertFalse(ChatMessageTranslation::where('message_id', $second)->exists());
    }

    public function test_backlog_returns_stored_translations_and_queues_the_missing_ones(): void
    {
        Bus::fake([TranslateChatBacklogJob::class]);
        $foreigner = $this->traveller(['locale' => 'en']);
        $make = fn (string $body) => ChatMessage::create([
            'schedule_id' => $this->schedule->id, 'user_id' => $this->staff->id,
            'sender_role' => 'staff', 'body' => $body,
        ]);
        $done = $make('ถึงแล้วครับ');
        ChatMessageTranslation::create([
            'message_id' => $done->id, 'lang' => 'en', 'body_hash' => sha1('ถึงแล้วครับ'),
            'text' => 'We are here.', 'source_lang' => 'th', 'same_language' => false,
        ]);
        $missing = $make('รอที่ล็อบบี้นะครับ');
        $english = $make('Thanks!');

        $this->actingAs($foreigner, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/translations", [
                'lang' => 'en',
                'message_ids' => [$done->id, $missing->id, $english->id],
            ])
            ->assertOk()
            ->assertJsonPath("data.translations.{$done->id}.text", 'We are here.')
            ->assertJsonPath('data.pending', [$missing->id]);

        Bus::assertDispatched(TranslateChatBacklogJob::class, fn ($job) => $job->lang === 'en' && $job->messageIds === [$missing->id]);

        // ถามซ้ำระหว่างงานยังไม่เสร็จ — ไม่เข้าคิวซ้ำ
        $this->actingAs($foreigner, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/translations", [
                'lang' => 'en', 'message_ids' => [$missing->id],
            ])
            ->assertJsonPath('data.pending', []);
        Bus::assertDispatchedTimes(TranslateChatBacklogJob::class, 1);
    }

    public function test_backlog_is_only_for_room_members(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/schedules/{$this->schedule->id}/chat/translations", ['lang' => 'en', 'message_ids' => [1]])
            ->assertForbidden();
    }

    public function test_script_detection(): void
    {
        $this->assertSame('th', ChatTranslationService::scriptOf('รถตู้ arrive 8 โมงนะ'));
        $this->assertSame('latin', ChatTranslationService::scriptOf('See you @สมชาย https://example.com/ที่'));
        $this->assertSame('zh', ChatTranslationService::scriptOf('明天几点出发？'));
        $this->assertSame('other', ChatTranslationService::scriptOf('ありがとう'));
        $this->assertSame('none', ChatTranslationService::scriptOf('👍 555 08:30'));

        $this->assertFalse(ChatTranslationService::needsTranslation('Thanks!', 'en'));
        $this->assertTrue(ChatTranslationService::needsTranslation('Thanks!', 'th'));
        $this->assertTrue(ChatTranslationService::needsTranslation('ありがとう', 'zh'));
    }
}
