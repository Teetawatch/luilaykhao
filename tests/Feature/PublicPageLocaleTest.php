<?php

namespace Tests\Feature;

use App\Http\Middleware\SetPublicPageLocale;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าลิงก์ Blade ที่ส่งให้ลูกค้า (/pay/ /t/ /p/ /f/ /r/ /claim/) ใช้ภาษาตามที่ลูกค้าเลือก
 * — กดสลับเอง > คุกกี้ > ภาษาของเจ้าของการจอง > ไทย
 */
class PublicPageLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function booking(?string $locale = null): Booking
    {
        $user = User::factory()->create(['locale' => $locale]);
        $trip = Trip::create([
            'title' => 'Deposit Trip', 'slug' => 'deposit-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(2)->toDateString(),
            'return_date' => now()->addMonths(2)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(), 'user_id' => $user->id, 'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(), 'status' => 'confirmed', 'total_amount' => 3000,
            'paid_amount' => 1000, 'payment_type' => 'deposit', 'deposit_amount' => 1000,
            'balance_amount' => 2000, 'balance_due_at' => now()->addMonth()->toDateString(),
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'Passenger', 'phone' => '0812345678']);

        return $booking;
    }

    public function test_an_english_customers_pay_link_opens_in_english(): void
    {
        $token = $this->booking('en')->ensurePaymentToken();

        $this->get('/pay/'.$token)
            ->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('Confirm balance payment')
            ->assertDontSee('ยืนยันการชำระยอดส่วนที่เหลือ')
            ->assertSee('ภาษาไทย');
    }

    public function test_a_thai_customer_gets_thai_and_can_switch(): void
    {
        $token = $this->booking()->ensurePaymentToken();

        $this->get('/pay/'.$token)
            ->assertOk()
            ->assertSee('<html lang="th"', false)
            ->assertSee('ยืนยันการชำระยอดส่วนที่เหลือ')
            ->assertSee('English');

        $this->get('/pay/'.$token.'?lang=en')
            ->assertOk()
            ->assertSee('Confirm balance payment')
            ->assertCookie(SetPublicPageLocale::COOKIE, 'en');
    }

    public function test_the_remembered_choice_beats_the_owner_language(): void
    {
        $token = $this->booking('en')->ensurePaymentToken();

        $this->withCookie(SetPublicPageLocale::COOKIE, 'th')
            ->get('/pay/'.$token)
            ->assertSee('ยืนยันการชำระยอดส่วนที่เหลือ');
    }

    /** หน้าจ่ายเงินและใบเดินทางภาษาอังกฤษไม่เหลือข้อความไทย นอกจากปุ่ม "ภาษาไทย" และชื่อบัญชีรับโอน */
    public function test_english_pay_and_brief_pages_have_no_thai_left(): void
    {
        $booking = $this->booking('en');

        foreach (['/pay/'.$booking->ensurePaymentToken(), '/t/'.$booking->ensureBriefToken()] as $url) {
            $html = preg_replace('#<(style|script)\b.*?</\1>#s', '', $this->get($url)->assertOk()->getContent());
            $text = str_replace(['ภาษาไทย', (string) config('payment.bank_holder')], '', strip_tags(html_entity_decode($html)));
            preg_match_all('/[\x{0E01}-\x{0E3A}\x{0E40}-\x{0E5B}][^\n<]{0,40}/u', $text, $thai);

            $this->assertSame([], array_values(array_unique($thai[0])), $url);
        }
    }
}
