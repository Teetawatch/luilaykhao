<?php

namespace Tests\Feature;

use App\Mail\PaymentConfirmedMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Receipt;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\MailService;
use App\Services\ReceiptService;
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
}
