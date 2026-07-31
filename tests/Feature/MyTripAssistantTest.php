<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MyTripAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => 'test-key']);
    }

    private function makeBooking(User $user, array $bookingOverrides = []): Booking
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'northeast',
            'difficulty' => 'medium',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 3900,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(5)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(7)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 5,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create(array_merge([
            'booking_ref' => 'LLK-20260801-'.strtoupper(substr(uniqid(), -4)),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 7800,
            'paid_amount' => 3900,
            'payment_type' => 'deposit',
            'balance_due_at' => now('Asia/Bangkok')->addDays(2)->toDateString(),
        ], $bookingOverrides));
    }

    /** ปลอมคำตอบของโมเดลในรูปแบบเดียวกับที่ structured output คืนมาจริง */
    private function fakeAnswer(string $reply, array $actions, string $stopReason = 'end_turn'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => $stopReason,
                'content' => [
                    ['type' => 'thinking', 'thinking' => ''],
                    ['type' => 'text', 'text' => json_encode([
                        'reply' => $reply,
                        'actions' => $actions,
                    ], JSON_UNESCAPED_UNICODE)],
                ],
            ]),
        ]);
    }

    public function test_requires_login(): void
    {
        $this->postJson('/api/v1/me/assistant', ['message' => 'รถออกกี่โมง'])
            ->assertStatus(401);
    }

    public function test_answers_with_the_actions_the_model_picked(): void
    {
        $user = User::factory()->create();
        $booking = $this->makeBooking($user);

        $this->fakeAnswer('ยังเหลือ 3,900 บาทครับ', [
            ['type' => 'payment', 'label' => 'จ่ายยอดคงเหลือ', 'booking_ref' => $booking->booking_ref],
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => 'ยอดคงเหลือเท่าไหร่'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'ยังเหลือ 3,900 บาทครับ')
            ->assertJsonPath('data.actions.0.type', 'payment')
            ->assertJsonPath('data.actions.0.booking_ref', $booking->booking_ref);
    }

    public function test_the_users_own_booking_is_sent_to_the_model(): void
    {
        $user = User::factory()->create();
        $booking = $this->makeBooking($user);

        $this->fakeAnswer('ครับ', []);

        $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => 'ทริปหน้าคือทริปอะไร'])
            ->assertOk();

        Http::assertSent(function ($request) use ($booking) {
            $system = $request['system'][0]['text'];

            return str_contains($system, $booking->booking_ref)
                && str_contains($system, 'ภูกระดึง')
                // ยอดค้าง 7800 - 3900 ถูกคำนวณให้แล้ว โมเดลไม่ต้องลบเอง
                && str_contains($system, '3900');
        });
    }

    public function test_other_peoples_bookings_never_reach_the_model(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();

        $mine = $this->makeBooking($me);
        $theirs = $this->makeBooking($someoneElse);

        $this->fakeAnswer('ครับ', []);

        $this->actingAs($me)
            ->postJson('/api/v1/me/assistant', ['message' => 'ฉันจองอะไรไว้บ้าง'])
            ->assertOk();

        Http::assertSent(function ($request) use ($mine, $theirs) {
            $system = $request['system'][0]['text'];

            return str_contains($system, $mine->booking_ref)
                && ! str_contains($system, $theirs->booking_ref);
        });
    }

    public function test_cancelled_bookings_are_left_out(): void
    {
        $user = User::factory()->create();
        $cancelled = $this->makeBooking($user, ['status' => 'cancelled']);

        $this->fakeAnswer('ครับ', []);

        $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => 'ฉันจองอะไรไว้บ้าง'])
            ->assertOk();

        Http::assertSent(fn ($request) => ! str_contains($request['system'][0]['text'], $cancelled->booking_ref));
    }

    public function test_invented_actions_and_booking_refs_are_dropped(): void
    {
        $user = User::factory()->create();
        $this->makeBooking($user);

        $this->fakeAnswer('ครับ', [
            ['type' => 'cancel_booking', 'label' => 'ยกเลิกให้เลย', 'booking_ref' => null],
            ['type' => 'payment', 'label' => 'จ่ายเงิน', 'booking_ref' => 'LLK-ไม่มีจริง'],
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => 'ยกเลิกได้ไหม'])
            ->assertOk();

        $actions = $response->json('data.actions');

        $this->assertCount(1, $actions, 'ปุ่มที่แอปไม่รู้จักต้องถูกตัดทิ้ง');
        $this->assertSame('payment', $actions[0]['type']);
        $this->assertNull($actions[0]['booking_ref'], 'เลขที่จองที่ไม่มีจริงต้องกลายเป็น null');
    }

    public function test_a_refusal_becomes_a_readable_error(): void
    {
        $user = User::factory()->create();
        $this->makeBooking($user);
        $this->fakeAnswer('', [], 'refusal');

        $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => 'คำถามที่ถูกปฏิเสธ'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_api_failure_does_not_leak_upstream_details(): void
    {
        $user = User::factory()->create();
        $this->makeBooking($user);

        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => 'รถออกกี่โมง'])
            ->assertStatus(422);

        $this->assertStringNotContainsString('overloaded', $response->json('message'));
    }

    public function test_missing_api_key_does_not_reach_the_model(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => 'รถออกกี่โมง'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_user_with_no_bookings_still_gets_an_answer(): void
    {
        $user = User::factory()->create();
        $this->fakeAnswer('ยังไม่พบการจองของคุณครับ', []);

        $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => 'ฉันมีทริปไหม'])
            ->assertOk()
            ->assertJsonPath('data.actions', []);

        Http::assertSent(fn ($request) => str_contains($request['system'][0]['text'], 'ยังไม่มีการจอง'));
    }

    public function test_message_is_required_and_bounded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/me/assistant', [])->assertStatus(422);
        $this->actingAs($user)
            ->postJson('/api/v1/me/assistant', ['message' => str_repeat('ก', 501)])
            ->assertStatus(422);
    }

    public function test_conversation_history_is_forwarded_to_the_model(): void
    {
        $user = User::factory()->create();
        $this->makeBooking($user);
        $this->fakeAnswer('ครับ', []);

        $this->actingAs($user)->postJson('/api/v1/me/assistant', [
            'message' => 'แล้วขึ้นรถที่ไหน',
            'history' => [
                ['role' => 'user', 'content' => 'รถออกกี่โมง'],
                ['role' => 'assistant', 'content' => 'ออก 20:00 น. ครับ'],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $messages = $request['messages'];

            return count($messages) === 3
                && $messages[0]['content'] === 'รถออกกี่โมง'
                && $messages[2]['content'] === 'แล้วขึ้นรถที่ไหน';
        });
    }

    public function test_suggestions_change_with_whether_a_trip_is_coming_up(): void
    {
        $withTrip = User::factory()->create();
        $this->makeBooking($withTrip);

        $this->actingAs($withTrip)
            ->getJson('/api/v1/me/assistant/suggestions')
            ->assertOk()
            ->assertJsonPath('data.suggestions.0', 'ทริปหน้าออกเดินทางกี่โมง');

        $withoutTrip = User::factory()->create();

        $this->actingAs($withoutTrip)
            ->getJson('/api/v1/me/assistant/suggestions')
            ->assertOk()
            ->assertJsonPath('data.suggestions.0', 'ฉันมีทริปที่จองไว้ไหม');
    }
}
