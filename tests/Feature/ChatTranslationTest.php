<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ChatMessageTranslation;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config(['services.anthropic.key' => 'test-key']);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Translate Trip',
            'slug' => 'translate-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function member(TripSchedule $schedule): User
    {
        $user = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'Passenger',
            'phone' => '0812345678',
        ]);

        return $user;
    }

    private function message(TripSchedule $schedule, User $author, array $attrs = []): ChatMessage
    {
        return ChatMessage::create(array_merge([
            'schedule_id' => $schedule->id,
            'user_id' => $author->id,
            'sender_role' => 'staff',
            'body' => 'พรุ่งนี้รถตู้ออกตีห้า เจอกันที่จุดนัดรวมพลนะครับ',
        ], $attrs));
    }

    private function answer(string $text, string $source = 'th', bool $same = false): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'text', 'text' => json_encode([
                    'translation' => $text,
                    'source_lang' => $source,
                    'same_language' => $same,
                ], JSON_UNESCAPED_UNICODE)],
            ],
        ];
    }

    private function fakeTranslation(string $text, string $source = 'th', bool $same = false): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->answer($text, $source, $same))]);
    }

    /** คำตอบหลายครั้งตามลำดับ — Http::fake ซ้อนกันจะได้ตัวแรกทุกครั้ง */
    private function fakeTranslations(string ...$texts): void
    {
        $sequence = Http::sequence();
        foreach ($texts as $text) {
            $sequence->push($this->answer($text));
        }
        Http::fake(['api.anthropic.com/*' => $sequence]);
    }

    private function url(ChatMessage $message): string
    {
        return "/api/v1/schedules/{$message->schedule_id}/chat/messages/{$message->id}/translate";
    }

    public function test_member_translates_a_message_into_the_language_they_ask_for(): void
    {
        $schedule = $this->makeSchedule();
        $staff = User::factory()->create();
        $reader = $this->member($schedule);
        $message = $this->message($schedule, $staff);
        $this->fakeTranslation('The van leaves at 5 a.m. tomorrow — see you at the meeting point.');

        $this->actingAs($reader, 'sanctum')
            ->postJson($this->url($message), ['lang' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.message_id', $message->id)
            ->assertJsonPath('data.lang', 'en')
            ->assertJsonPath('data.text', 'The van leaves at 5 a.m. tomorrow — see you at the meeting point.')
            ->assertJsonPath('data.source_lang', 'th')
            ->assertJsonPath('data.same_language', false)
            ->assertJsonPath('data.cached', false);

        Http::assertSent(function (HttpRequest $request) use ($message) {
            return $request['model'] === 'claude-haiku-4-5'
                && $request['messages'][0]['content'] === $message->body
                && str_contains($request['system'][0]['text'], 'English')
                // Haiku 4.5 ตอบ 400 ถ้าส่ง effort ไป
                && ! isset($request['output_config']['effort']);
        });
    }

    public function test_second_reader_gets_the_stored_translation_without_calling_the_model(): void
    {
        $schedule = $this->makeSchedule();
        $message = $this->message($schedule, User::factory()->create());
        $this->fakeTranslation('明天早上五点面包车出发。');

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'zh'])
            ->assertOk();

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'zh'])
            ->assertOk()
            ->assertJsonPath('data.text', '明天早上五点面包车出发。')
            ->assertJsonPath('data.cached', true);

        Http::assertSentCount(1);
        $this->assertSame(1, ChatMessageTranslation::count());
    }

    public function test_each_language_is_stored_separately(): void
    {
        $schedule = $this->makeSchedule();
        $reader = $this->member($schedule);
        $message = $this->message($schedule, User::factory()->create());

        $this->fakeTranslations('Van leaves at 5.', '五点出发。');
        $this->actingAs($reader, 'sanctum')->postJson($this->url($message), ['lang' => 'en'])->assertOk();

        $this->actingAs($reader, 'sanctum')
            ->postJson($this->url($message), ['lang' => 'zh'])
            ->assertOk()
            ->assertJsonPath('data.text', '五点出发。');

        $this->assertEqualsCanonicalizing(
            ['en', 'zh'],
            ChatMessageTranslation::where('message_id', $message->id)->pluck('lang')->all(),
        );
    }

    public function test_an_edited_message_is_translated_again(): void
    {
        $schedule = $this->makeSchedule();
        $reader = $this->member($schedule);
        $message = $this->message($schedule, User::factory()->create());

        $this->fakeTranslations('Van leaves at 5.', 'Moved to 6 a.m.');
        $this->actingAs($reader, 'sanctum')->postJson($this->url($message), ['lang' => 'en'])->assertOk();

        $message->update(['body' => 'เลื่อนเป็นหกโมงเช้านะครับ', 'edited_at' => now()]);

        $this->actingAs($reader, 'sanctum')
            ->postJson($this->url($message), ['lang' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.text', 'Moved to 6 a.m.')
            ->assertJsonPath('data.cached', false);

        $this->assertSame(1, ChatMessageTranslation::where('message_id', $message->id)->count());
    }

    public function test_message_already_in_the_target_language_comes_back_unchanged(): void
    {
        $schedule = $this->makeSchedule();
        $message = $this->message($schedule, User::factory()->create());
        $this->fakeTranslation('', 'th', same: true);

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'th'])
            ->assertOk()
            ->assertJsonPath('data.same_language', true)
            ->assertJsonPath('data.text', $message->body);
    }

    public function test_outsider_cannot_translate_a_room_they_are_not_in(): void
    {
        $schedule = $this->makeSchedule();
        $message = $this->message($schedule, User::factory()->create());
        Http::fake();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'en'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_unsupported_language_is_rejected(): void
    {
        $schedule = $this->makeSchedule();
        $message = $this->message($schedule, User::factory()->create());
        Http::fake();

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'fr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lang');

        Http::assertNothingSent();
    }

    public function test_system_sticker_and_deleted_messages_are_not_sent_to_the_model(): void
    {
        $schedule = $this->makeSchedule();
        $reader = $this->member($schedule);
        $author = User::factory()->create();
        Http::fake();

        $system = $this->message($schedule, $author, ['sender_role' => 'system', 'system_key' => 'staff_joined']);
        $deleted = $this->message($schedule, $author, ['is_deleted' => true]);

        foreach ([$system, $deleted] as $message) {
            $this->actingAs($reader, 'sanctum')
                ->postJson($this->url($message), ['lang' => 'en'])
                ->assertStatus(422)
                ->assertJsonPath('message', 'ข้อความนี้แปลไม่ได้');
        }

        Http::assertNothingSent();
    }

    public function test_model_failure_shows_a_retry_message_and_stores_nothing(): void
    {
        $schedule = $this->makeSchedule();
        $message = $this->message($schedule, User::factory()->create());
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'แปลไม่สำเร็จ ลองใหม่อีกครั้งในสักครู่')
            ->assertDontSee('overloaded');

        $this->assertSame(0, ChatMessageTranslation::count());
    }

    public function test_without_an_api_key_nothing_is_sent(): void
    {
        config(['services.anthropic.key' => null]);
        $schedule = $this->makeSchedule();
        $message = $this->message($schedule, User::factory()->create());
        Http::fake();

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ระบบแปลภาษายังไม่พร้อมใช้งาน');

        Http::assertNothingSent();
    }

    public function test_deleting_a_message_removes_its_translations(): void
    {
        $schedule = $this->makeSchedule();
        $message = $this->message($schedule, User::factory()->create());
        $this->fakeTranslation('Van leaves at 5.');

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'en'])
            ->assertOk();

        app(ChatService::class)->deleteMessage($message);

        $this->assertSame(0, ChatMessageTranslation::count());
    }

    public function test_rate_limited_per_user_per_minute(): void
    {
        $schedule = $this->makeSchedule();
        $reader = $this->member($schedule);
        $message = $this->message($schedule, User::factory()->create());
        $this->fakeTranslation('Van leaves at 5.');

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($reader, 'sanctum')
                ->postJson($this->url($message), ['lang' => 'en'])
                ->assertOk();
        }

        $this->actingAs($reader, 'sanctum')
            ->postJson($this->url($message), ['lang' => 'en'])
            ->assertStatus(429);

        // คนอื่นในห้องยังกดได้ — นับแยกต่อคน
        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->url($message), ['lang' => 'en'])
            ->assertOk();
    }
}
