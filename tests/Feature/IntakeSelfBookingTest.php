<?php

namespace Tests\Feature;

use App\Jobs\ExpirePendingBookingsJob;
use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\BookingTermAcceptance;
use App\Models\CustomerIntake;
use App\Models\FcmToken;
use App\Models\IntakeLink;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\IntakeBookingService;
use App\Services\SlipOcrService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ลูกค้ากดจองและจ่ายเงินเองจากลิงก์กลุ่ม (/g/{token}) — ไม่ต้องรอทีมงานส่ง QR
 *
 * โจทย์จริง: กลุ่มที่กรอกครบแล้วต้องรอทีมงานเปิดใบจองและส่ง QR ในแชท ระหว่างรอ
 * ที่นั่งไม่ได้ถูกกันไว้ มีคนจองในแอปแล้วจ่ายตัดหน้าไป ลูกค้าที่รอก็ไม่ได้ไป
 */
class IntakeSelfBookingTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_ID = '1101700207251';

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('customer', 'web');
        Mail::fake();
        Storage::fake(MediaDisk::slipDisk());
    }

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'เขาหลวง สุโขทัย',
            'slug' => 'khao-luang-'.uniqid(),
            'type' => 'trekking',
            'location' => 'สุโขทัย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 3500,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
            'deposit_enabled' => true,
            'deposit_type' => 'amount',
            'deposit_amount' => 1000,
        ], $overrides));
    }

    private function makeLink(TripSchedule $schedule): IntakeLink
    {
        $link = new IntakeLink([
            'trip_schedule_id' => $schedule->id,
            'booking_type' => IntakeLink::TYPE_NORMAL,
            'label' => 'ไลน์ OA',
            'is_active' => true,
        ]);
        $link->token = IntakeLink::mintToken();
        $link->save();

        return $link;
    }

    private function person(array $overrides = []): array
    {
        return array_merge([
            'title' => 'นาย',
            'name' => 'สมชาย ใจดี',
            'nickname' => 'ชาย',
            'phone' => '081-234-5678',
            'email' => 'somchai@example.com',
            'id_card' => self::VALID_ID,
            'birth_date' => '1994-05-02',
            'blood_group' => '',
            'emergency_contact' => 'สมหญิง ใจดี · พี่สาว',
            'emergency_phone' => '089-999-8888',
            'allergies' => 'ไม่มี',
            'health_notes' => 'ไม่มี',
            'halal_food' => '0',
            'seat_id' => 'A1',
            'consent' => '1',
        ], $overrides);
    }

    /** กลุ่มสองคนที่กรอกครบ — คนเปิดกลุ่มนั่ง A1 เพื่อนนั่ง B1 */
    private function completeGroup(?TripSchedule $schedule = null): CustomerIntake
    {
        $link = $this->makeLink($schedule ?? $this->makeSchedule());
        $this->post("/r/{$link->token}", $this->person(['party_size' => 2]))->assertSessionHasNoErrors();
        $intake = CustomerIntake::firstOrFail();

        $this->post("/g/{$intake->token}", $this->person([
            'name' => 'มานี รักเรียน',
            'nickname' => 'มานี',
            'phone' => '0899999999',
            'email' => 'manee@example.com',
            'seat_id' => 'A2',
        ]))->assertSessionHasNoErrors();

        return $intake->fresh();
    }

    private function book(CustomerIntake $intake): TestResponse
    {
        return $this->post("/g/{$intake->token}/book", [
            'accepted_terms' => '1',
            'terms_version' => config('legal.terms_version'),
        ]);
    }

    private function bookSeat(TripSchedule $schedule, string $seatId): void
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 3500,
            'qr_code' => Booking::generateQrCode(),
        ]);

        BookingSeat::create([
            'booking_id' => $booking->id,
            'schedule_id' => $schedule->id,
            'seat_id' => $seatId,
            'passenger_name' => 'ลูกค้าที่จองเอง',
        ]);
    }

    public function test_a_complete_group_is_offered_to_book_and_pay_right_away(): void
    {
        $intake = $this->completeGroup();

        $this->get("/g/{$intake->token}")
            ->assertOk()
            ->assertSee('จองและชำระเงินได้เลย')
            ->assertSee('ยอดรวมโดยประมาณ')
            ->assertSee('฿7,000')
            ->assertSee('มีเวลาชำระเงิน '.Booking::PENDING_TTL_MINUTES.' นาที')
            ->assertSee('จ่ายเต็ม หรือมัดจำก่อนก็ได้');
    }

    public function test_booking_turns_the_whole_group_into_one_pending_booking_with_their_seats(): void
    {
        $intake = $this->completeGroup();

        $this->book($intake)->assertRedirect("/g/{$intake->token}/pay");

        $booking = Booking::with(['passengers', 'seats', 'user'])->firstOrFail();
        $this->assertSame('pending', $booking->status);
        $this->assertEquals(7000.0, (float) $booking->total_amount);
        $this->assertSame(['สมชาย ใจดี', 'มานี รักเรียน'], $booking->passengers->pluck('name')->all());
        $this->assertSame(['A1', 'A2'], $booking->seats->pluck('seat_id')->all());
        // ข้อมูลที่กรอกในลิงก์ไปถึงใบจองครบ ไม่ต้องให้ทีมงานพิมพ์ซ้ำ
        $this->assertSame(self::VALID_ID, $booking->passengers->first()->id_card);
        $this->assertSame('มานี', $booking->passengers->last()->nickname);

        // ใบจองอยู่ในบัญชีของคนเปิดกลุ่ม — บัญชีเงาที่มีอีเมลจริงของเขา
        $this->assertSame('somchai@example.com', $booking->user->email);
        $this->assertTrue($booking->user->is_shadow);

        // กลุ่มปิดรับ ผูกกับใบจอง และจำได้ว่าลูกค้าจองเอง
        $intake->refresh();
        $this->assertSame('booked', $intake->status);
        $this->assertSame($booking->id, $intake->booking_id);
        $this->assertTrue($intake->wasSelfBooked());
        $this->assertSame(1, $intake->self_booking_attempts);

        // หลักฐานการยอมรับเงื่อนไขเกิดพร้อมใบจอง ช่องทางเว็บ
        $acceptance = BookingTermAcceptance::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('web', $acceptance->channel);
    }

    public function test_booking_requires_accepting_the_terms(): void
    {
        $intake = $this->completeGroup();

        $this->post("/g/{$intake->token}/book", [])->assertSessionHasErrors('accepted_terms');

        $this->assertSame(0, Booking::count());
    }

    public function test_the_pay_page_offers_full_or_deposit_from_the_payment_quote(): void
    {
        $intake = $this->completeGroup();
        $this->book($intake);

        $this->get("/g/{$intake->token}/pay")
            ->assertOk()
            ->assertSee('ชำระเต็มจำนวน')
            ->assertSee('฿7,000')
            ->assertSee('จ่ายมัดจำก่อน')
            // มัดจำแบบบาทต่อคน 1,000 × 2 คน — มาจาก PaymentQuote ไม่ได้คิดเอง
            ->assertSee('฿2,000')
            ->assertSee('ส่วนที่เหลือ ฿5,000')
            ->assertSee('data-deadline', false)
            ->assertSee('data:image/svg+xml', false);
    }

    public function test_a_round_without_deposit_only_offers_full_payment(): void
    {
        $intake = $this->completeGroup($this->makeSchedule(['deposit_enabled' => false]));
        $this->book($intake);

        $this->get("/g/{$intake->token}/pay")
            ->assertOk()
            ->assertSee('ชำระเต็มจำนวน')
            ->assertDontSee('จ่ายมัดจำก่อน');
    }

    public function test_paying_in_full_confirms_the_seats_and_lands_on_the_app_page(): void
    {
        $intake = $this->completeGroup();
        $this->book($intake);

        $this->post("/g/{$intake->token}/pay", [
            'payment_type' => 'full',
            'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
        ])->assertRedirect("/g/{$intake->token}/pay");

        $booking = Booking::firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('full', $booking->payment_type);
        $this->assertEquals(7000.0, (float) $booking->paid_amount);

        $this->get("/g/{$intake->token}/pay")
            ->assertOk()
            ->assertSee('จองสำเร็จแล้ว')
            ->assertSee($booking->booking_ref)
            ->assertSee('ขั้นต่อไป: โหลดแอปลุยเลเขา')
            ->assertSee('ห้องแชทของรอบ')
            ->assertSee('เปิดใช้บัญชี')
            ->assertSee('5678');

        // ลิงก์เปิดบัญชีไปทาง SMS เท่านั้น ครั้งเดียว ไม่ว่าจะเปิดหน้านี้กี่รอบ
        $this->get("/g/{$intake->token}/pay")->assertOk();
        $claim = SmsLog::where('booking_id', $booking->id)->where('sms_type', 'account_claim')->get();
        $this->assertCount(1, $claim);
        $this->assertStringContainsString('ยืนยันการจอง', $claim->first()->message);

        // หน้ากลุ่มบอกเพื่อนที่เปิดลิงก์ทีหลังว่าจองแล้ว
        $this->get("/g/{$intake->token}")->assertOk()->assertSee('จองสำเร็จแล้ว');
    }

    public function test_the_done_page_never_shows_the_claim_link_itself(): void
    {
        $intake = $this->completeGroup();
        $this->book($intake);
        $this->post("/g/{$intake->token}/pay", [
            'payment_type' => 'full',
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ]);

        $owner = Booking::firstOrFail()->user->fresh();
        $this->assertNotNull($owner->claim_token);

        // ลิงก์กลุ่มอยู่ในแชทกลุ่ม — ใครถือลิงก์เปิดบัญชีคือยึดบัญชีได้
        $this->get("/g/{$intake->token}/pay")
            ->assertOk()
            ->assertDontSee($owner->claim_token)
            ->assertDontSee('somchai@example.com')
            ->assertDontSee('081-234-5678');
    }

    public function test_paying_the_deposit_records_a_deposit_booking(): void
    {
        $intake = $this->completeGroup();
        $this->book($intake);

        $this->post("/g/{$intake->token}/pay", [
            'payment_type' => 'deposit',
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertSessionHasNoErrors();

        $booking = Booking::firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('deposit', $booking->payment_type);
        $this->assertEquals(2000.0, (float) $booking->paid_amount);
        $this->assertEquals(5000.0, (float) $booking->balance_amount);

        $this->get("/g/{$intake->token}/pay")->assertOk()->assertSee('ยอดคงเหลือ')->assertSee('฿5,000');
    }

    public function test_customers_who_already_have_the_app_are_not_sold_the_app(): void
    {
        $intake = $this->completeGroup();
        $this->book($intake);
        $this->post("/g/{$intake->token}/pay", [
            'payment_type' => 'full',
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ]);

        FcmToken::create([
            'user_id' => Booking::firstOrFail()->user_id,
            'token' => 'device-'.uniqid(),
            'platform' => 'ios',
            'is_active' => true,
        ]);

        $this->get("/g/{$intake->token}/pay")
            ->assertOk()
            ->assertSee('เปิดแอปลุยเลเขาเพื่อดูการจองนี้')
            ->assertDontSee('ขั้นต่อไป: โหลดแอปลุยเลเขา');
    }

    public function test_an_unpaid_booking_releases_the_seats_and_the_group_can_book_again(): void
    {
        $intake = $this->completeGroup();
        $this->book($intake);
        $first = Booking::firstOrFail();

        $this->travel(Booking::PENDING_TTL_MINUTES + 1)->minutes();

        // หน้าชำระเงินไม่ปล่อยให้โอนเข้าใบที่หมดเวลาแล้ว แม้ระบบยังไม่ได้ยกเลิก
        $this->get("/g/{$intake->token}/pay")->assertOk()->assertSee('หมดเวลาชำระเงิน');

        (new ExpirePendingBookingsJob)->handle(app(BookingService::class));

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame(0, BookingSeat::where('booking_id', $first->id)->count());
        $this->assertSame('new', $intake->fresh()->status);

        $this->get("/g/{$intake->token}")->assertOk()->assertSee('จองและชำระเงินได้เลย');
        $this->book($intake->fresh())->assertRedirect("/g/{$intake->token}/pay");
        $this->assertSame(2, Booking::count());
        $this->assertSame(2, $intake->fresh()->self_booking_attempts);
    }

    public function test_a_group_that_keeps_booking_without_paying_is_stopped(): void
    {
        $intake = $this->completeGroup();
        $intake->forceFill(['self_booking_attempts' => IntakeBookingService::MAX_SELF_BOOKINGS])->save();

        $this->get("/g/{$intake->token}")
            ->assertOk()
            ->assertSee('ยังกดจองเองไม่ได้')
            ->assertSee('กรุณาทักทีมงานเพื่อจองต่อ');

        $this->book($intake)->assertRedirect("/g/{$intake->token}");
        $this->assertSame(0, Booking::count());
    }

    public function test_a_seat_taken_while_the_group_waited_blocks_booking_and_names_who_must_repick(): void
    {
        $schedule = $this->makeSchedule();
        $intake = $this->completeGroup($schedule);
        $this->bookSeat($schedule, 'A2');

        $this->get("/g/{$intake->token}")
            ->assertOk()
            ->assertSee('ที่นั่งของ มานี ถูกจองไปแล้ว');

        $this->book($intake);
        $this->assertSame(1, Booking::count()); // ใบของคนที่จองตัดหน้าไปเท่านั้น
        $this->assertSame('new', $intake->fresh()->status);
    }

    public function test_booking_twice_does_not_open_a_second_booking(): void
    {
        $intake = $this->completeGroup();

        $this->book($intake)->assertRedirect("/g/{$intake->token}/pay");
        $this->book($intake)->assertRedirect("/g/{$intake->token}");

        $this->assertSame(1, Booking::count());
    }

    public function test_a_group_without_a_round_cannot_book_itself(): void
    {
        $intake = $this->completeGroup();
        $intake->forceFill(['trip_schedule_id' => null])->save();

        $this->get("/g/{$intake->token}")->assertOk()->assertSee('ยังไม่ได้เลือกรอบเดินทาง');
        $this->book($intake);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_real_account_found_by_email_is_used_but_never_renamed(): void
    {
        $existing = User::factory()->create([
            'name' => 'Somchai Real',
            'email' => 'somchai@example.com',
            'phone' => '0800000000',
        ]);

        $intake = $this->completeGroup();
        $this->book($intake);

        $booking = Booking::firstOrFail();
        $this->assertSame($existing->id, $booking->user_id);

        // ฟอร์มสาธารณะ — ใครพิมพ์อีเมลคนอื่นได้ ต้องไม่เปลี่ยนชื่อ/เบอร์บัญชีจริงของเขา
        $existing->refresh();
        $this->assertSame('Somchai Real', $existing->name);
        $this->assertSame('0800000000', $existing->phone);
    }

    public function test_a_slip_whose_amount_does_not_match_is_held_for_the_team(): void
    {
        $intake = $this->completeGroup();
        $this->book($intake);

        // OCR อ่านได้ว่ายอดไม่ตรง — กติกา "กันกลาง" เดียวกับแอป
        $this->mock(SlipOcrService::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn(['reason' => 'amount_mismatch', 'raw' => ['amount' => 10]]);
        });

        $this->post("/g/{$intake->token}/pay", [
            'payment_type' => 'full',
            'slip_image' => UploadedFile::fake()->image('slip.jpg'),
        ]);

        $booking = Booking::firstOrFail();
        $this->assertSame('pending', $booking->status);
        $this->assertNotNull($booking->slip_ocr_status);

        $this->get("/g/{$intake->token}/pay")->assertOk()->assertSee('กำลังตรวจสอบยอดโอน');
    }

    public function test_an_admin_held_booking_pulled_from_the_group_can_be_paid_from_the_group_link(): void
    {
        $intake = $this->completeGroup();
        $schedule = $intake->schedule;

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'pending',
            'total_amount' => 7000,
            'qr_code' => Booking::generateQrCode(),
            'hold_until' => now()->addDays(2),
        ]);
        $intake->forceFill(['status' => 'booked', 'booking_id' => $booking->id])->save();

        $this->get("/g/{$intake->token}")
            ->assertOk()
            ->assertSee('รอชำระเงินเพื่อยืนยันที่นั่ง')
            ->assertSee('ไปหน้าชำระเงิน');

        $this->get("/g/{$intake->token}/pay")
            ->assertOk()
            ->assertSee('ทีมงานกันที่นั่งไว้ให้ถึง')
            ->assertSee('ชำระเต็มจำนวน');
    }
}
