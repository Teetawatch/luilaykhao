<?php

namespace Tests\Feature;

use App\Mail\BookingCreatedMail;
use App\Mail\TripBriefMail;
use App\Models\Booking;
use App\Models\FcmToken;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\AppLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ชวนลูกค้าที่จองผ่านแอดมิน/เว็บให้รู้จักแอป — โดยไม่ไปกวนคนที่มีแอปอยู่แล้ว
 *
 * กฎเดียวที่ทุกหน้าใช้ร่วมกัน: คนที่เคยเปิดแอป (มีโทเคนแจ้งเตือนที่ยังใช้งานอยู่)
 * ต้องไม่เห็นคำชวนที่ไหนเลย ไม่งั้นคำชวนครั้งที่สำคัญจริงจะหมดน้ำหนัก
 */
class AppInviteTest extends TestCase
{
    use RefreshDatabase;

    private function customer(bool $withApp): User
    {
        $user = User::factory()->create(['email' => 'som'.uniqid().'@example.com']);

        if ($withApp) {
            FcmToken::create([
                'user_id' => $user->id,
                'token' => 'tok-'.uniqid(),
                'platform' => 'android',
                'is_active' => true,
            ]);
        }

        return $user;
    }

    private function booking(User $user): Booking
    {
        $trip = Trip::create([
            'title' => 'เขาช้างเผือก', 'slug' => 'kcp-'.uniqid(), 'type' => 'trekking',
            'location' => 'กาญจนบุรี', 'difficulty' => 'hard', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3900, 'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(21)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1,
            'transport_type' => 'van', 'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3900,
            'paid_amount' => 3900,
        ]);
    }

    public function test_the_store_id_is_derived_from_the_store_url(): void
    {
        config(['app.apple_app_store_id' => null]);
        config(['app.mobile_ios_store_url' => 'https://apps.apple.com/th/app/luilaykhao/id6770391928?l=th']);

        $this->assertSame('6770391928', AppLinks::appleAppStoreId());
    }

    public function test_no_banner_tag_is_printed_when_the_id_cannot_be_read(): void
    {
        // ชี้ไปแอปผิดตัวแย่กว่าไม่มีแถบเลย
        config(['app.apple_app_store_id' => null]);
        config(['app.mobile_ios_store_url' => 'https://example.com/no-id-here']);

        $this->assertSame('', AppLinks::appleAppStoreId());
        $this->get('/')->assertDontSee('apple-itunes-app', false);
    }

    public function test_the_website_carries_the_smart_banner_and_store_links(): void
    {
        $response = $this->get('/');

        $response->assertSee('apple-itunes-app', false);
        $response->assertSee('app-id='.AppLinks::appleAppStoreId(), false);
        $response->assertSee('llk:app-ios-url', false);
        $response->assertSee('llk:app-android-url', false);
    }

    public function test_a_customer_without_the_app_is_invited_in_the_trip_brief(): void
    {
        $booking = $this->booking($this->customer(withApp: false));

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertSee('ห้องแชทของรอบนี้')
            ->assertSee(AppLinks::ios());
    }

    public function test_a_customer_who_already_opened_the_app_is_not_invited(): void
    {
        $booking = $this->booking($this->customer(withApp: true));

        $this->get('/t/'.$booking->ensureBriefToken())
            ->assertOk()
            ->assertDontSee('มีอะไรอยู่ในแอปอีก')
            ->assertDontSee('Google Play');
    }

    public function test_an_inactive_token_counts_as_not_having_the_app(): void
    {
        // ถอนแอปหรือปิดแจ้งเตือนไปแล้ว — ชวนใหม่ได้
        $user = $this->customer(withApp: true);
        $user->fcmTokens()->update(['is_active' => false]);

        $this->assertFalse(AppLinks::hasApp($user->fresh()));
    }

    public function test_the_booking_email_invites_only_customers_without_the_app(): void
    {
        $without = (new BookingCreatedMail($this->booking($this->customer(withApp: false))))->render();
        $with = (new BookingCreatedMail($this->booking($this->customer(withApp: true))))->render();

        $this->assertStringContainsString('เพื่อนร่วมทริปรอบนี้คุยกันอยู่ในแอป', $without);
        $this->assertStringNotContainsString('เพื่อนร่วมทริปรอบนี้คุยกันอยู่ในแอป', $with);
    }

    public function test_the_trip_brief_email_invites_only_customers_without_the_app(): void
    {
        $without = (new TripBriefMail($this->booking($this->customer(withApp: false))))->render();
        $with = (new TripBriefMail($this->booking($this->customer(withApp: true))))->render();

        $this->assertStringContainsString('มีอะไรอยู่ในแอปอีก', $without);
        $this->assertStringNotContainsString('มีอะไรอยู่ในแอปอีก', $with);
    }

    public function test_the_claim_done_page_hands_over_store_links(): void
    {
        // ก่อนหน้านี้หน้านี้บอกให้ "เข้าสู่ระบบในแอป" โดยไม่มีลิงก์ให้โหลดเลย
        $response = $this->withSession(['claim_done' => [
            'merged' => false, 'count' => 1, 'email' => 'som@example.com',
        ]])->get('/claim-done');

        $response->assertOk();
        $response->assertSee(AppLinks::ios());
        $response->assertSee(AppLinks::android());
    }

    public function test_the_me_endpoint_reports_whether_the_customer_has_the_app(): void
    {
        $withApp = $this->customer(withApp: true);
        $withoutApp = $this->customer(withApp: false);

        $this->actingAs($withApp)->getJson('/api/v1/auth/me')->assertJsonPath('data.has_app', true);
        $this->actingAs($withoutApp)->getJson('/api/v1/auth/me')->assertJsonPath('data.has_app', false);
    }
}
