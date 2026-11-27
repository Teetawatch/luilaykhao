<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\PermitFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "เตรียมตัวก่อนเดินทาง" — GET bookings/{ref}/prep
 *
 * ล็อกไว้สามเรื่อง: (1) แต่ละรายการอ่านจากบริการเจ้าของเรื่อง จึงบอกตรงกับการ์ดในใบจอง
 * (2) รายการที่ไม่เกี่ยวกับใบนี้ไม่โผล่ (3) เพื่อนร่วมใบเห็นเฉพาะเรื่องของตัวเอง ไม่เห็นเงิน
 */
class TripPrepTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    public function test_a_paid_booking_with_nothing_else_to_do_is_ready(): void
    {
        $booking = $this->booking();

        $data = $this->prep($booking);

        $this->assertTrue($data['open']);
        $this->assertTrue($data['ready']);
        $this->assertSame([['key' => 'payment', 'status' => 'done']], $data['items']);
        $this->assertSame(1, $data['done']);
        $this->assertSame(1, $data['total']);
        $this->assertSame(0, $data['todo']);
    }

    public function test_an_unpaid_booking_asks_to_pay_and_a_sent_slip_waits(): void
    {
        $booking = $this->booking(attributes: ['status' => 'pending', 'paid_amount' => 0]);

        $item = $this->item($booking, 'payment');
        $this->assertSame('todo', $item['status']);
        $this->assertSame('pay', $item['kind']);
        $this->assertEquals(3000, $item['amount']);
        $this->assertFalse($this->prep($booking)['ready']);

        $booking->forceFill(['slip_ocr_status' => 'pending'])->saveQuietly();

        $item = $this->item($booking, 'payment');
        $this->assertSame('waiting', $item['status']);
        $this->assertSame('slip_review', $item['kind']);
        // รอทีมงานตรวจไม่ใช่เรื่องที่ลูกค้าต้องทำ — ไม่นับเป็นค้าง
        $this->assertTrue($this->prep($booking)['ready']);
    }

    public function test_a_deposit_balance_is_owed_with_its_due_date(): void
    {
        $booking = $this->booking(attributes: [
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 2000,
            'paid_amount' => 1000,
            'balance_due_at' => now()->addDays(5),
        ]);

        $item = $this->item($booking, 'payment');
        $this->assertSame('todo', $item['status']);
        $this->assertSame('balance', $item['kind']);
        $this->assertEquals(2000, $item['amount']);
        $this->assertNotNull($item['due_at']);
    }

    public function test_the_next_installment_is_owed(): void
    {
        $booking = $this->booking(attributes: ['payment_type' => 'installment', 'paid_amount' => 1000]);
        foreach ([[1, 'paid'], [2, 'pending'], [3, 'pending']] as [$no, $status]) {
            InstallmentPayment::create([
                'booking_id' => $booking->id,
                'installment_no' => $no,
                'amount' => 1000,
                'due_date' => now('Asia/Bangkok')->addDays($no * 3)->toDateString(),
                'status' => $status,
            ]);
        }

        $item = $this->item($booking, 'payment');
        $this->assertSame('installment', $item['kind']);
        $this->assertSame(2, $item['installment_no']);
        $this->assertEquals(1000, $item['amount']);
    }

    public function test_missing_required_documents_are_counted_per_slot(): void
    {
        Storage::fake('local');
        $booking = $this->booking(
            tripAttributes: ['document_requirements' => [
                ['key' => 'dive-cert', 'label' => 'บัตรดำน้ำ', 'required' => true],
                ['key' => 'insurance', 'label' => 'ประกัน', 'required' => false],
            ]],
            passengers: ['สมชาย ใจดี', 'สมหญิง ใจดี'],
        );

        $item = $this->item($booking, 'documents');
        $this->assertSame('todo', $item['status']);
        $this->assertSame(2, $item['missing']);
        $this->assertSame(2, $item['count']);

        foreach ($booking->passengers as $passenger) {
            BookingDocument::create([
                'booking_id' => $booking->id,
                'booking_passenger_id' => $passenger->id,
                'requirement_key' => 'dive-cert',
                'label' => 'บัตรดำน้ำ',
                'file_path' => 'booking-documents/x.jpg',
                'original_name' => 'x.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 100,
            ]);
        }

        $item = $this->item($booking, 'documents');
        $this->assertSame('done', $item['status']);
        $this->assertSame(0, $item['missing']);
    }

    public function test_optional_documents_alone_are_not_a_to_do(): void
    {
        $booking = $this->booking(tripAttributes: ['document_requirements' => [
            ['key' => 'insurance', 'label' => 'ประกัน', 'required' => false],
        ]]);

        $this->assertNull($this->item($booking, 'documents'));
    }

    public function test_an_international_trip_asks_for_passports(): void
    {
        $booking = $this->booking(tripAttributes: [
            'destination_type' => 'international',
            'country_code' => 'NP',
        ]);

        $item = $this->item($booking, 'passport');
        $this->assertSame('todo', $item['status']);
        $this->assertSame(1, $item['missing']);

        $booking->passengers->first()->forceFill([
            'passport_no' => 'AA1234567',
            'passport_expires_at' => now('Asia/Bangkok')->addYears(5)->toDateString(),
            'name_en' => 'SOMCHAI JAIDEE',
            'nationality' => 'TH',
            'birth_date' => '1990-01-01',
        ])->save();

        $this->assertSame('done', $this->item($booking, 'passport')['status']);
    }

    public function test_a_domestic_trip_never_asks_for_a_passport(): void
    {
        $this->assertNull($this->item($this->booking(), 'passport'));
    }

    public function test_attendance_is_asked_only_close_to_departure_for_groups(): void
    {
        $far = $this->booking(daysAhead: 10, passengers: ['ก ข', 'ค ง']);
        $this->assertNull($this->item($far, 'attendance'));

        $solo = $this->booking(daysAhead: 2);
        $this->assertNull($this->item($solo, 'attendance'));

        $near = $this->booking(daysAhead: 2, passengers: ['ก ข', 'ค ง']);
        $item = $this->item($near, 'attendance');
        $this->assertSame('todo', $item['status']);
        $this->assertSame(2, $item['count']);

        $near->forceFill(['attendance_confirmed_at' => now()])->saveQuietly();
        $this->assertSame('done', $this->item($near, 'attendance')['status']);
    }

    public function test_an_unsigned_park_form_is_a_to_do(): void
    {
        Storage::fake('local');
        $form = app(PermitFormService::class)->create(
            'เขาช้างเผือก',
            [UploadedFile::fake()->image('page.jpg', 420, 594)],
            [['width_mm' => 210, 'height_mm' => 297]],
        );
        $form->update(['fields' => [
            ['id' => 's', 'page' => 0, 'source' => 'signature', 'x' => 0.55, 'y' => 0.7, 'w' => 0.3, 'h' => 0.05, 'size' => 11, 'align' => 'center'],
        ]]);

        $booking = $this->booking(
            tripAttributes: ['permit_form_id' => $form->id],
            passengers: ['สมชาย ใจดี', 'สมหญิง ใจดี'],
        );

        $item = $this->item($booking, 'permit');
        $this->assertSame('todo', $item['status']);
        $this->assertSame(0, $item['signed']);
        $this->assertSame(2, $item['count']);
    }

    public function test_a_member_sees_only_their_own_documents_and_no_money(): void
    {
        Storage::fake('local');
        $booking = $this->booking(
            tripAttributes: ['document_requirements' => [
                ['key' => 'dive-cert', 'label' => 'บัตรดำน้ำ', 'required' => true],
            ], 'destination_type' => 'international', 'country_code' => 'NP'],
            passengers: ['สมชาย ใจดี', 'สมหญิง ใจดี'],
            attributes: ['status' => 'pending', 'paid_amount' => 0],
        );
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'passenger_id' => $booking->passengers->last()->id,
            'role' => 'member',
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $data = $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/prep")
            ->assertOk()
            ->json('data');

        $keys = array_column($data['items'], 'key');
        $this->assertSame(['documents'], $keys);
        $this->assertSame(1, $data['items'][0]['count']);
    }

    public function test_finished_cancelled_and_postponed_bookings_have_nothing_to_prepare(): void
    {
        foreach ([
            $this->booking(attributes: ['status' => 'cancelled']),
            $this->booking(daysAhead: -5),
            $this->booking(attributes: ['force_majeure_at' => now(), 'force_majeure_until' => now()->addDays(60)]),
        ] as $booking) {
            $data = $this->prep($booking);
            $this->assertFalse($data['open'], $booking->status);
            $this->assertSame([], $data['items']);
            $this->assertFalse($data['ready']);
        }
    }

    public function test_a_stranger_cannot_read_it(): void
    {
        $booking = $this->booking();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/prep")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/bookings/{$booking->booking_ref}/prep")->assertUnauthorized();
    }

    /** @return array<string, mixed> */
    private function prep(Booking $booking): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/prep")
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed>|null */
    private function item(Booking $booking, string $key): ?array
    {
        foreach ($this->prep($booking)['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tripAttributes
     * @param  list<string>  $passengers
     * @param  array<string, mixed>  $attributes
     */
    private function booking(
        int $daysAhead = 10,
        array $tripAttributes = [],
        array $passengers = ['สมชาย ใจดี'],
        array $attributes = [],
    ): Booking {
        $trip = Trip::create(array_merge([
            'title' => 'เขาช้างเผือก', 'slug' => 'prep-'.uniqid(), 'type' => 'trekking',
            'location' => 'กาญจนบุรี', 'difficulty' => 'hard', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ], $tripAttributes));

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($daysAhead)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($daysAhead + 1)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $booking = Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $this->owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'payment_type' => 'full',
        ], $attributes));

        foreach ($passengers as $name) {
            BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'นาย', 'name' => $name, 'phone' => '0812345678']);
        }

        return $booking->fresh('passengers');
    }
}
