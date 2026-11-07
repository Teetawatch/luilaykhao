<?php

namespace Tests\Feature;

use App\Http\Middleware\SetAppLocale;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Rules\ThaiName;
use App\Support\AppLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppLocaleTest extends TestCase
{
    use RefreshDatabase;

    /** ภาษาที่ request นี้ได้ หลังผ่าน SetAppLocale */
    private function localeFor(array $headers = []): string
    {
        $request = Request::create('/api/v1/trips', 'GET', server: collect($headers)
            ->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])
            ->all());

        return (new SetAppLocale)->handle($request, fn () => new Response(App::getLocale()))->getContent();
    }

    public function test_app_header_sets_the_request_locale(): void
    {
        $this->assertSame('en', $this->localeFor([AppLocale::HEADER => 'en']));
        $this->assertSame('en', $this->localeFor([AppLocale::HEADER => 'en-US']));
        $this->assertSame('th', $this->localeFor([AppLocale::HEADER => 'th']));
    }

    public function test_requests_without_the_header_stay_thai(): void
    {
        App::setLocale('en');

        $this->assertSame('th', $this->localeFor());
    }

    public function test_browser_accept_language_is_ignored(): void
    {
        // เว็บเรียก API ชุดเดียวกัน — เบราว์เซอร์ภาษาอังกฤษของคนไทยต้องไม่ทำให้
        // ข้อความกลายเป็นอังกฤษ
        $this->assertSame('th', $this->localeFor(['Accept-Language' => 'en-US,en;q=0.9']));
    }

    public function test_unknown_languages_fall_back_to_thai(): void
    {
        $this->assertSame('th', $this->localeFor([AppLocale::HEADER => 'zh']));
        $this->assertSame('th', $this->localeFor([AppLocale::HEADER => '']));
    }

    public function test_signed_in_user_language_is_remembered_for_pushes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me', [AppLocale::HEADER => 'en'])->assertOk();

        $this->assertSame('en', $user->fresh()->locale);
        $this->assertSame('en', AppLocale::forUser($user->fresh()));
    }

    public function test_requests_without_the_header_do_not_reset_the_saved_language(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_users_who_never_chose_get_thai(): void
    {
        $this->assertSame('th', AppLocale::forUser(User::factory()->create()));
        $this->assertSame('th', AppLocale::forUser(null));
    }

    public function test_with_user_switches_and_restores_the_locale(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        App::setLocale('th');

        $inside = AppLocale::withUser($user, fn () => App::getLocale());

        $this->assertSame('en', $inside);
        $this->assertSame('th', App::getLocale());
    }

    public function test_booking_validation_errors_come_back_in_english(): void
    {
        $response = $this->bookAs([
            'name' => 'John Smith',
            'nationality' => 'US',
            'id_card' => null,
            'phone' => '+14155550100',
        ], 'en');

        $response->assertStatus(422);
        $this->assertSame(
            'Please enter your passport number (used for travel insurance)',
            $response->json('errors')['passengers.0.passport_no'][0]
        );
        $this->assertDoesNotMatchRegularExpression('/[\x{0E00}-\x{0E7F}]/u', $response->json('message'));
    }

    public function test_thai_validation_errors_are_untouched(): void
    {
        $response = $this->bookAs(['name' => 'Somchai Jaidee'], null)->assertStatus(422);

        $this->assertSame(ThaiName::NOT_THAI, $response->json('errors')['passengers.0.name'][0]);
        $this->assertSame(ThaiName::NOT_THAI, $response->json('message'));
    }

    public function test_dynamic_messages_are_translated_with_their_values(): void
    {
        App::setLocale('en');
        $this->assertSame(
            'Seat A1, A2 has just been booked. Please choose another seat',
            __('ที่นั่ง :seats ถูกจองไปแล้ว กรุณาเลือกที่นั่งอื่น', ['seats' => 'A1, A2'])
        );

        App::setLocale('th');
        $this->assertSame(
            'ที่นั่ง A1, A2 ถูกจองไปแล้ว กรุณาเลือกที่นั่งอื่น',
            __('ที่นั่ง :seats ถูกจองไปแล้ว กรุณาเลือกที่นั่งอื่น', ['seats' => 'A1, A2'])
        );
    }

    public function test_every_translation_keeps_its_placeholders(): void
    {
        // คำแปลที่ทำ :placeholder หาย จะโชว์ค่าว่างหรือ ":seat" ดิบ ๆ ให้ลูกค้า
        $translations = json_decode(file_get_contents(lang_path('en.json')), true);

        foreach ($translations as $thai => $english) {
            preg_match_all('/:([a-z_]+)/', $thai, $expected);
            preg_match_all('/:([a-z_]+)/', $english, $actual);
            sort($expected[1]);
            sort($actual[1]);
            $this->assertSame($expected[1], $actual[1], $thai);
            $this->assertDoesNotMatchRegularExpression('/[\x{0E01}-\x{0E3A}\x{0E40}-\x{0E5B}]/u', $english, $thai);
        }
    }

    private function bookAs(array $passenger, ?string $language)
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Loei',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [array_merge([
                    'title' => 'นาย',
                    'nickname' => 'ชาย',
                    'id_card' => '1234567890123',
                    'phone' => '0810000000',
                    'blood_group' => 'A',
                    'halal_food' => false,
                    'emergency_contact' => 'แม่',
                    'emergency_phone' => '0820000000',
                ], $passenger)],
            ], $language ? [AppLocale::HEADER => $language] : []);
    }
}
