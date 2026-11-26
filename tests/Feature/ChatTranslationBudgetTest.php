<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ChatMessageTranslation;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatTranslationMeter;
use App\Services\ChatTranslationService;
use App\Services\OpsHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * งบค่าแปลแชท — มิเตอร์จาก usage จริง, เพดานรายเดือน 1,000 บาท (90% พักแปล
 * อัตโนมัติ, 100% พักปุ่มกดแปล) และการใช้คำแปลซ้ำของข้อความเดียวกันข้ามห้อง
 */
class ChatTranslationBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.chat_auto_translate' => true,
            'chat_translation.monthly_cap_thb' => 1000,
            'chat_translation.usd_thb' => 36,
        ]);
    }

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'เขาใหญ่', 'slug' => 'budget-'.uniqid(), 'type' => 'trekking', 'location' => 'นครราชสีมา',
            'difficulty' => 'easy', 'duration_days' => 1, 'max_participants' => 10, 'price_per_person' => 990, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addWeek()->toDateString(),
            'return_date' => now()->addWeek()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function member(TripSchedule $schedule): User
    {
        $user = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(), 'user_id' => $user->id, 'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(), 'status' => 'confirmed', 'total_amount' => 990,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'P']);

        return $user;
    }

    private function message(TripSchedule $schedule, string $body): ChatMessage
    {
        return ChatMessage::create([
            'schedule_id' => $schedule->id, 'user_id' => User::factory()->create()->id,
            'sender_role' => 'staff', 'body' => $body,
        ]);
    }

    private function fakeAnswer(string $text, int $in = 1000, int $out = 200): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-5-5',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
            'content' => [['type' => 'text', 'text' => json_encode([
                'translation' => $text, 'source_lang' => 'th', 'same_language' => false,
            ])]],
        ])]);
    }

    /** ใส่ยอดใช้เดือนนี้เป็นบาท (ผ่านตารางมิเตอร์ตรง ๆ) */
    private function spend(float $thb): void
    {
        $key = ['date' => now('Asia/Bangkok')->toDateString(), 'model' => 'claude-haiku-5-5'];
        $before = (float) DB::table('chat_translation_usage')->where($key)->value('cost_usd');
        DB::table('chat_translation_usage')->updateOrInsert($key, [
            'calls' => 1,
            'cost_usd' => $before + $thb / 36,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        cache()->flush();
    }

    private function translateUrl(ChatMessage $message): string
    {
        return "/api/v1/schedules/{$message->schedule_id}/chat/messages/{$message->id}/translate";
    }

    public function test_each_call_is_metered_from_the_real_usage(): void
    {
        $schedule = $this->schedule();
        $message = $this->message($schedule, 'รถออกตีห้า');
        $this->fakeAnswer('The van leaves at 5 a.m.', in: 1000, out: 200);

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->translateUrl($message), ['lang' => 'en'])
            ->assertOk();

        $row = DB::table('chat_translation_usage')->first();
        $this->assertSame(1, (int) $row->calls);
        $this->assertSame(1000, (int) $row->input_tokens);
        $this->assertSame(200, (int) $row->output_tokens);
        // 1000 × $0.10/M + 200 × $0.50/M = $0.0002 → 0.0072 บาท ที่ 36 บาท/ดอลลาร์
        $this->assertEqualsWithDelta(0.0002, (float) $row->cost_usd, 0.0000001);
        $this->assertEqualsWithDelta(0.0072, app(ChatTranslationMeter::class)->monthSpendThb(), 0.00001);
    }

    public function test_the_same_text_in_another_room_reuses_the_translation(): void
    {
        $this->fakeAnswer('Thank you!');
        $roomA = $this->schedule();
        $roomB = $this->schedule();
        $first = $this->message($roomA, 'ขอบคุณครับ');
        $second = $this->message($roomB, 'ขอบคุณครับ');

        $this->actingAs($this->member($roomA), 'sanctum')->postJson($this->translateUrl($first), ['lang' => 'en'])->assertOk();
        $this->actingAs($this->member($roomB), 'sanctum')
            ->postJson($this->translateUrl($second), ['lang' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.text', 'Thank you!');

        Http::assertSentCount(1);
        $this->assertSame(2, ChatMessageTranslation::count());
        $this->assertSame(1, (int) DB::table('chat_translation_usage')->sum('reused'));
    }

    public function test_auto_translation_pauses_at_90_percent_but_tapping_still_works(): void
    {
        $this->spend(905);
        $schedule = $this->schedule();
        $message = $this->message($schedule, 'เจอกันที่ล็อบบี้');
        $this->fakeAnswer('See you in the lobby.');

        $this->assertFalse(ChatTranslationService::autoEnabled());
        $this->assertSame([], app(ChatTranslationService::class)->autoTargets($message));

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->translateUrl($message), ['lang' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.text', 'See you in the lobby.');
    }

    public function test_everything_stops_at_the_monthly_cap(): void
    {
        $this->spend(1000);
        $schedule = $this->schedule();
        $message = $this->message($schedule, 'เจอกันที่ล็อบบี้');
        Http::fake();

        $this->actingAs($this->member($schedule), 'sanctum')
            ->postJson($this->translateUrl($message), ['lang' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ระบบแปลภาษาพักชั่วคราว ลองใหม่อีกครั้งเดือนหน้า');

        Http::assertNothingSent();
        $this->assertSame(1, (int) DB::table('chat_translation_usage')->sum('blocked'));
    }

    public function test_reused_translations_are_still_served_over_the_cap(): void
    {
        $roomA = $this->schedule();
        $done = $this->message($roomA, 'ขอบคุณครับ');
        ChatMessageTranslation::create([
            'message_id' => $done->id, 'lang' => 'en', 'body_hash' => sha1('ขอบคุณครับ'),
            'text' => 'Thank you!', 'source_lang' => 'th', 'same_language' => false,
        ]);
        $this->spend(1000);
        $roomB = $this->schedule();
        $again = $this->message($roomB, 'ขอบคุณครับ');
        Http::fake();

        $this->actingAs($this->member($roomB), 'sanctum')
            ->postJson($this->translateUrl($again), ['lang' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.text', 'Thank you!');
        Http::assertNothingSent();
    }

    public function test_unknown_models_are_priced_high_so_the_cap_never_runs_loose(): void
    {
        $meter = app(ChatTranslationMeter::class);
        $usage = ['input_tokens' => 1_000_000, 'output_tokens' => 0];

        $this->assertEqualsWithDelta(0.10, $meter->costUsd('claude-haiku-5-5', $usage), 0.000001);
        $this->assertEqualsWithDelta(1.00, $meter->costUsd('claude-haiku-4-5-20251001', $usage), 0.000001);
        $this->assertEqualsWithDelta(5.00, $meter->costUsd('some-future-model', $usage), 0.000001);
    }

    public function test_ops_check_fails_from_the_alert_percent_so_admins_get_mail(): void
    {
        $check = fn () => collect(app(OpsHealthService::class)->checks())->firstWhere('key', 'chat_translate_budget');

        $this->spend(400);
        $this->assertSame('ok', $check()['status']);

        $this->spend(420); // รวม 820 บาท = 82%
        $this->assertSame('fail', $check()['status']);
        $this->assertStringContainsString('820.00/1,000', $check()['detail']);
    }

    public function test_admin_sees_this_months_spend(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->spend(250);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/support/translation-usage')
            ->assertOk()
            ->assertJsonPath('data.spent_thb', 250)
            ->assertJsonPath('data.cap_thb', 1000)
            ->assertJsonPath('data.percent', 25)
            ->assertJsonPath('data.auto_paused', false);
    }

    public function test_check_command_translates_samples_and_meters_them(): void
    {
        $this->fakeAnswer('The van leaves at 5 a.m.', in: 500, out: 100);

        $this->artisan('chat-translate:check', ['--message' => ['รถออกตีห้า'], '--lang' => 'en'])
            ->expectsOutputToContain('The van leaves at 5 a.m.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, (int) DB::table('chat_translation_usage')->sum('calls'));
    }
}
