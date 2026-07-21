<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function makeCompletedBooking(User $user, array $tripOverrides = []): Booking
    {
        $trip = Trip::create(array_merge([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'distance_km' => 20,
            'elevation_gain_m' => 1000,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $tripOverrides));

        $departure = now('Asia/Bangkok')->subDays(5)->toDateString();

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => $departure,
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => sprintf('LLK-20200101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'completed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
        ]);
    }

    private function publishedUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'name' => 'Somchai',
            'public_handle' => 'somchai',
            'public_profile_enabled' => true,
        ], $overrides));

        $this->makeCompletedBooking($user);

        return $user;
    }

    public function test_public_profile_page_renders_stats_and_og_tags(): void
    {
        $this->publishedUser(['public_bio' => 'สายเดินป่าตัวจริง']);

        $response = $this->get('/u/somchai');

        $response->assertOk()
            ->assertSee('สายเดินป่าตัวจริง')
            ->assertSee('ทริปที่เดินจบ')
            // การ์ด OG ต้องชี้มาที่รูปที่เราวาดเอง ไม่ใช่โลโก้ทั่วไป
            ->assertSee(url('/u/somchai/og.png'))
            ->assertSee('og:image', false)
            ->assertSee('index, follow', false);
    }

    public function test_disabled_profile_is_not_public(): void
    {
        $this->publishedUser(['public_profile_enabled' => false]);

        $this->get('/u/somchai')
            ->assertNotFound()
            ->assertSee('ไม่พบโปรไฟล์นี้')
            ->assertSee('noindex', false);

        $this->get('/u/somchai/og.png')->assertNotFound();
    }

    public function test_unknown_handle_looks_the_same_as_a_disabled_one(): void
    {
        $this->get('/u/nobodyhere')
            ->assertNotFound()
            ->assertSee('ไม่พบโปรไฟล์นี้');
    }

    public function test_og_image_route_returns_a_png(): void
    {
        $this->publishedUser();

        $response = $this->get('/u/somchai/og.png');

        $response->assertOk()->assertHeader('Content-Type', 'image/png');

        // ตรวจ magic bytes เพื่อยืนยันว่าเป็น PNG จริง ไม่ใช่แค่ header ที่ตั้งไว้
        $this->assertStringStartsWith("\x89PNG", $response->getContent());
    }

    public function test_only_approved_review_photos_are_shown(): void
    {
        $user = $this->publishedUser();

        // หนึ่งรีวิวต่อหนึ่งการจอง (unique key) จึงต้องแยกทริปกันคนละใบ
        foreach ([['approved.jpg', true], ['pending.jpg', false]] as [$image, $approved]) {
            $booking = $this->makeCompletedBooking($user);

            Review::create([
                'user_id' => $user->id,
                'booking_id' => $booking->id,
                'trip_id' => $booking->schedule->trip_id,
                'rating' => 5,
                'comment' => 'รีวิว',
                'images' => ['reviews/'.$image],
                'is_approved' => $approved,
            ]);
        }

        $this->get('/u/somchai')
            ->assertOk()
            ->assertSee('approved.jpg')
            ->assertDontSee('pending.jpg');
    }

    public function test_owner_can_enable_profile_and_gets_a_stable_handle(): void
    {
        $user = User::factory()->create(['name' => 'Nok Trekker', 'nickname' => null]);

        $first = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/me/public-profile', ['enabled' => true, 'bio' => 'ชอบภูเขา'])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.bio', 'ชอบภูเขา')
            ->json('data.handle');

        $this->assertNotEmpty($first);

        // ปิดแล้วเปิดใหม่ต้องได้ handle เดิม ลิงก์ที่แชร์ไปแล้วจึงไม่ตาย
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/me/public-profile', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.handle', $first);

        $this->assertSame(url('/u/'.$first), $user->fresh()->publicProfileUrl());
    }

    public function test_public_profile_settings_require_auth(): void
    {
        $this->getJson('/api/v1/me/public-profile')->assertUnauthorized();
        $this->putJson('/api/v1/me/public-profile', ['enabled' => true])->assertUnauthorized();
    }
}
