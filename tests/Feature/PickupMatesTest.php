<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "เพื่อนรอรถจุดเดียวกัน" — คนที่ถึงจุดรับตอนตีสี่ต้องรู้ได้ว่าไม่ได้รอคนเดียว
 * โดยไม่ต้องพิมพ์ถามในกลุ่ม และโดยไม่เปิดเผยคนที่ขอไม่ให้แสดง
 */
class PickupMatesTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private SchedulePickupPoint $mochit;

    private SchedulePickupPoint $rangsit;

    protected function setUp(): void
    {
        parent::setUp();

        $trip = Trip::create([
            'title' => 'Pickup Mates Trip',
            'slug' => 'pickup-mates-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 12,
            // ≥ 8 ไม่งั้นรอบถูกระงับข้อความเตรียมเดินทาง
            'booked_seats' => 8,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $this->mochit = $this->point('จุดขึ้นรถหมอชิต', 1, notes: 'ยืนรอหน้า 7-11 ทางออก 3');
        $this->rangsit = $this->point('ฟิวเจอร์รังสิต', 2);
    }

    public function test_traveller_sees_who_else_waits_at_the_same_point(): void
    {
        [$mine, $me] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี']]);
        [$friend] = $this->booking($this->mochit, [
            ['name' => 'นางสาว สุดา แสงทอง', 'nickname' => 'นุ่น'],
            ['name' => 'ปิติ มีสุข'],
        ], status: 'arrived', note: 'เสื้อเหลือง');
        $this->booking($this->rangsit, [['name' => 'คนละจุด ไกลกัน']], status: 'arrived');

        $response = $this->actingAs($me, 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->assertOk()
            ->assertJsonPath('data.visible', true)
            ->assertJsonCount(1, 'data.points');

        $point = $response->json('data.points.0');
        $this->assertSame($this->mochit->id, $point['id']);
        $this->assertSame('ยืนรอหน้า 7-11 ทางออก 3', $point['notes']);
        $this->assertSame(2, $point['people']);
        $this->assertSame(2, $point['arrived_people']);
        $this->assertTrue($point['me']['is_mine']);
        $this->assertSame('สมชาย ใ.', $point['me']['name']);

        $this->assertCount(1, $point['mates']);
        $mate = $point['mates'][0];
        $this->assertSame('นุ่น, ปิติ ม.', $mate['name']);
        $this->assertSame(2, $mate['people']);
        $this->assertSame('arrived', $mate['status']);
        $this->assertSame('เสื้อเหลือง', $mate['note']);

        // ไม่มีอะไรที่ระบุตัวได้เกินชื่อ
        $this->assertArrayNotHasKey('phone', $mate);
        $this->assertStringNotContainsString('แสงทอง', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($friend);
    }

    public function test_hidden_bookings_are_left_out_entirely(): void
    {
        [$mine, $me] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี']]);
        [$shy] = $this->booking($this->mochit, [['name' => 'ขี้อาย มาก']], status: 'arrived');
        $shy->update(['pickup_mates_hidden' => true]);

        $point = $this->actingAs($me, 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->assertOk()
            ->json('data.points.0');

        $this->assertSame([], $point['mates']);
        $this->assertSame(0, $point['people']);
        $this->assertSame(0, $point['arrived_people']);
    }

    public function test_stranger_cannot_read_a_booking_they_are_not_on(): void
    {
        [$mine] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี']]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->assertNotFound();
    }

    public function test_linked_member_sees_the_card_for_the_booking_they_joined(): void
    {
        [$mine] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี'], ['name' => 'เพื่อน รักกัน']]);
        $member = User::factory()->create();
        BookingMember::create([
            'booking_id' => $mine->id,
            'user_id' => $member->id,
            'passenger_id' => $mine->passengers()->skip(1)->first()->id,
            'role' => 'member',
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->assertOk()
            ->assertJsonPath('data.visible', true);
    }

    public function test_card_is_hidden_outside_the_waiting_window(): void
    {
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->addDays(5)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(6)->toDateString(),
        ]);
        [$mine, $me] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี']]);

        $this->actingAs($me, 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->assertOk()
            ->assertJsonPath('data.visible', false);
    }

    public function test_underfilled_round_hides_the_card_the_night_before_but_not_on_the_day(): void
    {
        $this->schedule->update([
            'booked_seats' => 3,
            'departure_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(2)->toDateString(),
        ]);
        [$mine, $me] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี']]);

        $this->actingAs($me, 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->assertJsonPath('data.visible', false);

        // วันเดินทางจริง: รอบที่ไม่ถูกยกเลิกคือไปแน่
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
        ]);

        $this->actingAs($me, 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->assertJsonPath('data.visible', true);
    }

    public function test_custom_pin_and_fully_boarded_bookings_have_no_card(): void
    {
        [$pinned, $pinUser] = $this->booking($this->mochit, [['name' => 'ปักหมุด เอง']]);
        // ปักหมุดเอง = ไม่มีจุดรับตายตัว (จุดตายตัวชนะหมุดเสมอ)
        $pinned->update(['pickup_point_id' => null, 'custom_pickup_label' => 'หน้าบ้าน', 'custom_pickup_lat' => 13.8, 'custom_pickup_lng' => 100.5]);
        $pinned->passengers()->update(['pickup_point_id' => null]);

        $this->actingAs($pinUser, 'sanctum')
            ->getJson("/api/v1/bookings/{$pinned->booking_ref}/pickup-mates")
            ->assertJsonPath('data.visible', false);

        [$boarded, $boardedUser] = $this->booking($this->mochit, [['name' => 'ขึ้นรถ แล้ว']]);
        $boarded->passengers()->update(['checked_in_at' => now()]);

        $this->actingAs($boardedUser, 'sanctum')
            ->getJson("/api/v1/bookings/{$boarded->booking_ref}/pickup-mates")
            ->assertJsonPath('data.visible', false);
    }

    public function test_first_arrival_posts_a_count_only_chat_card_and_pings_people_on_the_way(): void
    {
        [$first, $firstUser] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี', 'nickname' => 'ชาย']]);
        [$moving, $movingUser] = $this->booking($this->mochit, [['name' => 'กำลัง มา']], status: 'on_the_way');
        [, $asleepUser] = $this->booking($this->mochit, [['name' => 'ยัง หลับ']]);
        [, $otherPointUser] = $this->booking($this->rangsit, [['name' => 'คนละ จุด']], status: 'on_the_way');

        $this->actingAs($firstUser, 'sanctum')
            ->putJson("/api/v1/bookings/{$first->booking_ref}/pickup-mates/me", ['note' => 'เสื้อแดง หน้าประตู 2'])
            ->assertOk();

        $this->actingAs($firstUser, 'sanctum')
            ->postJson("/api/v1/bookings/{$first->booking_ref}/pickup-status", ['status' => 'arrived'])
            ->assertOk();

        $card = ChatMessage::where('schedule_id', $this->schedule->id)
            ->where('system_key', "pickup_mates_{$this->mochit->id}")
            ->first();
        $this->assertNotNull($card);
        $this->assertStringContainsString('จุดขึ้นรถหมอชิต', $card->body);
        $this->assertStringContainsString('1 คน จาก 3 คน', $card->body);
        // ทั้งรอบเห็นการ์ดนี้ — ไม่มีชื่อ
        $this->assertStringNotContainsString('ชาย', $card->body);

        $pushes = SmartNotification::where('type', 'pickup_mate_arrived')->get();
        $this->assertSame([$movingUser->id], $pushes->pluck('user_id')->all());
        $this->assertSame('ชาย ถึงจุดขึ้นรถหมอชิตแล้ว · เสื้อแดง หน้าประตู 2', $pushes->first()->body);
        $this->assertSame($moving->booking_ref, $pushes->first()->data['booking_ref']);
        $this->assertSame('trip_day', $pushes->first()->data['route']);
        $this->assertNotContains($asleepUser->id, $pushes->pluck('user_id'));
        $this->assertNotContains($otherPointUser->id, $pushes->pluck('user_id'));

        // คนที่สองถึง: การ์ดอัปเดตที่เดิม ไม่มี push ซ้ำ
        $this->actingAs($movingUser, 'sanctum')
            ->postJson("/api/v1/bookings/{$moving->booking_ref}/pickup-status", ['status' => 'arrived'])
            ->assertOk();

        $this->assertSame(1, ChatMessage::where('system_key', "pickup_mates_{$this->mochit->id}")->count());
        $this->assertStringContainsString('2 คน จาก 3 คน', $card->fresh()->body);
        $this->assertSame(1, SmartNotification::where('type', 'pickup_mate_arrived')->count());
    }

    public function test_hidden_arrival_neither_pings_nor_shows_in_the_chat_card(): void
    {
        [$shy, $shyUser] = $this->booking($this->mochit, [['name' => 'ขี้อาย มาก']]);
        $this->booking($this->mochit, [['name' => 'กำลัง มา']], status: 'on_the_way');

        $this->actingAs($shyUser, 'sanctum')
            ->putJson("/api/v1/bookings/{$shy->booking_ref}/pickup-mates/me", ['hidden' => true])
            ->assertOk()
            ->assertJsonPath('data.hidden', true);

        $this->actingAs($shyUser, 'sanctum')
            ->postJson("/api/v1/bookings/{$shy->booking_ref}/pickup-status", ['status' => 'arrived'])
            ->assertOk();

        $this->assertSame(0, SmartNotification::where('type', 'pickup_mate_arrived')->count());
        $this->assertSame(0, ChatMessage::where('system_key', "pickup_mates_{$this->mochit->id}")->count());
    }

    public function test_no_card_or_ping_once_the_van_is_already_there(): void
    {
        $this->mochit->update(['arrived_at' => now()]);
        [$first, $firstUser] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี']]);
        $this->booking($this->mochit, [['name' => 'กำลัง มา']], status: 'on_the_way');

        $this->actingAs($firstUser, 'sanctum')
            ->postJson("/api/v1/bookings/{$first->booking_ref}/pickup-status", ['status' => 'arrived'])
            ->assertOk();

        $this->assertSame(0, SmartNotification::where('type', 'pickup_mate_arrived')->count());
        $this->assertSame(0, ChatMessage::where('system_key', "pickup_mates_{$this->mochit->id}")->count());
    }

    public function test_note_is_trimmed_cleared_and_expires(): void
    {
        [$mine, $me] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี']]);

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates/me", ['note' => '  หมวกเขียว  '])
            ->assertOk()
            ->assertJsonPath('data.my_note', 'หมวกเขียว');

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates/me", ['note' => str_repeat('ก', 81)])
            ->assertUnprocessable();

        $mine->update(['pickup_note_at' => now()->subHours(Booking::PICKUP_STATUS_TTL_HOURS + 1)]);

        $this->actingAs($me, 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->assertJsonPath('data.my_note', null);

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates/me", ['note' => ''])
            ->assertOk();

        $this->assertNull($mine->fresh()->pickup_note);
        $this->assertNull($mine->fresh()->pickup_note_at);
    }

    public function test_avatar_is_shown_only_when_the_person_is_known(): void
    {
        $solo = User::factory()->create(['avatar' => 'avatars/solo.jpg']);
        [$mine, $me] = $this->booking($this->mochit, [['name' => 'สมชาย ใจดี']]);
        $this->booking($this->mochit, [['name' => 'เดี่ยว คนเดียว']], user: $solo);
        $this->booking($this->mochit, [['name' => 'หนึ่ง กลุ่ม'], ['name' => 'สอง กลุ่ม']], user: User::factory()->create(['avatar' => 'avatars/group.jpg']));

        $mates = collect($this->actingAs($me, 'sanctum')
            ->getJson("/api/v1/bookings/{$mine->booking_ref}/pickup-mates")
            ->json('data.points.0.mates'))->keyBy('name');

        $this->assertNotNull($mates['เดี่ยว ค.']['avatar_url']);
        // สองคนในใบเดียว — รูปของคนจองอาจไม่ใช่หน้าของคนที่ยืนอยู่
        $this->assertNull($mates['หนึ่ง ก., สอง ก.']['avatar_url']);
    }

    private function point(string $location, int $order, ?string $notes = null): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $this->schedule->id,
            'region' => 'bangkok-'.$order,
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => $location,
            'notes' => $notes,
            'price' => 0,
            'sort_order' => $order,
        ]);
    }

    /**
     * @param  array<int, array{name: string, nickname?: string}>  $passengers
     * @return array{0: Booking, 1: User}
     */
    private function booking(
        SchedulePickupPoint $point,
        array $passengers,
        ?string $status = null,
        ?string $note = null,
        ?User $user = null,
    ): array {
        $user ??= User::factory()->create();

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'pickup_point_id' => $point->id,
            'total_amount' => 1500,
            'paid_amount' => 1500,
            'pickup_status' => $status,
            'pickup_status_at' => $status ? now() : null,
            'pickup_note' => $note,
            'pickup_note_at' => $note ? now() : null,
        ]);

        foreach ($passengers as $p) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => $p['name'],
                'nickname' => $p['nickname'] ?? null,
                'phone' => '0800000000',
                'pickup_point_id' => $point->id,
            ]);
        }

        return [$booking->fresh(), $user];
    }
}
