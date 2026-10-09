<?php

namespace Tests\Feature;

use App\Mail\BalancePaidMail;
use App\Mail\DepositPaidMail;
use App\Mail\PaymentConfirmedMail;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\Receipt;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
use App\Services\ReceiptService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function makeConfirmedBooking(float $total = 3000): Booking
    {
        $owner = User::factory()->create();
        $trip = Trip::create([
            'title' => 'ทริปเขาใหญ่', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'เขาใหญ่', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 10, 'price_per_person' => $total, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => $total,
            'paid_amount' => $total,
            'payment_type' => 'full',
            'payment_method' => 'promptpay',
            'payment_ref' => 'PAY-TEST',
            'paid_at' => now(),
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'สมชาย ใจดี', 'phone' => '0810000000',
        ]);

        return $booking;
    }

    public function test_receipt_is_issued_and_attached_on_payment_confirmed(): void
    {
        Mail::fake();
        $booking = $this->makeConfirmedBooking(3000);

        app(MailService::class)->sendPaymentConfirmedEmail($booking, 'full');

        $receipt = Receipt::where('booking_id', $booking->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('full', $receipt->kind);
        $this->assertEquals(3000.0, (float) $receipt->amount);
        $this->assertMatchesRegularExpression('/^RC-\d{6}-\d{4}$/', $receipt->receipt_no);
        $this->assertNotEmpty($receipt->snapshot);

        Mail::assertQueued(PaymentConfirmedMail::class, fn ($m) => $m->receipt?->id === $receipt->id);
    }

    public function test_issuance_is_idempotent_per_kind(): void
    {
        $booking = $this->makeConfirmedBooking(3000);
        $service = app(ReceiptService::class);

        $a = $service->issueForBooking($booking, 'full', 3000);
        $b = $service->issueForBooking($booking, 'full', 3000);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Receipt::where('booking_id', $booking->id)->count());
    }

    public function test_public_verify_page_renders_and_hides_unknown(): void
    {
        $booking = $this->makeConfirmedBooking(3000);
        $receipt = app(ReceiptService::class)->issueForBooking($booking, 'full', 3000);

        $this->get('/receipt/'.$receipt->verify_token)
            ->assertOk()
            ->assertSee($receipt->receipt_no)
            ->assertSee('ทริปเขาใหญ่');

        $this->get('/receipt/doesnotexist')->assertNotFound();
    }

    public function test_receipt_pdf_downloads_as_pdf(): void
    {
        $booking = $this->makeConfirmedBooking(3000);
        $receipt = app(ReceiptService::class)->issueForBooking($booking, 'full', 3000);

        $response = $this->get('/receipt/'.$receipt->verify_token.'/pdf');
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_app_lists_receipts_of_its_own_booking(): void
    {
        $booking = $this->makeConfirmedBooking(3000);
        $receipt = app(ReceiptService::class)->issueForBooking($booking, 'full', 3000);

        $response = $this->actingAs($booking->user, 'sanctum')
            ->getJson('/api/v1/bookings/'.$booking->booking_ref.'/receipts');

        $response->assertOk()
            ->assertJsonPath('data.0.receipt_no', $receipt->receipt_no)
            ->assertJsonPath('data.0.amount', 3000)
            ->assertJsonPath('data.0.kind_label', 'ชำระเต็มจำนวน');

        // ลิงก์ที่แอปเอาไปเปิดต้องเป็น URL เต็มของหน้าตรวจสอบ + PDF ของใบนั้น
        $this->assertStringEndsWith('/receipt/'.$receipt->verify_token, $response->json('data.0.verify_url'));
        $this->assertStringEndsWith('/receipt/'.$receipt->verify_token.'/pdf', $response->json('data.0.pdf_url'));
    }

    public function test_receipts_are_not_exposed_to_other_customers(): void
    {
        $booking = $this->makeConfirmedBooking(3000);
        app(ReceiptService::class)->issueForBooking($booking, 'full', 3000);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/bookings/'.$booking->booking_ref.'/receipts')
            ->assertForbidden();
    }

    public function test_booking_without_receipt_returns_an_empty_list(): void
    {
        $booking = $this->makeConfirmedBooking(3000);

        $this->actingAs($booking->user, 'sanctum')
            ->getJson('/api/v1/bookings/'.$booking->booking_ref.'/receipts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * จองคนเดียวไปกันสามคน — ผู้จองเป็นผู้เดินทางคนแรก เพื่อนสองคนกรอกอีเมลไว้
     *
     * @return array{0: Booking, 1: BookingPassenger, 2: BookingPassenger}
     */
    private function makeGroupBooking(float $total = 9000): array
    {
        $booking = $this->makeConfirmedBooking($total);
        $booking->user->update(['email' => 'owner@example.com']);
        $booking->passengers()->first()->update(['email' => 'owner@example.com']);

        $friendA = BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Ms.', 'name' => 'สมหญิง รักเที่ยว',
            'phone' => '0820000000', 'email' => 'Friend.A@example.com',
        ]);
        $friendB = BookingPassenger::create([
            'booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'สมศักดิ์ ขึ้นเขา',
            'phone' => '0830000000', 'email' => 'friend.b@example.com',
        ]);

        return [$booking->fresh(), $friendA, $friendB];
    }

    public function test_single_traveller_booking_gets_no_personal_receipts(): void
    {
        $booking = $this->makeConfirmedBooking(3000);
        $service = app(ReceiptService::class);

        $parent = $service->issueForBooking($booking, 'full', 3000);

        $this->assertCount(0, $service->issuePersonalReceipts($parent));
    }

    public function test_group_booking_gets_one_receipt_per_traveller_that_adds_up_to_the_whole(): void
    {
        [$booking, $friendA] = $this->makeGroupBooking(9000);
        $service = app(ReceiptService::class);

        $parent = $service->issueForBooking($booking, 'full', 9000);
        $personal = $service->issuePersonalReceipts($parent);

        $this->assertCount(3, $personal);
        $this->assertEquals(9000.0, $personal->sum(fn ($r) => (float) $r->amount));
        $this->assertSame([$parent->receipt_no.'-1', $parent->receipt_no.'-2', $parent->receipt_no.'-3'], $personal->pluck('receipt_no')->all());

        $second = $personal[1];
        $this->assertSame($friendA->id, $second->passenger_id);
        $this->assertSame('สมหญิง รักเที่ยว', data_get($second->snapshot, 'customer.name'));
        $this->assertEquals(3000.0, data_get($second->snapshot, 'summary.total'));
        $this->assertEquals(3000.0, data_get($second->snapshot, 'summary.paid'));
        $this->assertSame($parent->receipt_no, data_get($second->snapshot, 'personal.parent_receipt_no'));

        // เรียกซ้ำได้ชุดเดิม
        $this->assertSame($personal->pluck('id')->all(), $service->issuePersonalReceipts($parent)->pluck('id')->all());
        $this->assertSame(4, Receipt::where('booking_id', $booking->id)->count());
    }

    public function test_odd_amounts_split_to_the_satang_and_each_receipt_still_adds_up(): void
    {
        [$booking] = $this->makeGroupBooking(2501);
        $booking->update(['voucher_amount' => 500, 'paid_amount' => 2501]);
        $service = app(ReceiptService::class);

        $parent = $service->issueForBooking($booking->fresh(), 'full', 2501);
        $personal = $service->issuePersonalReceipts($parent);

        $this->assertEqualsWithDelta(2501.0, $personal->sum(fn ($r) => (float) $r->amount), 0.001);
        $this->assertEqualsWithDelta(3001.0, $personal->sum(fn ($r) => data_get($r->snapshot, 'summary.total')), 0.001);

        foreach ($personal as $r) {
            $s = $r->snapshot['summary'];
            // ใบเต็มจำนวน: บัตรของขวัญ + รับชำระ = ยอดสุทธิของคนนั้นพอดี
            $this->assertEqualsWithDelta($s['total'], $s['gift_voucher'] + $s['paid'], 0.001);
            $this->assertEqualsWithDelta($s['total'], $r->snapshot['items'][0]['amount'] - $s['discount'], 0.001);
        }
    }

    public function test_personal_receipts_do_not_disturb_the_monthly_numbering(): void
    {
        [$booking] = $this->makeGroupBooking(9000);
        $service = app(ReceiptService::class);

        $parent = $service->issueForBooking($booking, 'full', 9000);
        $service->issuePersonalReceipts($parent);

        $next = $service->issueForBooking($this->makeConfirmedBooking(1000), 'full', 1000);

        $seq = fn (string $no) => (int) substr($no, -4);
        $this->assertSame($seq($parent->receipt_no) + 1, $seq($next->receipt_no));
    }

    public function test_deposit_and_balance_each_get_their_own_personal_receipts(): void
    {
        [$booking] = $this->makeGroupBooking(9000);
        $service = app(ReceiptService::class);

        $deposit = $service->issueForBooking($booking, 'deposit', 3000);
        $balance = $service->issueForBooking($booking, 'balance', 6000);

        $this->assertCount(3, $service->issuePersonalReceipts($deposit));
        $this->assertCount(3, $service->issuePersonalReceipts($balance));
        $this->assertEquals(2000.0, (float) $balance->personalReceipts()->first()->amount);
    }

    public function test_split_payment_bookings_are_not_divided_evenly(): void
    {
        [$booking] = $this->makeGroupBooking(9000);
        $service = app(ReceiptService::class);

        $parent = $service->issueForBooking($booking, 'split', 3000);

        $this->assertCount(0, $service->issuePersonalReceipts($parent));
    }

    public function test_payer_gets_the_whole_receipt_and_friends_get_their_own(): void
    {
        Mail::fake();
        [$booking, $friendA] = $this->makeGroupBooking(9000);

        app(MailService::class)->sendPaymentConfirmedEmail($booking, 'full');

        $parent = Receipt::where('booking_id', $booking->id)->whereNull('parent_id')->firstOrFail();
        $mine = Receipt::where('passenger_id', $friendA->id)->firstOrFail();

        Mail::assertQueued(PaymentConfirmedMail::class, 3);
        Mail::assertQueued(PaymentConfirmedMail::class, fn ($m) => $m->hasTo('owner@example.com')
            && $m->receipt->id === $parent->id
            && $m->personalReceipts->count() === 3
            && $m->recipientName === null);
        Mail::assertQueued(PaymentConfirmedMail::class, fn ($m) => $m->hasTo('friend.a@example.com')
            && $m->receipt->id === $mine->id
            && $m->recipientName === 'สมหญิง รักเที่ยว');

        $payerHtml = (new PaymentConfirmedMail($booking, 'full', $parent, $parent->personalReceipts))->render();
        $this->assertStringContainsString('ใบเสร็จแยกรายคน', $payerHtml);
        $this->assertStringContainsString('สมศักดิ์ ขึ้นเขา', $payerHtml);
        $this->assertStringContainsString('/receipt/'.$mine->verify_token, $payerHtml);

        $friendMail = new PaymentConfirmedMail($booking, 'full', $mine, new Collection([$mine]), 'สมหญิง รักเที่ยว');
        $friendHtml = $friendMail->render();
        $this->assertStringContainsString('สวัสดีคุณ <strong>สมหญิง รักเที่ยว</strong>', $friendHtml);
        $this->assertStringContainsString('ในชื่อของคุณ', $friendHtml);
        $this->assertStringNotContainsString('ใบเสร็จแยกรายคน', $friendHtml);
        $this->assertCount(1, $friendMail->attachments());
    }

    public function test_deposit_and_balance_emails_carry_personal_receipts_too(): void
    {
        Mail::fake();
        [$booking, , $friendB] = $this->makeGroupBooking(9000);
        $booking->update(['payment_type' => 'deposit', 'deposit_amount' => 3000, 'paid_amount' => 3000, 'balance_amount' => 6000]);

        app(MailService::class)->sendDepositPaidEmail($booking->fresh());
        $booking->update(['paid_amount' => 9000]);
        app(MailService::class)->sendBalancePaidEmail($booking->fresh());

        Mail::assertQueued(DepositPaidMail::class, fn ($m) => $m->hasTo('friend.b@example.com')
            && $m->receipt->passenger_id === $friendB->id && (float) $m->receipt->amount === 1000.0);
        Mail::assertQueued(BalancePaidMail::class, fn ($m) => $m->hasTo('friend.b@example.com')
            && $m->receipt->passenger_id === $friendB->id && (float) $m->receipt->amount === 2000.0);
    }

    public function test_personal_receipt_verify_page_and_pdf_name_the_traveller(): void
    {
        [$booking] = $this->makeGroupBooking(9000);
        $service = app(ReceiptService::class);
        $parent = $service->issueForBooking($booking, 'full', 9000);
        $mine = $service->issuePersonalReceipts($parent)[2];

        $this->get('/receipt/'.$mine->verify_token)
            ->assertOk()
            ->assertSee('สมศักดิ์ ขึ้นเขา')
            ->assertSee('ใบเสร็จแยกรายบุคคล')
            ->assertSee($parent->receipt_no);

        $this->assertStringStartsWith('%PDF', $this->get('/receipt/'.$mine->verify_token.'/pdf')->getContent());
    }

    public function test_owner_sees_personal_receipts_nested_and_a_linked_friend_sees_only_their_own(): void
    {
        [$booking, $friendA] = $this->makeGroupBooking(9000);
        $service = app(ReceiptService::class);
        $parent = $service->issueForBooking($booking, 'full', 9000);
        $service->issuePersonalReceipts($parent);

        $this->actingAs($booking->user, 'sanctum')
            ->getJson('/api/v1/bookings/'.$booking->booking_ref.'/receipts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.receipt_no', $parent->receipt_no)
            ->assertJsonPath('data.0.is_personal', false)
            ->assertJsonCount(3, 'data.0.personal')
            ->assertJsonPath('data.0.personal.1.holder_name', 'สมหญิง รักเที่ยว');

        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id, 'user_id' => $friend->id, 'passenger_id' => $friendA->id,
            'role' => BookingMember::ROLE_COMPANION, 'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($friend, 'sanctum')
            ->getJson('/api/v1/bookings/'.$booking->booking_ref.'/receipts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.receipt_no', $parent->receipt_no.'-2')
            ->assertJsonPath('data.0.is_personal', true)
            ->assertJsonPath('data.0.amount', 3000)
            ->assertJsonPath('data.0.parent_receipt_no', $parent->receipt_no);
    }
}
