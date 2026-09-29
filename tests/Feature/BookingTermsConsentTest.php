<?php

namespace Tests\Feature;

use App\Mail\BookingCreatedMail;
use App\Models\Booking;
use App\Models\BookingTermAcceptance;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ReceiptService;
use App\Support\LegalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ใบจองต้องจำได้ว่าลูกค้ากดยอมรับเงื่อนไข "ฉบับไหน" ตอนไหน
 *
 * เวลามีข้อพิพาทเรื่องการยกเลิก คำถามแรกคือ "ตอนจองเห็นเงื่อนไขว่าอย่างไร" —
 * ถ้าไม่บันทึกเวอร์ชันไว้ พอแก้หน้า /terms ทีหลังก็ตอบไม่ได้อีกเลย
 */
class BookingTermsConsentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Terms Consent Trip',
            'slug' => 'terms-consent-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function passengers(): array
    {
        return [[
            'title' => 'นาย',
            'name' => 'ผู้เดินทาง หนึ่ง',
            'nickname' => 'หนึ่ง',
            'id_card' => '1234567890121',
            'phone' => '0812345678',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0898765432',
        ]];
    }

    public function test_accepting_terms_stamps_the_version_and_time(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(),
                'accepted_terms' => true,
            ]);

        $response->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertNotNull($booking->terms_accepted_at);
        $this->assertSame(config('legal.terms_version'), $booking->terms_version);
    }

    /**
     * ช่องทางที่ไม่มีหน้าจอให้กดยอมรับ (แอดมินจองแทน, แอปรุ่นก่อน) ต้องจองได้
     * เหมือนเดิม และต้องปล่อยช่องว่างไว้ตามความจริง — การประทับเวลาให้ทั้งที่
     * ไม่มีใครกด คือหลักฐานปลอมที่แย่กว่าไม่มีหลักฐาน
     */
    public function test_booking_without_the_flag_records_no_consent(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(),
            ]);

        $response->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertNull($booking->terms_accepted_at);
        $this->assertNull($booking->terms_version);
    }

    /** @param  array<string, mixed>  $extra */
    private function book(User $user, array $extra = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeaders(['User-Agent' => 'Luilaykhao-Test/1.0'])
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $this->makeSchedule()->id,
                'passengers' => $this->passengers(),
            ] + $extra);
    }

    /**
     * หลักฐานเต็ม: ข้อความทุกข้อที่แสดง ช่องทาง เครื่อง และบัญชีที่กด — สิ่งที่
     * ต้องยื่นได้เมื่อลูกค้าบอกว่า "ตอนจองไม่เห็นข้อนี้"
     */
    public function test_accepting_terms_records_the_full_evidence(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'ผู้จอง ทดสอบ', 'phone' => '0811111111']);

        $response = $this->book($user, [
            'accepted_terms' => true,
            'terms_version' => config('legal.terms_version'),
            'consent_channel' => 'app',
        ]);

        $response->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $proof = BookingTermAcceptance::where('booking_id', $booking->id)->firstOrFail();

        $this->assertSame($booking->booking_ref, $proof->booking_ref);
        $this->assertSame($user->id, $proof->user_id);
        $this->assertSame('ผู้จอง ทดสอบ', $proof->accepted_by_name);
        $this->assertSame($user->email, $proof->accepted_by_email);
        $this->assertSame('0811111111', $proof->accepted_by_phone);
        $this->assertSame(config('legal.terms_version'), $proof->terms_version);
        $this->assertSame(LegalPolicy::bookingTerms(), $proof->terms_lines);
        $this->assertSame('app', $proof->channel);
        $this->assertSame('Luilaykhao-Test/1.0', $proof->user_agent);
        $this->assertNotEmpty($proof->ip_address);
        $this->assertTrue($proof->isIntact());
        $this->assertSame($booking->terms_accepted_at->toIso8601String(), $proof->accepted_at->toIso8601String());
    }

    /**
     * แท็บที่เปิดค้างข้ามการ deploy เห็นเงื่อนไขฉบับเก่า — ถ้าประทับฉบับใหม่ลงไป
     * หลักฐานจะบอกว่าลูกค้ายอมรับข้อความที่ไม่เคยเห็น ต้องไม่ได้ใบจองเลย
     */
    public function test_a_stale_terms_version_is_rejected_without_creating_a_booking(): void
    {
        Mail::fake();

        $response = $this->book(User::factory()->create(), [
            'accepted_terms' => true,
            'terms_version' => '2020-01-01',
            'consent_channel' => 'web',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('เงื่อนไขการจองเพิ่งปรับปรุง', $response->json('message'));
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, BookingTermAcceptance::count());
    }

    /** ไคลเอนต์รุ่นก่อนที่ส่งแค่ accepted_terms ยังจองได้และได้หลักฐานเหมือนเดิม */
    public function test_older_clients_without_version_or_channel_still_get_evidence(): void
    {
        Mail::fake();

        $response = $this->book(User::factory()->create(), [
            'accepted_terms' => true,
            'consent_channel' => 'fax-machine',
        ]);

        $response->assertCreated();

        $proof = BookingTermAcceptance::firstOrFail();
        $this->assertSame(config('legal.terms_version'), $proof->terms_version);
        $this->assertSame(BookingTermAcceptance::CHANNEL_UNKNOWN, $proof->channel);
    }

    public function test_no_evidence_row_is_written_without_the_flag(): void
    {
        Mail::fake();

        $this->book(User::factory()->create(), [
            'terms_version' => config('legal.terms_version'),
            'consent_channel' => 'app',
        ])->assertCreated();

        $this->assertSame(0, BookingTermAcceptance::count());
    }

    /** หลักฐานที่แก้ทีหลังได้ไม่ใช่หลักฐาน */
    public function test_the_evidence_cannot_be_edited(): void
    {
        Mail::fake();

        $this->book(User::factory()->create(), ['accepted_terms' => true, 'consent_channel' => 'web'])
            ->assertCreated();

        $proof = BookingTermAcceptance::firstOrFail();

        $this->expectException(\LogicException::class);
        $proof->update(['terms_lines' => ['ข้อความที่ถูกแก้']]);
    }

    public function test_tampered_text_no_longer_matches_its_fingerprint(): void
    {
        Mail::fake();

        $this->book(User::factory()->create(), ['accepted_terms' => true, 'consent_channel' => 'web'])
            ->assertCreated();

        // แก้ตรงที่ฐานข้อมูล ข้ามโมเดล — แบบที่ใครสักคนอาจทำ
        \DB::table('booking_term_acceptances')->update(['terms_lines' => json_encode(['คืนเงินเต็มจำนวนทุกกรณี'])]);

        $this->assertFalse(BookingTermAcceptance::firstOrFail()->isIntact());
    }

    /**
     * ลูกค้าอ่านย้อนได้ว่าตกลงอะไรไว้ แต่ IP/เครื่อง/ลายนิ้วมือเป็นของทีมงาน
     */
    public function test_customers_see_the_terms_they_accepted_but_not_the_device_details(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $ref = $this->book($user, ['accepted_terms' => true, 'consent_channel' => 'liff'])
            ->assertCreated()
            ->json('data.booking_ref');

        $terms = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$ref}")
            ->assertOk()
            ->json('data.terms_acceptance');

        $this->assertSame('recorded', $terms['status']);
        $this->assertSame(LegalPolicy::bookingTerms(), $terms['lines']);
        $this->assertSame('LINE', $terms['channel_label']);
        $this->assertArrayNotHasKey('ip_address', $terms);
        $this->assertArrayNotHasKey('user_agent', $terms);
        $this->assertArrayNotHasKey('hash', $terms);
    }

    public function test_admins_see_the_full_evidence(): void
    {
        Mail::fake();

        $ref = $this->book(User::factory()->create(), ['accepted_terms' => true, 'consent_channel' => 'web'])
            ->assertCreated()
            ->json('data.booking_ref');

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $terms = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/bookings/{$ref}")
            ->assertOk()
            ->json('data.terms_acceptance');

        $this->assertSame('recorded', $terms['status']);
        $this->assertSame('Luilaykhao-Test/1.0', $terms['user_agent']);
        $this->assertNotEmpty($terms['ip_address']);
        $this->assertTrue($terms['intact']);
        $this->assertSame(64, strlen($terms['hash']));
    }

    /**
     * ใบจองก่อนมีตารางหลักฐาน มีแค่เวลาและเลขฉบับ — ข้อความต้องดึงจากคลังได้
     * ไม่ใช่เอาเงื่อนไขฉบับปัจจุบันมาแสดงแทน
     */
    public function test_older_bookings_show_the_archived_text_of_their_version(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $ref = $this->book($user)->assertCreated()->json('data.booking_ref');

        Booking::where('booking_ref', $ref)->update([
            'terms_accepted_at' => now()->subDays(5),
            'terms_version' => '2026-09-10',
        ]);

        $terms = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$ref}")
            ->json('data.terms_acceptance');

        $this->assertSame('version_only', $terms['status']);
        $this->assertSame(LegalPolicy::archivedBookingTerms('2026-09-10'), $terms['lines']);
        $this->assertNotSame(LegalPolicy::bookingTerms(), $terms['lines']);
    }

    public function test_no_consent_is_reported_as_none(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $ref = $this->book($user)->assertCreated()->json('data.booking_ref');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$ref}")
            ->assertJsonPath('data.terms_acceptance.status', 'none');
    }

    /**
     * อีเมลในกล่องของลูกค้าเองคือหลักฐานที่เถียงยากที่สุด — ต้องทวนข้อความ
     * ที่กดยอมรับจริงทุกข้อ และห้ามบอกว่า "คุณได้ยอมรับไว้" กับใบที่ไม่ได้กด
     */
    public function test_the_booking_email_repeats_the_exact_accepted_terms(): void
    {
        Mail::fake();

        $ref = $this->book(User::factory()->create(), ['accepted_terms' => true, 'consent_channel' => 'app'])
            ->assertCreated()
            ->json('data.booking_ref');

        $html = (new BookingCreatedMail(Booking::where('booking_ref', $ref)->firstOrFail()))->render();

        $this->assertStringContainsString('เงื่อนไขที่คุณได้ยอมรับไว้', $html);
        foreach (LegalPolicy::bookingTerms() as $line) {
            $this->assertStringContainsString(e($line), $html);
        }
        $this->assertStringContainsString('ผ่านแอปพลิเคชัน', $html);
        // ตัวเลขที่เคยพิมพ์ผิดไว้ในอีเมล (45 วัน ขณะที่เงื่อนไขจริงคือ 30)
        $this->assertStringNotContainsString('45 วัน', $html);
    }

    public function test_the_booking_email_does_not_claim_consent_that_was_never_given(): void
    {
        Mail::fake();

        $ref = $this->book(User::factory()->create())->assertCreated()->json('data.booking_ref');

        $html = (new BookingCreatedMail(Booking::where('booking_ref', $ref)->firstOrFail()))->render();

        $this->assertStringNotContainsString('เงื่อนไขที่คุณได้ยอมรับไว้', $html);
        $this->assertStringContainsString('เงื่อนไขการจอง', $html);
    }

    /** ใบเสร็จอ้างถึงเงื่อนไขที่ผู้ซื้อตกลงไว้ — ใบที่ไม่ได้กดยอมรับไม่มีบรรทัดนี้ */
    public function test_the_receipt_references_the_accepted_terms(): void
    {
        Mail::fake();

        $ref = $this->book(User::factory()->create(), ['accepted_terms' => true, 'consent_channel' => 'web'])
            ->assertCreated()
            ->json('data.booking_ref');
        $withConsent = Booking::where('booking_ref', $ref)->firstOrFail();

        $receipt = app(ReceiptService::class)->issueForBooking($withConsent, 'full', 1500);
        $this->assertNotEmpty(data_get($receipt->snapshot, 'terms.version'));
        $this->assertNotEmpty(data_get($receipt->snapshot, 'terms.accepted_at'));
        $this->assertStringContainsString(
            'ผู้ซื้อยอมรับเงื่อนไขการจองฉบับวันที่',
            view('receipts.pdf', ['receipt' => $receipt, 'd' => $receipt->snapshot, 'kindLabel' => 'ชำระเต็มจำนวน', 'qr' => '', 'verifyUrl' => '', 'fontRegular' => '', 'fontBold' => '', 'fontSemibold' => ''])->render(),
        );

        $withoutRef = $this->book(User::factory()->create())->assertCreated()->json('data.booking_ref');
        $without = app(ReceiptService::class)->issueForBooking(
            Booking::where('booking_ref', $withoutRef)->firstOrFail(),
            'full',
            1500,
        );
        $this->assertNull(data_get($without->snapshot, 'terms'));
    }
}
