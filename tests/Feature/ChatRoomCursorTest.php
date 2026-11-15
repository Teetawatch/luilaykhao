<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\ChatRoomCursor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\InMemoryRedis;
use Tests\TestCase;

/**
 * การถามหาข้อความใหม่ของห้องแชท (after_id) ตอบ "ไม่มี" จาก Redis ได้
 *
 * สิ่งที่ต้องไม่เกิดเด็ดขาด: แอปพลาดข้อความ เพราะแคชบอกว่าไม่มีทั้งที่มี
 */
class ChatRoomCursorTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryRedis $redis;

    private TripSchedule $schedule;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::swap($this->redis = new InMemoryRedis);

        $trip = Trip::create([
            'title' => 'Chat Trip', 'slug' => 'cursor-'.uniqid(), 'type' => 'trekking', 'location' => 'x',
            'difficulty' => 'easy', 'duration_days' => 1, 'max_participants' => 10,
            'price_per_person' => 1500, 'status' => 'active',
        ]);
        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id, 'departure_date' => now()->addWeek()->toDateString(),
            'return_date' => now()->addWeek()->toDateString(), 'total_seats' => 10, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open',
        ]);
        $this->member = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(), 'user_id' => $this->member->id,
            'schedule_id' => $this->schedule->id, 'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 1500,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'P', 'phone' => '0812345678']);
    }

    private function message(?TripSchedule $schedule = null, string $body = 'สวัสดี'): ChatMessage
    {
        return ChatMessage::create([
            'schedule_id' => ($schedule ?? $this->schedule)->id,
            'user_id' => $this->member->id,
            'sender_role' => 'customer',
            'body' => $body,
        ]);
    }

    private function poll(int $afterId)
    {
        return $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/messages?after_id={$afterId}");
    }

    /** จำนวน query ที่แตะตาราง chat_messages ระหว่าง [callback] */
    private function messageQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'chat_messages'))->count();
        DB::disableQueryLog();

        return $count;
    }

    public function test_an_up_to_date_poll_is_answered_without_reading_messages(): void
    {
        $latest = $this->message();

        $queries = $this->messageQueries(fn () => $this->poll($latest->id)
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'สำเร็จ', 'data' => ['messages' => [], 'has_more' => false]]));

        $this->assertSame(0, $queries);
    }

    public function test_a_new_message_is_always_returned(): void
    {
        $first = $this->message(body: 'แรก');
        $this->poll($first->id)->assertJsonPath('data.messages', []);

        $second = $this->message(body: 'ใหม่');

        $this->poll($first->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.id', $second->id);
    }

    public function test_an_empty_cache_is_filled_from_the_table(): void
    {
        $first = $this->message(body: 'แรก');
        $latest = $this->message(body: 'ล่าสุด');
        $this->redis->data = [];

        // คีย์หายไป (หมดอายุ / Redis รีสตาร์ต) — ต้องไม่ตอบ "ไม่มี" มั่ว ๆ
        $this->poll($first->id)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.id', $latest->id);
        $this->assertTrue(ChatRoomCursor::nothingAfter($this->schedule->id, $latest->id));
        $this->poll($latest->id)->assertJsonPath('data.messages', []);
    }

    public function test_the_cursor_only_moves_up_even_when_writes_land_out_of_order(): void
    {
        $id = $this->schedule->id;

        ChatRoomCursor::bump($id, 50);
        ChatRoomCursor::bump($id, 40);   // เขียนมาทีหลังแต่ id ต่ำกว่า
        $this->assertFalse(ChatRoomCursor::nothingAfter($id, 45));
        $this->assertTrue(ChatRoomCursor::nothingAfter($id, 50));

        // เติมจากตารางด้วยค่าที่ต่ำกว่า (อ่านก่อนข้อความใหม่ commit) ต้องไม่กดค่าลง
        $this->assertFalse(ChatRoomCursor::nothingAfter($id, 49));
        $this->assertCount(1, $this->redis->data[ChatRoomCursor::key($id)]);
    }

    public function test_rooms_do_not_share_a_cursor(): void
    {
        $other = TripSchedule::create([
            'trip_id' => $this->schedule->trip_id, 'departure_date' => now()->addWeeks(2)->toDateString(),
            'return_date' => now()->addWeeks(2)->toDateString(), 'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $mine = $this->message();
        $this->message($other);

        $this->poll($mine->id)->assertJsonPath('data.messages', []);
    }

    public function test_when_redis_is_down_polls_still_return_new_messages(): void
    {
        $first = $this->message();
        $this->redis->down = true;
        $second = $this->message();

        $this->poll($first->id)->assertJsonPath('data.messages.0.id', $second->id);
        $this->poll($second->id)->assertOk()->assertJsonPath('data.messages', []);
    }

    public function test_a_stale_low_cursor_never_hides_messages_for_longer_than_its_ttl(): void
    {
        $first = $this->message();
        // จำลองการเขียนพลาดแวบเดียว: ข้อความใหม่เข้าตารางแต่ไม่ได้ขยับแคช
        $this->redis->down = true;
        $second = $this->message();
        $this->redis->down = false;

        $this->travel(ChatRoomCursor::TTL_SECONDS + 1)->seconds();

        $this->poll($first->id)->assertJsonPath('data.messages.0.id', $second->id);
    }

    public function test_outsiders_are_still_refused_before_the_cache_is_consulted(): void
    {
        $latest = $this->message();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/chat/messages?after_id={$latest->id}")
            ->assertForbidden();
    }
}
