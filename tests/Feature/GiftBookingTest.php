<?php

namespace Tests\Feature;

use App\Mail\GiftClaimedMail;
use App\Mail\GiftPurchasedMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GiftBookingTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $tripOverrides = []): TripSchedule
    {
        $trip = Trip::create(array_merge([
            'title' => 'Gift Trip',
            'slug' => 'gift-trip',
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2000,
            'status' => 'active',
        ], $tripOverrides));

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makeGiftBooking(User $buyer, TripSchedule $schedule, array $overrides = []): Booking
    {
        $booking = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $buyer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2000,
            'paid_amount' => 2000,
            'is_gift' => true,
            'gift_code' => Booking::generateGiftCode(),
            'gift_from_name' => 'พี่หมี',
            'gift_message' => 'สุขสันต์วันเกิดนะ',
        ], $overrides));

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'น้องมายด์',
        ]);

        return $booking;
    }

    public function test_can_create_gift_booking_with_minimal_passenger_info(): void
    {
        $buyer = User::factory()->create();
        $schedule = $this->makeSchedule();

        $response = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $schedule->id,
            'passengers' => [['name' => 'น้องมายด์']],
            'is_gift' => true,
            'gift_from_name' => 'พี่หมี',
            'gift_message' => 'ขอให้เที่ยวให้สนุกนะ',
        ]);

        $response->assertCreated();
        $this->assertTrue($response->json('data.is_gift'));
        $this->assertSame('พี่หมี', $response->json('data.gift.from_name'));
        // ผู้ซื้อเป็นผู้ให้ — ต้องเห็นโค้ดของขวัญ
        $this->assertNotEmpty($response->json('data.gift.code'));
        $this->assertFalse($response->json('data.gift.claimed'));

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->first();
        $this->assertSame(8, strlen($booking->gift_code));
    }

    public function test_gift_booking_requires_from_name(): void
    {
        $buyer = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $schedule->id,
            'passengers' => [['name' => 'น้องมายด์']],
            'is_gift' => true,
        ])->assertUnprocessable();
    }

    public function test_non_gift_booking_still_requires_full_passenger_info(): void
    {
        $buyer = User::factory()->create();
        $schedule = $this->makeSchedule();

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $schedule->id,
            'passengers' => [['name' => 'คนเดินทาง']],
        ])->assertUnprocessable();
    }

    public function test_recipient_can_preview_gift_without_price(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        $response = $this->actingAs($recipient, 'sanctum')
            ->getJson("/api/v1/gifts/{$booking->gift_code}")
            ->assertOk();

        $this->assertSame('พี่หมี', $response->json('data.from_name'));
        $this->assertSame('Gift Trip', $response->json('data.trip.title'));
        $this->assertTrue($response->json('data.claimable'));
        $this->assertFalse($response->json('data.viewer_is_giver'));
        // ห้ามหลุดราคาหรือเลขที่จองไปให้ผู้รับ
        $this->assertArrayNotHasKey('total_amount', $response->json('data'));
        $this->assertArrayNotHasKey('booking_ref', $response->json('data'));
    }

    public function test_preview_unknown_code_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/gifts/NOTACODE')
            ->assertNotFound();
    }

    public function test_recipient_can_claim_gift_and_ownership_transfers(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'มายด์ ใจดี',
            'phone' => '0899999999',
            'blood_group' => 'O',
        ]);
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        $response = $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertOk();

        $booking->refresh();
        $this->assertSame($recipient->id, $booking->user_id);
        $this->assertSame($buyer->id, $booking->gifted_by_user_id);
        $this->assertNotNull($booking->gift_claimed_at);

        // ข้อมูลผู้เดินทางคนแรกถูกเติมจากโปรไฟล์ผู้รับ
        $passenger = $booking->passengers()->first();
        $this->assertSame('มายด์ ใจดี', $passenger->name);
        $this->assertSame('0899999999', $passenger->phone);
        $this->assertSame('O', $passenger->blood_group);

        // การจองย้ายไปโผล่ในรายการของผู้รับ
        $refs = collect($this->actingAs($recipient, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->json('data'))->pluck('booking_ref');
        $this->assertTrue($refs->contains($booking->booking_ref));

        // และหายจากรายการของผู้ซื้อ
        $buyerRefs = collect($this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->json('data'))->pluck('booking_ref');
        $this->assertFalse($buyerRefs->contains($booking->booking_ref));

        $this->assertTrue($response->json('data.gift.claimed'));
    }

    public function test_cannot_claim_twice(): void
    {
        $buyer = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertOk();

        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertUnprocessable();

        $this->assertSame($first->id, $booking->fresh()->user_id);
    }

    public function test_buyer_cannot_claim_own_gift(): void
    {
        $buyer = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertUnprocessable();
    }

    public function test_cannot_claim_unpaid_gift(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule(), [
            'status' => 'pending',
            'paid_amount' => 0,
        ]);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertUnprocessable();
    }

    public function test_cannot_claim_deposit_gift_with_outstanding_balance(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule(), [
            'payment_type' => 'deposit',
            'deposit_amount' => 500,
            'balance_amount' => 1500,
            'balance_paid_at' => null,
        ]);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertUnprocessable();
    }

    public function test_cannot_claim_cancelled_gift(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule(), [
            'status' => 'cancelled',
        ]);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertUnprocessable();
    }

    public function test_women_only_trip_blocks_male_recipient(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create(['title' => 'นาย']);
        $schedule = $this->makeSchedule(['is_women_only' => true, 'slug' => 'women-gift-trip']);
        $booking = $this->makeGiftBooking($buyer, $schedule);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertUnprocessable();
    }

    public function test_sent_gifts_list_shows_status_before_and_after_claim(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create(['name' => 'มายด์']);
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        $before = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/gifts/sent')->assertOk();
        $this->assertCount(1, $before->json('data'));
        $this->assertFalse($before->json('data.0.claimed'));
        $this->assertSame($booking->gift_code, $before->json('data.0.gift_code'));

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertOk();

        $after = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/gifts/sent')->assertOk();
        $this->assertCount(1, $after->json('data'));
        $this->assertTrue($after->json('data.0.claimed'));
        $this->assertSame('มายด์', $after->json('data.0.claimed_by_name'));

        // ผู้รับไม่ใช่ผู้ให้ — รายการที่ส่งต้องว่าง
        $recipientList = $this->actingAs($recipient, 'sanctum')->getJson('/api/v1/gifts/sent')->assertOk();
        $this->assertCount(0, $recipientList->json('data'));
    }

    public function test_gift_code_hidden_from_non_giver_on_booking_detail(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        // คนอื่นที่รู้เลขที่จอง (เช่น เพื่อนที่ถูกแชร์ลิงก์) ต้องไม่เห็นโค้ด
        $response = $this->actingAs($recipient, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk();

        $this->assertNull($response->json('data.gift.code'));
        $this->assertNull($response->json('data.gift.share_url'));
        $this->assertFalse($response->json('data.gift.viewer_is_giver'));

        $asBuyer = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk();

        $this->assertSame($booking->gift_code, $asBuyer->json('data.gift.code'));
        // ลิงก์แชร์เว็บต้องมีให้ผู้ให้เท่านั้น
        $this->assertStringContainsString(
            '/gift/'.$booking->gift_code,
            $asBuyer->json('data.gift.share_url'),
        );
        $this->assertTrue($asBuyer->json('data.gift.viewer_is_giver'));
    }

    public function test_public_gift_page_renders_without_price(): void
    {
        $buyer = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        $response = $this->get("/gift/{$booking->gift_code}")->assertOk();
        $response->assertSee('Gift Trip');
        $response->assertSee('พี่หมี');
        $response->assertSee('luilaykhao://gift/'.$booking->gift_code, false);
        // ราคาต้องไม่โผล่บนหน้าเปิดของขวัญสาธารณะ
        $response->assertDontSee('2,000');
    }

    public function test_public_gift_page_returns_404_for_unknown_code(): void
    {
        $this->get('/gift/NOTACODE')->assertNotFound();
    }

    public function test_public_gift_page_shows_claimed_state(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertOk();

        $this->get("/gift/{$booking->gift_code}")
            ->assertOk()
            ->assertSee('ถูกเปิดรับเรียบร้อยแล้ว');
    }

    public function test_gift_purchased_email_queued_on_creation(): void
    {
        Mail::fake();

        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $schedule = $this->makeSchedule();

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $schedule->id,
            'passengers' => [['name' => 'น้องมายด์']],
            'is_gift' => true,
            'gift_from_name' => 'พี่หมี',
        ])->assertCreated();

        Mail::assertQueued(GiftPurchasedMail::class);
    }

    public function test_gift_claimed_email_queued_to_giver(): void
    {
        Mail::fake();

        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $recipient = User::factory()->create();
        $booking = $this->makeGiftBooking($buyer, $this->makeSchedule());

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/gifts/{$booking->gift_code}/claim")
            ->assertOk();

        Mail::assertQueued(GiftClaimedMail::class);
    }
}
