<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\TripCountdownImageService;
use App\Services\TripStoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * การ์ดนับถอยหลังสาธารณะ /s/{token} — ปลายทางของลิงก์ที่ลูกค้าแชร์ลงฟีด
 */
class TripStoryCardTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeBooking(User $user, int $daysFromNow = 10, array $overrides = []): Booking
    {
        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'distance_km' => 20,
            'elevation_gain_m' => 1000,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $departure = now('Asia/Bangkok')->addDays($daysFromNow)->toDateString();

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create(array_merge([
            'booking_ref' => sprintf('LLK-20260101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ], $overrides));
    }

    // ── โทเคน ────────────────────────────────────────────────────────────────

    public function test_story_token_is_separate_from_the_live_tracking_token(): void
    {
        $booking = $this->makeBooking(User::factory()->create());

        $story = $booking->ensureStoryToken();
        $tracking = $booking->ensureShareToken();

        // ปนกันเมื่อไหร่ = ใครที่เห็นโพสต์สาธารณะเปิดดูตำแหน่ง GPS สดได้ทันที
        $this->assertNotSame($tracking, $story);
    }

    public function test_story_token_is_minted_once_and_reused(): void
    {
        $booking = $this->makeBooking(User::factory()->create());

        $this->assertSame($booking->ensureStoryToken(), $booking->fresh()->ensureStoryToken());
    }

    // ── หน้าสาธารณะ ──────────────────────────────────────────────────────────

    public function test_public_page_renders_the_countdown_and_og_tags(): void
    {
        $token = $this->makeBooking(User::factory()->create(), 10)->ensureStoryToken();

        $response = $this->get('/s/'.$token);

        $response->assertOk();
        $response->assertSee('ดอยหลวงเชียงดาว', false);
        $response->assertSee('อีก', false);
        $response->assertSee('10', false);
        $response->assertSee('og:image', false);
        $response->assertSee(route('trip.story.og', ['token' => $token]), false);
        // การ์ดของแต่ละคนไม่ควรถูกจัดเก็บลงดัชนี
        $response->assertSee('noindex', false);
    }

    public function test_public_page_never_leaks_anything_from_the_booking(): void
    {
        $user = User::factory()->create(['name' => 'สมชาย ใจดี', 'phone' => '0812345678']);
        $booking = $this->makeBooking($user);
        $token = $booking->ensureStoryToken();

        $response = $this->get('/s/'.$token);

        $response->assertOk();
        $response->assertDontSee($booking->booking_ref, false);
        $response->assertDontSee('สมชาย', false);
        $response->assertDontSee('0812345678', false);
        $response->assertDontSee($booking->ensureShareToken(), false);
    }

    public function test_cancelled_booking_retires_its_card(): void
    {
        $booking = $this->makeBooking(User::factory()->create());
        $token = $booking->ensureStoryToken();

        $booking->update(['status' => 'cancelled']);

        $this->get('/s/'.$token)->assertNotFound();
        $this->get('/s/'.$token.'/og.png')->assertNotFound();
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->get('/s/doesnotexist')->assertNotFound();
    }

    // ── ภาพ OG ───────────────────────────────────────────────────────────────

    public function test_og_image_is_a_1200x630_png(): void
    {
        $token = $this->makeBooking(User::factory()->create())->ensureStoryToken();

        $response = $this->get('/s/'.$token.'/og.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');

        $size = getimagesizefromstring($response->getContent());

        $this->assertSame(1200, $size[0]);
        $this->assertSame(630, $size[1]);
    }

    // ── ถ้อยคำ ───────────────────────────────────────────────────────────────

    /**
     * ถ้อยคำต้องตรงกับ StoryCountdown ในแอป (trip_story_card.dart) ไม่งั้นคนที่
     * เห็นการ์ดในแอปกับคนที่เห็นลิงก์พรีวิวจะอ่านได้คนละเรื่อง
     */
    public function test_countdown_wording_matches_the_app(): void
    {
        $this->assertSame(['12', 'วัน', 'อีก'], TripCountdownImageService::countdownParts(12));
        $this->assertSame(['พรุ่งนี้!', null, 'อีกไม่กี่ชั่วโมง'], TripCountdownImageService::countdownParts(1));
        $this->assertSame(['วันนี้!', null, 'ออกเดินทางแล้ว'], TripCountdownImageService::countdownParts(0));
        $this->assertSame(['กำลังลุย', null, 'ตอนนี้อยู่ที่'], TripCountdownImageService::countdownParts(-2));
        $this->assertSame(['เร็ว ๆ นี้', null, 'ทริปต่อไป'], TripCountdownImageService::countdownParts(null));
    }

    // ── การนับวัน ────────────────────────────────────────────────────────────

    #[DataProvider('dayOffsets')]
    public function test_days_left_counts_whole_thai_calendar_days(int $offset, string $expectedHeadline): void
    {
        $token = $this->makeBooking(User::factory()->create(), $offset)->ensureStoryToken();

        $card = app(TripStoryService::class)->forToken($token);

        $this->assertSame($offset, $card['days_left']);
        $this->assertSame($expectedHeadline, $card['headline']);
    }

    /**
     * departure_date เป็นคอลัมน์ date ที่ cast เป็น Carbon ตาม timezone ของแอป
     * (UTC) ส่วนวันนี้นับตามเวลาไทย ต่างกันอยู่ 7 ชั่วโมง — เศษนั้นเคยทำให้ทริป
     * ที่ออกไปเมื่อวานถูกนับเป็น 0 แล้วขึ้นว่า "วันนี้!" ทั้งที่รถออกไปแล้ว
     */
    public static function dayOffsets(): array
    {
        return [
            'เมื่อวาน' => [-1, 'กำลังลุย'],
            'สามวันก่อน' => [-3, 'กำลังลุย'],
            'วันนี้' => [0, 'วันนี้!'],
            'พรุ่งนี้' => [1, 'พรุ่งนี้!'],
            'สิบวัน' => [10, '10'],
            'ร้อยยี่สิบวัน' => [120, '120'],
        ];
    }

    // ── API ──────────────────────────────────────────────────────────────────

    public function test_owner_can_mint_a_story_link(): void
    {
        $user = User::factory()->create();
        $booking = $this->makeBooking($user);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bookings/'.$booking->booking_ref.'/story-link');

        $response->assertOk();
        $response->assertJsonPath('data.url', $booking->fresh()->storyUrl());
        $this->assertNotNull($booking->fresh()->story_token);
    }

    public function test_story_link_is_not_handed_to_someone_elses_booking(): void
    {
        $booking = $this->makeBooking(User::factory()->create());
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson('/api/v1/bookings/'.$booking->booking_ref.'/story-link')
            ->assertNotFound();
    }
}
