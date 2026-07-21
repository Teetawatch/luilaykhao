<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripConciergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('concierge-catalog');
        config(['services.anthropic.key' => 'test-key']);
    }

    private function makeTrip(array $overrides = [], bool $withOpenRound = true): Trip
    {
        $trip = Trip::create(array_merge([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng',
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'northeast',
            'difficulty' => 'medium',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 3900,
            'status' => 'active',
        ], $overrides));

        if ($withOpenRound) {
            TripSchedule::create([
                'trip_id' => $trip->id,
                'departure_date' => now('Asia/Bangkok')->addDays(14)->toDateString(),
                'return_date' => now('Asia/Bangkok')->addDays(16)->toDateString(),
                'total_seats' => 20,
                'booked_seats' => 5,
                'transport_type' => 'van',
                'status' => 'open',
            ]);
        }

        return $trip;
    }

    /** ปลอมคำตอบของโมเดลในรูปแบบเดียวกับที่ structured output คืนมาจริง */
    private function fakeAnswer(string $reply, array $slugs, string $stopReason = 'end_turn'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => $stopReason,
                'content' => [
                    ['type' => 'thinking', 'thinking' => ''],
                    ['type' => 'text', 'text' => json_encode([
                        'reply' => $reply,
                        'trip_slugs' => $slugs,
                    ], JSON_UNESCAPED_UNICODE)],
                ],
            ]),
        ]);
    }

    public function test_answers_with_the_trips_the_model_picked(): void
    {
        $this->makeTrip();
        $this->fakeAnswer('ภูกระดึงเหมาะกับงบนี้ครับ', ['phu-kradueng']);

        $this->postJson('/api/v1/concierge', ['message' => 'งบ 4000 ไปไหนดี'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'ภูกระดึงเหมาะกับงบนี้ครับ')
            ->assertJsonPath('data.trips.0.slug', 'phu-kradueng')
            ->assertJsonPath('data.trips.0.price_from', 3900);
    }

    public function test_catalog_sent_to_the_model_only_holds_bookable_trips(): void
    {
        $this->makeTrip();
        $this->makeTrip(['title' => 'ไม่มีรอบเปิด', 'slug' => 'no-rounds'], withOpenRound: false);
        $this->makeTrip(['title' => 'ปิดอยู่', 'slug' => 'inactive-trip', 'status' => 'inactive']);

        $this->fakeAnswer('ครับ', []);

        $this->postJson('/api/v1/concierge', ['message' => 'มีทริปอะไรบ้าง'])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request['system'][0]['text'];

            return str_contains($system, 'phu-kradueng')
                && ! str_contains($system, 'no-rounds')
                && ! str_contains($system, 'inactive-trip');
        });
    }

    public function test_invented_trip_slugs_are_dropped(): void
    {
        $this->makeTrip();
        // โมเดลแต่งทริปที่ไม่มีจริงขึ้นมา — ต้องไม่หลุดไปถึงหน้าเว็บ
        $this->fakeAnswer('แนะนำสองทริปนี้ครับ', ['phu-kradueng', 'doi-that-does-not-exist']);

        $response = $this->postJson('/api/v1/concierge', ['message' => 'แนะนำหน่อย'])->assertOk();

        $this->assertCount(1, $response->json('data.trips'));
        $response->assertJsonPath('data.trips.0.slug', 'phu-kradueng');
    }

    public function test_a_refusal_becomes_a_readable_error(): void
    {
        $this->makeTrip();
        $this->fakeAnswer('', [], stopReason: 'refusal');

        $this->postJson('/api/v1/concierge', ['message' => 'คำถามที่ถูกปฏิเสธ'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_api_failure_does_not_leak_upstream_details(): void
    {
        $this->makeTrip();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->postJson('/api/v1/concierge', ['message' => 'แนะนำหน่อย'])
            ->assertStatus(422)
            ->assertJsonPath('data', null)
            ->assertDontSee('overloaded');
    }

    public function test_no_bookable_trips_answers_without_calling_the_model(): void
    {
        Http::fake();

        $this->postJson('/api/v1/concierge', ['message' => 'แนะนำหน่อย'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_missing_api_key_does_not_reach_the_model(): void
    {
        config(['services.anthropic.key' => null]);
        $this->makeTrip();
        Http::fake();

        $this->postJson('/api/v1/concierge', ['message' => 'แนะนำหน่อย'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_message_is_required_and_bounded(): void
    {
        Http::fake();

        $this->postJson('/api/v1/concierge', [])->assertStatus(422);
        $this->postJson('/api/v1/concierge', ['message' => str_repeat('ก', 501)])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_haiku_request_omits_the_params_it_would_reject(): void
    {
        config(['services.anthropic.concierge_model' => 'claude-haiku-4-5']);
        $this->makeTrip();
        $this->fakeAnswer('ครับ', []);

        $this->postJson('/api/v1/concierge', ['message' => 'แนะนำหน่อย'])->assertOk();

        Http::assertSent(function ($request) {
            // effort และ thinking ทำให้ haiku ตอบ 400 — ต้องไม่หลุดไปทั้งคู่
            return $request['model'] === 'claude-haiku-4-5'
                && ! isset($request['thinking'])
                && ! isset($request['output_config']['effort'])
                && isset($request['output_config']['format']);
        });
    }

    public function test_effort_is_sent_only_to_models_that_accept_it(): void
    {
        $this->makeTrip();
        $this->fakeAnswer('ครับ', []);

        foreach (['claude-sonnet-5' => true, 'claude-opus-4-8' => true, 'claude-haiku-4-5-20251001' => false] as $model => $expected) {
            config(['services.anthropic.concierge_model' => $model]);
            // fake ใหม่ทุกรอบ ไม่งั้น request ของรุ่นก่อนหน้าจะทำให้ assertSent
            // เป็นจริงแบบเปล่า ๆ (assertSent ผ่านถ้ามี request ใดก็ได้ที่ตรง)
            $this->fakeAnswer('ครับ', []);

            $this->postJson('/api/v1/concierge', ['message' => 'แนะนำหน่อย'])->assertOk();

            Http::assertSent(fn ($request) => $request['model'] === $model
                && isset($request['output_config']['effort']) === $expected);
            Http::assertSentCount(1);
        }
    }

    public function test_conversation_history_is_forwarded_to_the_model(): void
    {
        $this->makeTrip();
        $this->fakeAnswer('ครับ', []);

        $this->postJson('/api/v1/concierge', [
            'message' => 'แล้วมีรอบอื่นไหม',
            'history' => [
                ['role' => 'user', 'content' => 'อยากไปเลย'],
                ['role' => 'assistant', 'content' => 'แนะนำภูกระดึงครับ'],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $messages = $request['messages'];

            return count($messages) === 3
                && $messages[0]['content'] === 'อยากไปเลย'
                && $messages[2]['role'] === 'user'
                && $messages[2]['content'] === 'แล้วมีรอบอื่นไหม';
        });
    }
}
