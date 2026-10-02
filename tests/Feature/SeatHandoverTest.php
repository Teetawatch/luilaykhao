<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\BookingSeat;
use App\Models\BookingSplitShare;
use App\Models\ChatMessage;
use App\Models\LiveActivity;
use App\Models\SeatHandover;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripMemberLocation;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeatHandoverTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_ID = '1101700230708';

    private User $owner;

    private User $friend;

    private TripSchedule $schedule;

    private Booking $booking;

    private BookingPassenger $ownerSeat;

    private BookingPassenger $friendSeat;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('staff', 'web');

        $this->owner = User::factory()->create(['name' => 'สมชาย ใจดี', 'phone' => '0811111111']);
        $this->friend = User::factory()->create(['name' => 'สมหญิง รักดี', 'nickname' => 'หญิง']);
        $this->schedule = $this->makeSchedule();
        [$this->booking, $this->ownerSeat, $this->friendSeat] = $this->makeBooking($this->owner, $this->schedule);

        BookingMember::create([
            'booking_id' => $this->booking->id,
            'user_id' => $this->friend->id,
            'passenger_id' => $this->friendSeat->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
            'invited_by' => $this->owner->id,
            'accepted_at' => now(),
        ]);
    }

    private function makeSchedule(array $scheduleOverrides = [], array $tripOverrides = []): TripSchedule
    {
        $trip = Trip::create(array_merge([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2000,
            'status' => 'active',
        ], $tripOverrides));

        // เคสจริง: ไปพรุ่งนี้ ยังไม่ได้ตั้งเวลารถออก
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(2)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ], $scheduleOverrides));
    }

    /** @return array{0: Booking, 1: BookingPassenger, 2: BookingPassenger} */
    private function makeBooking(User $owner, TripSchedule $schedule, array $overrides = [], string $row = 'A'): array
    {
        $booking = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 4000,
            'paid_amount' => 4000,
            'payment_type' => 'full',
        ], $overrides));

        $ownerSeat = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'สมชาย ใจดี',
            'phone' => '0811111111',
            'id_card' => '3101001234565',
            'allergies' => 'แพ้กุ้ง',
        ]);
        $friendSeat = BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'สมหญิง รักดี',
            'phone' => '0822222222',
            'id_card' => '1234567890121',
            'allergies' => 'แพ้ถั่ว',
            'health_notes' => 'หอบหืด',
        ]);

        BookingSeat::create(['booking_id' => $booking->id, 'schedule_id' => $schedule->id, 'seat_id' => $row.'1', 'passenger_name' => 'สมชาย ใจดี']);
        BookingSeat::create(['booking_id' => $booking->id, 'schedule_id' => $schedule->id, 'seat_id' => $row.'2', 'passenger_name' => 'สมหญิง รักดี']);

        return [$booking, $ownerSeat, $friendSeat];
    }

    private function claimPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'นาย',
            'name' => 'มานะ ขยันดี',
            'nickname' => 'มานะ',
            'nationality' => 'TH',
            'id_card' => self::VALID_ID,
            'birth_date' => '1995-05-05',
            'phone' => '0833333333',
            'email' => 'mana@example.com',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0844444444',
            'accept_terms' => true,
            'terms_version' => config('legal.terms_version'),
            'channel' => 'app',
        ], $overrides);
    }

    private function createLink(User $as, BookingPassenger $seat, array $extra = []): string
    {
        $response = $this->actingAs($as, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers", array_merge([
                'passenger_id' => $seat->id,
            ], $extra))
            ->assertCreated();

        return $response->json('data.token');
    }

    public function test_owner_hands_a_companion_seat_to_someone_new(): void
    {
        $recipient = User::factory()->create(['name' => 'มานะ ขยันดี']);
        $token = $this->createLink($this->owner, $this->friendSeat, ['note' => 'ไปแทนหญิงนะ']);

        $preview = $this->actingAs($recipient, 'sanctum')
            ->getJson("/api/v1/seat-handovers/{$token}")
            ->assertOk();
        $this->assertTrue($preview->json('data.claimable'));
        $this->assertSame('A2', $preview->json('data.seat_label'));
        $this->assertSame('ไปแทนหญิงนะ', $preview->json('data.note'));
        $this->assertSame('มานะ ขยันดี', $preview->json('data.prefill.name'));
        // คนรับไม่เห็นราคาหรือข้อมูลของคนเดิม
        $this->assertStringNotContainsString('4000', $preview->getContent());
        $this->assertStringNotContainsString('สมหญิง', $preview->getContent());

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $this->booking->booking_ref)
            ->assertJsonPath('data.transfers_ownership', false);

        $seat = $this->friendSeat->fresh();
        $this->assertSame('มานะ ขยันดี', $seat->name);
        $this->assertSame(self::VALID_ID, $seat->id_card);
        // ข้อมูลส่วนตัวของคนเดิมต้องไม่เหลือติดชื่อคนใหม่
        $this->assertNull($seat->allergies);
        $this->assertNull($seat->health_notes);
        $this->assertSame('มานะ ขยันดี', BookingSeat::where('seat_id', 'A2')->value('passenger_name'));

        $this->assertDatabaseMissing('booking_members', ['booking_id' => $this->booking->id, 'user_id' => $this->friend->id]);
        $this->assertDatabaseHas('booking_members', [
            'booking_id' => $this->booking->id,
            'user_id' => $recipient->id,
            'passenger_id' => $this->friendSeat->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);
        $this->assertSame($this->owner->id, $this->booking->fresh()->user_id);
        $this->assertTrue($this->booking->fresh()->isAccessibleByUser($recipient->id));
        $this->assertFalse($this->booking->fresh()->isAccessibleByUser($this->friend->id));

        $handover = SeatHandover::where('token', $token)->first();
        $this->assertSame(SeatHandover::STATUS_CLAIMED, $handover->status);
        $this->assertSame('สมหญิง รักดี', $handover->previous_name);
        $this->assertSame('มานะ ขยันดี', $handover->new_name);
        $this->assertSame($this->friend->id, $handover->previous_member_user_id);
        $this->assertSame('app', $handover->channel);

        $this->assertTrue(SmartNotification::where('user_id', $recipient->id)->where('type', 'seat_handover_received')->exists());
        $this->assertTrue(SmartNotification::where('user_id', $this->owner->id)->where('type', 'seat_handover_claimed')->exists());
        $this->assertTrue(SmartNotification::where('user_id', $this->friend->id)->where('type', 'seat_handover_removed')->exists());
    }

    public function test_handover_works_the_night_before_a_trip_with_no_departure_time(): void
    {
        // รอบไม่ได้ตั้งเวลารถออก — ส่งต่อได้ถึงสิ้นวันก่อนเดินทาง
        $summary = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers")
            ->assertOk();

        $this->assertTrue($summary->json('data.available'));
        $this->assertStringStartsWith('สิ้นวันที่', $summary->json('data.deadline_label'));
    }

    public function test_handover_closes_three_hours_before_departure(): void
    {
        $this->schedule->update([
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'departs_at' => now('Asia/Bangkok')->addHours(2)->format('Y-m-d H:i:s'),
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers", ['passenger_id' => $this->friendSeat->id])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'เลยเวลาส่งต่อที่นั่งแล้ว (ปิดก่อนรถออก 3 ชั่วโมง) ทักทีมงานในแชทได้เลยครับ']);

        $this->schedule->update(['departs_at' => now('Asia/Bangkok')->addHours(4)->format('Y-m-d H:i:s')]);

        $this->createLink($this->owner, $this->friendSeat);
        $link = SeatHandover::first();
        // ลิงก์หมดอายุพร้อมเส้นตาย (เวลาจริง) ไม่ใช่อีก 14 วัน
        $this->assertTrue($link->expires_at->lte(now()->addHour()->addMinute()));
        $this->assertTrue($link->expires_at->gte(now()->addMinutes(59)));
    }

    public function test_owner_handing_over_own_seat_transfers_the_booking(): void
    {
        $recipient = User::factory()->create();
        $token = $this->createLink($this->owner, $this->ownerSeat, ['transfers_ownership' => true]);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk()
            ->assertJsonPath('data.transfers_ownership', true);

        $booking = $this->booking->fresh();
        $this->assertSame($recipient->id, $booking->user_id);
        $this->assertFalse($booking->isAccessibleByUser($this->owner->id));
        // เพื่อนอีกคนในใบเดียวกันยังไปต่อ
        $this->assertTrue($booking->isAccessibleByUser($this->friend->id));
        $this->assertSame($this->owner->id, SeatHandover::first()->previous_owner_id);
        $this->assertDatabaseMissing('booking_members', ['booking_id' => $booking->id, 'user_id' => $recipient->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/handovers")
            ->assertNotFound();
    }

    public function test_transferring_the_booking_requires_full_payment(): void
    {
        $this->booking->update(['payment_type' => 'deposit', 'balance_paid_at' => null]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers", [
                'passenger_id' => $this->ownerSeat->id,
                'transfers_ownership' => true,
            ])
            ->assertUnprocessable();

        // ส่งต่อเฉพาะที่นั่งยังได้ — เจ้าของยังเป็นผู้ชำระเงิน
        $this->createLink($this->owner, $this->friendSeat);
    }

    public function test_only_one_open_ownership_link_at_a_time(): void
    {
        $this->createLink($this->owner, $this->ownerSeat, ['transfers_ownership' => true]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers", [
                'passenger_id' => $this->friendSeat->id,
                'transfers_ownership' => true,
            ])
            ->assertUnprocessable();
    }

    public function test_companion_can_hand_over_only_their_own_seat(): void
    {
        $this->actingAs($this->friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers", ['passenger_id' => $this->ownerSeat->id])
            ->assertUnprocessable();

        $this->actingAs($this->friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers", [
                'passenger_id' => $this->friendSeat->id,
                'transfers_ownership' => true,
            ])
            ->assertUnprocessable();

        $overview = $this->actingAs($this->friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers")
            ->assertOk();
        $this->assertCount(1, $overview->json('data.seats'));
        $this->assertTrue($overview->json('data.seats.0.is_mine'));

        $token = $this->createLink($this->friend, $this->friendSeat);
        $recipient = User::factory()->create();

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk();

        // เจ้าของได้รู้ว่าใครมาแทน
        $this->assertTrue(SmartNotification::where('user_id', $this->owner->id)
            ->where('type', 'seat_handover_claimed')->exists());
        $this->assertTrue(SmartNotification::where('user_id', $this->friend->id)
            ->where('type', 'seat_handover_claimed')->exists());
    }

    public function test_strangers_cannot_see_or_create_links(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers")
            ->assertNotFound();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers", ['passenger_id' => $this->friendSeat->id])
            ->assertNotFound();
    }

    public function test_new_link_for_same_seat_cancels_the_old_one(): void
    {
        $first = $this->createLink($this->owner, $this->friendSeat);
        $second = $this->createLink($this->owner, $this->friendSeat);

        $this->assertNotSame($first, $second);
        $this->assertSame(SeatHandover::STATUS_CANCELLED, SeatHandover::where('token', $first)->value('status'));

        $recipient = User::factory()->create();
        $this->actingAs($recipient, 'sanctum')
            ->getJson("/api/v1/seat-handovers/{$first}")
            ->assertOk()
            ->assertJsonPath('data.claimable', false)
            ->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$first}/claim", $this->claimPayload())
            ->assertUnprocessable();
    }

    public function test_cancelled_link_cannot_be_claimed(): void
    {
        $token = $this->createLink($this->owner, $this->friendSeat);
        $id = SeatHandover::where('token', $token)->value('id');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$this->booking->booking_ref}/handovers/{$id}")
            ->assertOk();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertUnprocessable();
    }

    public function test_expired_link_cannot_be_claimed(): void
    {
        $token = $this->createLink($this->owner, $this->friendSeat);
        SeatHandover::where('token', $token)->update(['expires_at' => now()->subMinute()]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/seat-handovers/{$token}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertUnprocessable();
    }

    public function test_a_link_is_used_once(): void
    {
        $token = $this->createLink($this->owner, $this->friendSeat);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload(['name' => 'ปิติ ยินดี']))
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'ที่นั่งนี้มีคนรับไปแล้ว']);

        $this->assertSame('มานะ ขยันดี', $this->friendSeat->fresh()->name);
    }

    public function test_people_already_on_the_booking_or_round_cannot_claim(): void
    {
        $token = $this->createLink($this->owner, $this->friendSeat);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertUnprocessable();

        // มีใบจองของตัวเองในรอบเดียวกันแล้ว
        $other = User::factory()->create();
        $this->makeBooking($other, $this->schedule, [], 'B');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'คุณมีที่นั่งในรอบนี้อยู่แล้ว รับเพิ่มอีกที่ไม่ได้']);
    }

    public function test_bookings_that_cannot_be_handed_over(): void
    {
        $ref = $this->booking->booking_ref;
        $try = fn () => $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$ref}/handovers", ['passenger_id' => $this->friendSeat->id]);

        $this->booking->update(['status' => 'pending']);
        $try()->assertUnprocessable();

        $this->booking->update(['status' => 'confirmed', 'checked_in' => true]);
        $try()->assertUnprocessable();

        $this->booking->update(['checked_in' => false, 'is_gift' => true, 'gift_code' => 'ABCDEFGH']);
        $try()->assertUnprocessable();

        $this->booking->update(['is_gift' => false, 'force_majeure_at' => now()]);
        $try()->assertUnprocessable();

        $this->booking->update(['force_majeure_at' => null]);
        $this->schedule->update(['transport_type' => 'flight']);
        $try()->assertUnprocessable();

        $this->schedule->update(['transport_type' => 'van']);
        $try()->assertCreated();
    }

    public function test_staff_can_issue_a_link_for_a_flight_round(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->schedule->update(['transport_type' => 'flight']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/bookings/{$this->booking->booking_ref}/handovers", [
                'passenger_id' => $this->friendSeat->id,
            ])
            ->assertCreated();

        $token = $response->json('data.token');
        $this->assertNotEmpty($response->json('data.url'));

        $recipient = User::factory()->create();
        $this->actingAs($recipient, 'sanctum')
            ->getJson("/api/v1/seat-handovers/{$token}")
            ->assertJsonPath('data.from_name', 'ทีมงานลุยเลเขา')
            ->assertJsonPath('data.claimable', true);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk();

        $history = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$this->booking->booking_ref}/handovers")
            ->assertOk();
        $this->assertSame($recipient->id, $history->json('data.history.0.claimed_by.id'));
        $this->assertSame('สมหญิง รักดี', $history->json('data.history.0.previous_name'));

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$this->booking->booking_ref}/handovers")
            ->assertForbidden();
    }

    public function test_claim_validates_the_traveller_like_booking_does(): void
    {
        $token = $this->createLink($this->owner, $this->friendSeat);
        $recipient = User::factory()->create();
        $url = "/api/v1/seat-handovers/{$token}/claim";

        $this->actingAs($recipient, 'sanctum')
            ->postJson($url, $this->claimPayload(['name' => 'Mana Kayandee']))
            ->assertJsonValidationErrors('name');

        $this->actingAs($recipient, 'sanctum')
            ->postJson($url, $this->claimPayload(['id_card' => '1101700230700']))
            ->assertJsonValidationErrors('id_card');

        $this->actingAs($recipient, 'sanctum')
            ->postJson($url, $this->claimPayload(['accept_terms' => false]))
            ->assertJsonValidationErrors('accept_terms');

        $this->actingAs($recipient, 'sanctum')
            ->postJson($url, $this->claimPayload(['terms_version' => '2020-01-01']))
            ->assertJsonValidationErrors('terms_version');

        $this->actingAs($recipient, 'sanctum')
            ->postJson($url, $this->claimPayload(['phone' => '081234']))
            ->assertJsonValidationErrors('phone');

        $this->assertSame(SeatHandover::STATUS_PENDING, SeatHandover::where('token', $token)->value('status'));
    }

    public function test_women_only_trip_rejects_male_title(): void
    {
        $this->schedule->trip->update(['is_women_only' => true]);
        $token = $this->createLink($this->owner, $this->friendSeat);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload(['title' => 'นาย']))
            ->assertJsonValidationErrors('title');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload(['title' => 'นางสาว', 'name' => 'มานี มีนา']))
            ->assertOk();
    }

    public function test_international_trip_requires_passport(): void
    {
        $this->schedule->trip->update(['destination_type' => 'international', 'country_code' => 'NP']);
        $token = $this->createLink($this->owner, $this->friendSeat);
        $url = "/api/v1/seat-handovers/{$token}/claim";
        $recipient = User::factory()->create();

        $this->actingAs($recipient, 'sanctum')
            ->postJson($url, $this->claimPayload())
            ->assertJsonValidationErrors(['name_en', 'passport_no', 'passport_expires_at']);

        $this->actingAs($recipient, 'sanctum')
            ->postJson($url, $this->claimPayload([
                'name_en' => 'MANA KAYANDEE',
                'passport_no' => 'AA1234567',
                'passport_expires_at' => now()->addMonths(2)->toDateString(),
            ]))
            ->assertJsonValidationErrors('passport_expires_at');

        $this->actingAs($recipient, 'sanctum')
            ->postJson($url, $this->claimPayload([
                'name_en' => 'MANA KAYANDEE',
                'passport_no' => 'AA1234567',
                'passport_expires_at' => now()->addYears(3)->toDateString(),
            ]))
            ->assertOk();

        $this->assertSame('AA1234567', $this->friendSeat->fresh()->passport_no);
    }

    public function test_old_traveller_documents_are_deleted(): void
    {
        $document = BookingDocument::create([
            'booking_id' => $this->booking->id,
            'booking_passenger_id' => $this->friendSeat->id,
            'requirement_key' => 'medical',
            'label' => 'ใบรับรองแพทย์',
            'file_path' => 'booking-documents/x.pdf',
            'original_name' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);
        $token = $this->createLink($this->owner, $this->friendSeat);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk();

        $this->assertModelMissing($document);
    }

    public function test_unpaid_split_share_follows_the_seat(): void
    {
        $share = BookingSplitShare::create([
            'booking_id' => $this->booking->id,
            'passenger_id' => $this->friendSeat->id,
            'member_id' => BookingMember::where('user_id', $this->friend->id)->value('id'),
            'amount' => 2000,
            'status' => BookingSplitShare::STATUS_PENDING,
            'pay_token' => 'tok-'.uniqid(),
        ]);
        $token = $this->createLink($this->owner, $this->friendSeat);
        $recipient = User::factory()->create();

        $this->actingAs($recipient, 'sanctum')
            ->getJson("/api/v1/seat-handovers/{$token}")
            ->assertJsonPath('data.pending_share_amount', 2000);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk();

        $newMemberId = BookingMember::where('user_id', $recipient->id)->value('id');
        $this->assertSame($newMemberId, $share->fresh()->member_id);
    }

    public function test_departing_traveller_loses_live_card_and_location_and_room_is_told(): void
    {
        $activity = LiveActivity::create([
            'user_id' => $this->friend->id,
            'booking_id' => $this->booking->id,
            'schedule_id' => $this->schedule->id,
            'platform' => 'ios',
            'push_token' => 'tok',
            'started_at' => now(),
        ]);
        TripMemberLocation::create([
            'schedule_id' => $this->schedule->id,
            'user_id' => $this->friend->id,
            'latitude' => 13.7,
            'longitude' => 100.5,
            'recorded_at' => now(),
        ]);
        // ห้องเคยประกาศใบจองนี้แล้ว — ข้อความต้องพูดว่า "แทน"
        ChatMessage::create([
            'schedule_id' => $this->schedule->id,
            'user_id' => null,
            'body' => 'เข้าร่วม',
            'sender_role' => 'system',
            'system_key' => "member_joined:{$this->booking->id}",
        ]);

        $token = $this->createLink($this->owner, $this->friendSeat);
        $recipient = User::factory()->create(['nickname' => 'มานะ']);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk();

        $this->assertNotNull($activity->fresh()->ended_at);
        $this->assertDatabaseMissing('trip_member_locations', ['user_id' => $this->friend->id]);

        $handoverId = SeatHandover::where('token', $token)->value('id');
        $message = ChatMessage::where('system_key', "seat_handover:{$handoverId}")->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('มานะ ที่มาร่วมทริปแทน', $message->body);
        $this->assertStringNotContainsString('สมหญิง', $message->body);
    }

    public function test_round_staff_and_admins_are_told_the_manifest_changed(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->schedule->staff()->attach($staff->id);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $token = $this->createLink($this->owner, $this->friendSeat);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/seat-handovers/{$token}/claim", $this->claimPayload())
            ->assertOk();

        foreach ([$staff, $admin] as $user) {
            $note = SmartNotification::where('user_id', $user->id)->where('type', 'seat_handover_staff')->first();
            $this->assertNotNull($note);
            $this->assertStringContainsString('สมหญิง รักดี → มานะ ขยันดี', $note->body);
            $this->assertStringContainsString('A2', $note->body);
        }
    }

    public function test_booking_resource_tells_who_can_hand_over(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$this->booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.seat_handover.available', true)
            ->assertJsonPath('data.seat_handover.open_count', 0);

        $this->createLink($this->owner, $this->friendSeat);

        $this->actingAs($this->friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$this->booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.seat_handover.available', true)
            ->assertJsonPath('data.seat_handover.open_count', 1);
    }

    public function test_unknown_token_is_404(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/seat-handovers/nope')
            ->assertNotFound();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/seat-handovers/nope/claim', $this->claimPayload())
            ->assertNotFound();
    }
}
